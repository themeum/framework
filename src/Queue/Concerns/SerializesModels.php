<?php
/**
 * Opt-in trait that stores a queued job's models as their identity and re-fetches them when the
 * job runs, Laravel style. Without it a model on a job property is frozen with every attribute
 * and relation it had at dispatch. Only direct property values are converted, and unsaved models
 * are left as they are because there is no row to fetch them back from. Properties are written
 * under PHP's own mangled keys, so payloads stay readable if a job adds or drops this trait.
 *
 * @package    Framework
 * @subpackage Queue\Concerns
 * @since      3.2.1
 */
namespace Framework\Queue\Concerns;

defined('ABSPATH') || exit;

use Framework\Collections\Collection as BaseCollection;
use Framework\Database\Query\Collection;
use Framework\Database\Query\Model;
use Framework\Exceptions\ModelNotFoundException;
use Framework\Exceptions\QueueException;
use Framework\Queue\ModelIdentifier;
use LogicException;
use ReflectionClass;
use ReflectionProperty;

trait SerializesModels
{
    /**
     * Prepare the job's properties for serialization, replacing saved models with identifiers.
     *
     * @return array
     *
     * @since 3.2.1
     */
    public function __serialize(): array
    {
        $values = [];

        foreach ($this->get_serializable_properties() as $key => $property) {
            if (!$property->isInitialized($this)) {
                continue;
            }

            $values[$key] = $this->get_serialized_property_value($property->getValue($this));
        }

        return $values;
    }

    /**
     * Restore the job's properties, re-fetching the models behind any identifiers.
     *
     * Keys that no longer match a property are ignored, so a payload queued before a property
     * was removed from the job class still restores.
     *
     * @param array $values The serialized property values.
     *
     * @return void
     *
     * @throws \Framework\Exceptions\ModelNotFoundException When a stored model no longer exists.
     * @throws \Framework\Exceptions\QueueException When an identifier names a class that is not a model.
     *
     * @since 3.2.1
     */
    public function __unserialize(array $values): void
    {
        $properties = $this->get_serializable_properties();

        foreach ($values as $key => $value) {
            if (isset($properties[$key])) {
                $properties[$key]->setValue($this, $this->get_restored_property_value($value));
            }
        }
    }

    /**
     * Get the job's non-static properties, including a parent's private ones, keyed as PHP mangles them.
     *
     * @return \ReflectionProperty[]
     *
     * @since 3.2.1
     */
    protected function get_serializable_properties()
    {
        $properties = [];
        $reflection = new ReflectionClass($this);

        do {
            foreach ($reflection->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $reflection->getName()) {
                    continue;
                }

                $property->setAccessible(true);
                $properties[$this->get_mangled_property_name($property)] = $property;
            }
        } while ($reflection = $reflection->getParentClass());

