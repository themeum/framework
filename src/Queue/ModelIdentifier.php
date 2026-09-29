<?php
/**
 * What SerializesModels stores in place of a model or a model collection on a queued job.
 * It holds only the identity needed to re-fetch the rows when the job runs, so the payload stays
 * small and handle() sees current data rather than a snapshot taken at dispatch.
 *
 * @package    Framework
 * @subpackage Queue
 * @since      3.2.1
 */
namespace Framework\Queue;

defined('ABSPATH') || exit;

class ModelIdentifier
{
    /**
     * The model class.
     *
     * @var string
     *
     * @since 3.2.1
     */
    public $class;

    /**
     * The primary key value, or the ordered list of keys for a collection.
     *
     * @var mixed
     *
     * @since 3.2.1
     */
    public $id;

    /**
     * The relations loaded at dispatch, nested ones as dotted paths.
     *
     * @var array
     *
     * @since 3.2.1
     */
    public $relations;

    /**
     * The collection class to restore into, or null for a single model.
     *
     * @var string|null
     *
     * @since 3.2.1
     */
    public $collection_class;

    /**
     * Create a new model identifier.
     *
     * @param string $class The model class.
     * @param mixed $id The primary key value, or the ordered list of keys.
     * @param array $relations The loaded relations.
     * @param string|null $collection_class The collection class, or null for a single model.
     *
     * @return void
     *
     * @since 3.2.1
     */
    public function __construct(string $class, $id, array $relations = [], ?string $collection_class = null)
    {
        $this->class = $class;
        $this->id = $id;
        $this->relations = $relations;
        $this->collection_class = $collection_class;
    }
}
