<?php

namespace Framework\Managers;

use Framework\Collections\Collection;
use Framework\Supports\Arr;
use Framework\Wordpress\Models\Option;

use function Framework\collection;
use function Framework\with_prefix;
use function Framework\without_prefix;

class OptionManager
{
    /**
     * The cache of the options.
     *
     * @var array
     */
    protected static $cache = [];

    /**
     * Set the value of an option.
     *
     * Stores the given value in the WordPress options table using a namespaced option name.
     *
     * @param string $name The option key to set.
     * @param mixed $value The value to store for the option.
     * @param bool|null $autoload Whether to autoload the option.
     *
     * @return bool True if the value was updated, false otherwise.
     */
    public function set(string $name, $value, $autoload = null, $with_prefix = true)
    {
        $key = $this->prepare_option_name($name, $with_prefix);
        $result = update_option($key, $value, $autoload);
        $option = (object) [
            'option_name' => $key,
            'option_value' => $value,
        ];

        $this->update_cache($option);

        return $result;
    }

    /**
     * Retrieve the value of an option.
     *
     * Gets the value from the WordPress options table using a namespaced option name.
     * Returns the default value if the option does not exist.
     *
     * @param string|array $name The option key to retrieve.
     * @param mixed|null $default The default value to return if the option does not exist.
     *
     * @return mixed The value of the option or the default value.
     * 
     * @since 1.0.0
     */
    public function get($name, $default = null, $with_prefix = true)
    {
        $must_be_array = is_array($name);

        $names = $this->get_option_name($name, $with_prefix);
        $fresh_option_names = collection($names)->reject(function ($name) {
            return $this->is_cached($name);
        });
        $cached_option_names = collection($names)->accept(function ($name) {
            return $this->is_cached($name);
        });

        $options = $this->get_options_from_cache($cached_option_names);

        if (!$fresh_option_names->is_empty()) {
            $fresh_options = Option::query()->where_in('option_name', $fresh_option_names->all())
                ->get()->map(function ($value) {
                    return $this->value($value);
                });
            $this->sync_cache($fresh_options);
            $plucked = $fresh_options->pluck('option_value', 'option_name');
            $options = $options->merge($plucked);
        }

        if ($options->is_empty()) {
            if (!$must_be_array) {
                return $default;
            }

            return $this->refill_missing_keys_with_defaults([], $names, $default, $with_prefix);
        }

        if (!$must_be_array) {
            return $options->first() ?? $default;
        }

        // Fill with defaults for the missing keys
        $results =  $options->map(function($value, $key) use ($default) {
            if (is_array($default)) {
                return !is_null($value) ? $value : ($default[$key] ?? null);
            }

            return is_null($value) ? $default : $value;
        })->all();

        $results = $this->refill_missing_keys_with_defaults($results, $names, $default, $with_prefix);

        return $this->rebase_keys($results, $with_prefix);
    }

    /**
     * Get the options from the cache.
     *
     * @param Collection $options The options to get.
     * 
     * @return Collection The options from the cache.
     */
    protected function get_options_from_cache(Collection $options)
    {
        $data = [];

        foreach ($options as $option_name) {
            $data[$option_name] = static::$cache[$option_name];
        }

        return new Collection($data);
    }

    /**
     * Get the value of the option.
     *
     * @param mixed $value The value of the option.
     * 
     * @return mixed The value of the option.
     */
    protected function value($value)
    {
        return maybe_unserialize($value);
    }

    /**
     * Sync the cache with the options.
     *
     * @param Collection $options The options to sync.
     * 
     * @return void
     * 
     * @since 1.0.0
     */
    protected function sync_cache(Collection $options)
    {
        foreach ($options as $option) {
            $this->update_cache($option);
        }
    }

    /**
     * Update the cache with the option name and value.
     *
     * @param object $option The option object.
     * 
     * @return void
     * 
     * @since 1.0.0
     */
    protected function update_cache($option)
    {
        static::$cache[$option->option_name] = $option->option_value;
    }

    /**
     * Remove the option from the cache.
     *
     * @param string $option_name The name of the option.
     * 
     * @return void
     * 
     * @since 1.0.0
     */
    protected function remove_from_cache($option_name)
    {
        unset(static::$cache[$option_name]);
    }

    /**
     * Clear the cache.
     *
     * @return void
     * 
     * @since 1.0.0
     */
    protected function clear_cache()
    {
        static::$cache = [];
    }

    /**
     * Check if the option is cached.
     *
     * @param string $option_name The name of the option.
     * 
     * @return bool
     * 
     * @since 1.0.0
     */
    protected function is_cached($option_name)
    {
        return isset(static::$cache[$option_name]);
    }

    /**
     * Refill the missing keys with the defaults.
     *
     * @param array $results The results to refill.
     * @param array $names The names to refill.
     * @param mixed $default The default value to refill.
     * @param bool $with_prefix
     *
     * @return array The refilled results.
     */
    protected function refill_missing_keys_with_defaults(array $results, array $names, $default, $with_prefix)
    {
        foreach ($names as $key) {
            if (!isset($results[$key])) {
                $key_without_prefix = $with_prefix ? without_prefix($key) : $key;
                $results[$key] = is_array($default) ? ($default[$key_without_prefix] ?? null) : $default;
            }
        }

        return $results;
    }

    /**
     * Rebase the keys of the results.
     *
     * @param array $results The results to rebase.
     * @param bool $with_prefix
     * @return array The rebased results.
     */
    protected function rebase_keys(array $results, $with_prefix)
    {
        return collection($results)->map(function($value, $key) use ($with_prefix) {
            $key_without_prefix = $with_prefix ? without_prefix($key) : $key;
            return [$key_without_prefix => $value];
        })->collapse()->all();
    }

    /**
     * Delete an option.
     *
     * Removes the option from the WordPress options table using a namespaced option name.
     *
     * @param string $name The option key to delete.
     * @return bool True if the option was deleted, false otherwise.
     */
    public function delete(string $name, $with_prefix = true)
    {
        $result = delete_option($this->prepare_option_name($name, $with_prefix));
        $this->remove_from_cache($this->prepare_option_name($name, $with_prefix));

        return $result;
    }

    /**
     * Generate the full option name with namespace prefix.
     *
     * Prepends the app prefix to the given option key.
     *
     * @param string|array<string> $name The base option key.
     * @return array The namespaced option key.
     */
    protected function get_option_name($name, $with_prefix = true)
    {
        $name = Arr::wrap($name);

        return collection($name)->map(function ($name) use ($with_prefix) {
            return $this->prepare_option_name($name, $with_prefix);
        })->all();
    }

    /**
     * Prepare the option name.
     *
     * @param string $name The name of the option.
     * @param bool $with_prefix Whether to prefix the name.
     * 
     * @return string The prepared option name.
     */
    protected function prepare_option_name(string $name, bool $with_prefix = true)
    {
        return $with_prefix ? with_prefix($name) : $name;
    }
}