        return $properties;
    }

    /**
     * Get the key PHP's native serialize() uses for a property.
     *
     * @param \ReflectionProperty $property The property.
     *
     * @return string
     *
     * @since 3.2.1
     */
    protected function get_mangled_property_name(ReflectionProperty $property)
    {
        if ($property->isPrivate()) {
            return "\0" . $property->getDeclaringClass()->getName() . "\0" . $property->getName();
        }

        if ($property->isProtected()) {
            return "\0*\0" . $property->getName();
        }

        return $property->getName();
    }

    /**
     * Get the value to store for a property.
     *
     * @param mixed $value The property value.
     *
     * @return mixed
     *
     * @throws \LogicException When a collection holds saved models of more than one class.
     *
     * @since 3.2.1
     */
    protected function get_serialized_property_value($value)
    {
        if ($value instanceof Collection) {
            return $this->get_serialized_collection($value);
        }

        if ($value instanceof Model && $this->is_saved_model($value)) {
            return new ModelIdentifier(
                get_class($value),
                $value->get_primary_key_value(),
                $this->get_queueable_relations($value)
            );
        }

        return $value;
    }

    /**
     * Get the value to store for a model collection.
     *
     * The collection is kept as it is unless every item is a saved model.
     *
     * @param \Framework\Database\Query\Collection $collection The collection.
     *
     * @return \Framework\Database\Query\Collection|\Framework\Queue\ModelIdentifier
     *
     * @throws \LogicException When the collection holds saved models of more than one class.
     *
     * @since 3.2.1
     */
    protected function get_serialized_collection(Collection $collection)
    {
        $items = array_values($collection->all());

        if ($items === []) {
            return $collection;
        }

        foreach ($items as $item) {
            if (!$item instanceof Model || !$this->is_saved_model($item)) {
                return $collection;
            }
        }

        $classes = array_values(array_unique(array_map('get_class', $items)));

        if (count($classes) > 1) {
            throw new LogicException('Queueing collections with multiple model types is not supported.');
        }

        return new ModelIdentifier(
            $classes[0],
            array_map(function (Model $model) {
                return $model->get_primary_key_value();
            }, $items),
            $this->get_collection_relations($collection),
            get_class($collection)
        );
    }

    /**
     * Determine whether a model has a row it can be fetched back from.
     *
     * The exists flag is protected and Model forwards unknown calls to the query builder, so it
     * is read through reflection.
     *
     * @param \Framework\Database\Query\Model $model The model.
     *
     * @return bool
     *
     * @since 3.2.1
     */
    protected function is_saved_model(Model $model)
    {
        static $exists;

        if ($exists === null) {
            $exists = new ReflectionProperty(Model::class, 'exists');
            $exists->setAccessible(true);
        }

        return $exists->getValue($model) === true && $model->get_primary_key_value() !== null;
    }

    /**
     * Get the relations loaded on a model, nested ones as dotted paths.
     *
     * @param \Framework\Database\Query\Model $model The model.
     *
     * @return array
     *
     * @since 3.2.1
     */
    protected function get_queueable_relations(Model $model)
    {
        $relations = [];

        foreach ($model->get_relations() as $name => $related) {
            $relations[] = $name;
            $nested = [];

            if ($related instanceof Model) {
                $nested = $this->get_queueable_relations($related);
            } elseif ($related instanceof BaseCollection) {
                $nested = $this->get_collection_relations($related);
            }

            foreach ($nested as $path) {
                $relations[] = $name . '.' . $path;
            }
        }

        return array_values(array_unique($relations));
    }

    /**
     * Get the relations loaded on every model in a collection.
     *
     * A path is kept only when all the models have it loaded, so restoring never loads a
     * relation onto models that did not have it.
     *
     * @param \Framework\Collections\Collection $collection The collection.
     *
     * @return array
     *
     * @since 3.2.1
     */
    protected function get_collection_relations(BaseCollection $collection)
    {
        $relations = null;

        foreach ($collection->all() as $item) {
            if (!$item instanceof Model) {
                return [];
            }

            $item_relations = $this->get_queueable_relations($item);
            $relations = $relations === null ? $item_relations : array_intersect($relations, $item_relations);
        }

        return array_values($relations ?? []);
    }

    /**
     * Get the value to restore onto a property.
     *
     * @param mixed $value The stored value.
     *
     * @return mixed
     *
     * @throws \Framework\Exceptions\ModelNotFoundException When the stored model no longer exists.
     * @throws \Framework\Exceptions\QueueException When the identifier names a class that is not a model.
     *
     * @since 3.2.1
     */
    protected function get_restored_property_value($value)
    {
        if (!$value instanceof ModelIdentifier) {
            return $value;
        }

        if (!is_string($value->class) || !is_subclass_of($value->class, Model::class)) {
            throw QueueException::invalid_payload(sprintf(
                'the stored model class [%s] is not a %s.',
                is_string($value->class) ? $value->class : gettype($value->class),
                Model::class
            ));
        }

        return $value->collection_class === null
            ? $this->restore_model($value)
            : $this->restore_collection($value);
    }

    /**
     * Re-fetch a single model and its relations.
     *
     * @param \Framework\Queue\ModelIdentifier $value The identifier.
     *
     * @return \Framework\Database\Query\Model
     *
     * @throws \Framework\Exceptions\ModelNotFoundException When the model no longer exists.
     *
     * @since 3.2.1
     */
    protected function restore_model(ModelIdentifier $value)
    {
        $class = $value->class;
        $model = $this->new_restoration_query($value)->find($value->id, (new $class())->get_primary_key());

        if ($model === null) {
            throw new ModelNotFoundException($value->class, $value->id);
        }

        return $model;
    }

    /**
     * Re-fetch a collection of models in its original order, dropping rows that no longer exist.
     *
     * @param \Framework\Queue\ModelIdentifier $value The identifier.
     *
     * @return \Framework\Database\Query\Collection
     *
     * @throws \Framework\Exceptions\QueueException When the collection class is not a model collection.
     *
     * @since 3.2.1
     */
    protected function restore_collection(ModelIdentifier $value)
    {
        $collection_class = $value->collection_class;

        if (
            !is_string($collection_class)
            || ($collection_class !== Collection::class && !is_subclass_of($collection_class, Collection::class))
        ) {
            throw QueueException::invalid_payload(sprintf(
                'the stored collection class [%s] is not a %s.',
                is_string($collection_class) ? $collection_class : gettype($collection_class),
                Collection::class
            ));
        }

        $class = $value->class;
        $ids = (array) $value->id;
        $models = $this->new_restoration_query($value)->where_in((new $class())->get_primary_key(), $ids)->get();

        $dictionary = [];

        foreach ($models as $model) {
            $dictionary[$model->get_primary_key_value()] = $model;
        }

        $ordered = [];

        foreach ($ids as $id) {
            if (isset($dictionary[$id])) {
                $ordered[] = $dictionary[$id];
            }
        }

        return new $collection_class($ordered);
    }

    /**
     * Start a query for the identifier's model class, eager loading its recorded relations.
     *
     * @param \Framework\Queue\ModelIdentifier $value The identifier.
     *
     * @return \Framework\Database\Query\QueryBuilder
     *
     * @since 3.2.1
     */
    protected function new_restoration_query(ModelIdentifier $value)
    {
        $class = $value->class;
        $query = $class::query();

        if (!empty($value->relations)) {
            $query->with($value->relations);
        }

        return $query;
    }
}
