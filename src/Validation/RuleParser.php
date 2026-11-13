<?php
/**
 * Validation rule parser class.
 *
 * @package    Framework
 * @subpackage Validation
 * @since      1.0.0
 */

namespace Framework\Validation;

use Framework\Supports\Arr;
use Framework\Validation\Rules\ConditionalRule;

use function Framework\Polyfill\str_contains;

defined('ABSPATH') || exit;

class RuleParser
{
    /**
     * The data to parse.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected array $data;

    /**
     * The rules to parse.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected array $rules;

    /**
     * The rule key each concrete key was expanded from, so a wildcard entry is parsed once.
     *
     * @var array<string, string>
     *
     * @since 1.0.0
     */
    protected array $sources = [];

    /**
     * Create a new rule parser instance.
     *
     * @param array $data The data to parse.
     * @param array $rules The rules to parse.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(array $data, array $rules)
    {
        $this->data = $data;
        $this->rules = $rules;
    }

    /**
     * Parse the rules into rule instances.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function parse()
    {
        return $this->explode_wildcards()
            ->process_rules();
    }

    /**
     * Get the parsed and exploded rules.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function all()
    {
        return $this->rules();
    }

    /**
     * Expand the wildcard rule keys into concrete keys.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function explode_wildcards()
    {
        $exploded = [];
        $this->sources = [];

        foreach ($this->rules as $key => $rules) {
            if (str_contains($key, '*')) {
                $array_rules = $this->explode_array_rule($key, $rules);
                $exploded = array_merge($exploded, $array_rules);

                foreach (array_keys($array_rules) as $concrete_key) {
                    $this->sources[$concrete_key] = $key;
                }
            } else {
                $exploded[$key] = $rules;
                $this->sources[$key] = $key;
            }
        }

        $this->rules = $exploded;

        return $this;
    }

    /**
     * Build rule instances for every parsed rule definition.
     *
     * Every concrete key expanded from one wildcard entry would parse to the same result, so the
     * entry is parsed once: its first key takes the built instances and each later key takes
     * shallow clones. A shallow clone is a full copy because rule instances hold only scalars and
     * arrays, and the validator resets their per-key state before each check. A rule that gains an
     * object property which must not be shared should implement __clone().
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function process_rules()
    {
        $parsed = [];
        $parsed_by_source = [];

        foreach ($this->rules as $key => $rules) {
            $source = $this->sources[$key] ?? $key;

            if (isset($parsed_by_source[$source])) {
                $parsed[$key] = array_map(fn ($rule) => clone $rule, $parsed_by_source[$source]);
                continue;
            }

            $parsed[$key] = $parsed_by_source[$source] = RuleFactory::make(
                $this->spread(
                    $this->make_array($rules)
                )
            );
        }

        $this->rules = $parsed;

        return $this;
    }

    /**
     * Prepare the rules to an array.
     *
     * @param array|Rule|string $rules The rules to prepare.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function make_array($rules)
    {
        if (is_array($rules)) {
            return $rules;
        }

        if (is_string($rules)) {
            return explode('|', $rules);
        }

        return Arr::wrap($rules);
    }

    /**
     * Spread the rules.
     *
     * @param array $rules The rules to spread.
     *
     * @return array
     * 
     * @since 1.0.0
     */
    protected function spread(array $rules)
    {
        $spread = [];

        foreach ($rules as $rule) {
            if ($rule instanceof ConditionalRule) {
                if ($rule->passes($this->data)) {
                    $spread = array_merge($spread, $rule->rules());
                } else {
                    $spread = array_merge($spread, $rule->default_rules());
                }
            } else {
                $spread[] = $rule;
            }
        }

        return $spread;
    }

    /**
     * Get the rules.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function rules()
    {
        return $this->rules;
    }

    /**
     * Expand a wildcard rule key into concrete dot-notated keys using the parser data.
     *
     * @param string $key The wildcard rule key.
     * @param array<Rule> $rule The rule to apply to each expanded key.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function explode_array_rule(string $key, $rule)
    {
        $segments = explode('.', $key);

        return $this->explode_array_rule_segments($this->data, $segments, [], $rule);
    }

    /**
     * Recursively expand wildcard segments against a data branch.
     *
     * @param mixed $current_data The current data branch being traversed.
     * @param array $segments The remaining key segments to resolve.
     * @param array $path The resolved path segments collected so far.
     * @param array<Rule> $rule The rule to apply when the path is fully resolved.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function explode_array_rule_segments($current_data, array $segments, array $path, $rule)
    {
        if ($segments === []) {
            return [implode('.', $path) => $rule];
        }

        $segment = array_shift($segments);

        if ($segment === '*') {
            if (!is_iterable($current_data) || $current_data === []) {
                return [];
            }

            $exploded = [];

            foreach ($current_data as $index => $item) {
                $exploded = array_merge(
                    $exploded,
                    $this->explode_array_rule_segments(
                        $item,
                        $segments,
                        array_merge($path, [$index]),
                        $rule
                    )
                );
            }

            return $exploded;
        }

        if (!is_array($current_data) || !array_key_exists($segment, $current_data)) {
            return [];
        }

        return $this->explode_array_rule_segments(
            $current_data[$segment],
            $segments,
            array_merge($path, [$segment]),
            $rule
        );
    }
}
