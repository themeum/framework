<?php
/**
 * Validator class.
 *
 * @package    Framework
 * @subpackage Validation
 * @since      1.0.0
 */
namespace Framework\Validation;

use Closure;
use Framework\Exceptions\ValidationException;
use Framework\Supports\Arr;
use Framework\Validation\Rules\ArrayRule;
use Framework\Validation\Rules\ConditionalRule;
use Framework\Validation\Rules\NullableRule;
use Framework\Validation\Rules\SometimesRule;
use stdClass;

use function Framework\deep_get;
use function Framework\message;
use function Framework\throw_exception;

defined('ABSPATH') || exit;

class Validator
{
    /**
     * The data to validate.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected array $data = [];

    /**
     * The rules to validate.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected array $rules = [];

    /**
     * The raw rules to validate.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected array $raw_rules = [];

    /**
     * The custom validation messages.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected array $custom_messages = [];

    /**
     * The errors encountered during validation.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected array $errors = [];

    /**
     * The validation should stop on first failure.
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected bool $stop_on_first_failure = false;

    /**
     * The outcome of the last run, or null when the rules have not run against the current state.
     *
     * @var bool|null
     *
     * @since 1.0.0
     */
    protected ?bool $passed = null;

    /**
     * Create a new validator instance.
     *
     * @param array $data The data to validate.
     * @param array $rules The rules to validate.
     * @param array $messages The messages to validate.
     *
     * @return void
     * 
     * @since 1.0.0
     */
    public function __construct(array $data, array $rules, array $messages = [])
    {
        $this->data = $data;
        $this->raw_rules = $rules;
        $this->rules = $this->parse_rules($rules);
        $this->custom_messages = $messages;
    }

    /**
     * Create a new validator instance.
     *
     * @param array $data The data to validate.
     * @param array $rules The rules to validate.
     * @param array $messages The messages to validate.     *
     *
     * @return static
     * 
     * @since 1.0.0
     */
    public static function make(array $data, array $rules, array $messages = [])
    {
        return new static($data, $rules, $messages);
    }

    /**
     * Parse the raw rules into rule instances.
     *
     * @param array $rules The rules to parse.
     *
     * @return array
     * 
     * @since 1.0.0
     */
    protected function parse_rules(array $rules)
    {
        return (new RuleParser($this->data, $rules))->parse()->all();
    }

    /**
     * Get the custom messages.
     *
     * @return array
     * 
     * @since 1.0.0
     */
    public function get_custom_messages()
    {
        return $this->custom_messages;
    }

    /**
     * Get the value of an attribute.
     *
     * @param string $attribute The attribute to get the value of.
     *
     * @return mixed
     * 
     * @since 1.0.0
     */
    public function get_value($attribute)
    {
        return Arr::get($this->data, $attribute);
    }

    /**
     * Set the value of an attribute.
     *
     * Discards the stored outcome and errors, so the next check validates the changed data.
     *
     * @param string $attribute The attribute to set the value of.
     * @param mixed $value The value to set.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function set_value($attribute, $value)
    {
        Arr::set($this->data, $attribute, $value);
        $this->reset_outcome();
    }

    /**
     * Discard the stored outcome and errors so the next check runs the rules again.
     *
     * A subclass that writes $this->data or $this->rules directly must call this.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function reset_outcome()
    {
        $this->passed = null;
        $this->errors = [];
    }

    /**
     * Get the rules.
     *
     * @return array
     * 
     * @since 1.0.0
     */
    public function get_rules()
    {
        return $this->rules;
    }

    /**
     * Get the data.
     *
     * @return array
     * 
     * @since 1.0.0
     */
    public function get_data()
    {
        return $this->data;
    }

    /**
     * Validate the data.
     *
     * The rules run once; later calls return the stored outcome until set_value() or sometimes()
     * changes what is being checked.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function passes()
    {
        if ($this->passed !== null) {
            return $this->passed;
        }

        foreach ($this->rules as $key => $rules) {
            if ($this->stop_on_first_failure && !empty($this->errors())) {
                break;
            }

            $this->validate_rules($key, $rules);
        }

        return $this->passed = empty($this->errors());
    }

    /**
     * Apply the rules to the key.
     *
     * @param string $key The key to apply the rules to.
     * @param array<ValidationRule> $rules The rules to apply.
     *
     * @return void
     * 
     * @since 1.0.0
     */
    protected function validate_rules(string $key, array $rules)
    {
        $value = deep_get($this->data, $key);

        foreach ($rules as $rule) {
            // For the nullable rule, the the value is nullable
            // then we don't need to validate other rules.
            if ($rule instanceof NullableRule && ValidationRule::is_nullish($value)) {
                break;
            }

            // For the sometimes rule,
            // if the key does not exist in the validating data then skip validation.
            if ($rule instanceof SometimesRule && !array_key_exists($key, $this->data)) {
                break;
            }

            if ($rule instanceof Closure) {
                $this->apply_closure_rule($rule, $value, $key);
                continue;
            }

            $this->apply($rule, $value, $key);
        }
    }

    /**
     * Apply a closure to a value.
     *
     * @param Closure $closure The closure to apply.
     * @param mixed $value The value to apply the closure to.
     * @param string $key The key to apply the closure to.
     *
     * @return string|bool
     * 
     * @since 1.0.0
     */
    protected function apply_closure_rule(Closure $closure, $value, string $key)
    {
        $result = $closure($value, $key, $this->data);

        if (is_string($result)) {
            return $this->add_error($key, $result);
        }

        if ($result === false) {
            return $this->add_error($key, message('validator.invalid', [$key]));
        }

        return true;
    }

    /**
     * Apply a rule to a value.
     *
     * @param ValidationRule $rule The rule to apply.
     * @param mixed $value The value to apply the rule to.
     * @param string $key The key to apply the rule to.
     *
     * @return void
     * 
     * @since 1.0.0
     */
    protected function apply(ValidationRule $rule, $value, string $key)
    {
        $rule->with_data($this->data)
            ->with_name($key)
            ->with_value($value)
            ->clear_error_messages()
            ->sync_custom_messages($this->custom_messages)
            ->should_stop_on_first_failure($this->stop_on_first_failure);

        if (!$rule->passed()) {
            return $this->add_error($key, $rule->messages());
        }

        return true;
    }

    /**
     * Add an error to the errors array.
     *
     * @param string $key The key to add the error to.
     * @param array $messages The messages to add.
     *
     * @return bool
     * 
     * @since 1.0.0
     */
    protected function add_error(string $key, $messages)
    {
        $this->errors[$key] ??= [];
        $this->errors[$key] = array_merge($this->errors[$key], Arr::wrap($messages));

        return false;
    }

    /**
     * Check if the validation failed.
     *
     * Shares the stored outcome with passes(), so it does not run the rules a second time.
     *
     * @return bool
     * 
     * @since 1.0.0
     */
    public function fails()
    {
        return !$this->passes();
    }

    /**
     * Get the errors.
     *
     * @return array
     * 
     * @since 1.0.0
     */
    public function errors()
    {
        return $this->errors;
    }

    /**
     * Validate the data.
     *
     * @return array
     * 
     * @since 1.0.0
     */
    public function validate()
    {
        if ($this->fails()) {
            throw_exception(ValidationException::with_errors($this->errors()));
        }

        return $this->validated();
    }

    /**
     * Get the validated data.
     *
     * Runs the rules only if they have not run against the current state.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function validated()
    {
        if (!$this->passes()) {
            throw_exception(ValidationException::with_errors($this->errors()));
        }

        $results = [];
        $missing_value = new stdClass();

        foreach ($this->get_rules() as $attribute => $rules) {
            $value = deep_get($this->get_data(), $attribute, $missing_value);

            if (
                $this->has_array_rule($rules) &&
                $value !== null && 
                !empty(preg_grep('/^' . preg_quote($attribute, '/') . '\.+/', array_keys($this->get_rules())))
            ) {
                continue;
            }

            if ($value !== $missing_value) {
                Arr::set($results, $attribute, $value);
            }
        }

        return $results;
    }

    /**
     * Check if the rules have an array rule.
     *
     * @param array $rules The rules to check.
     *
     * @return bool
     * 
     * @since 1.0.0
     */
    protected function has_array_rule(array $rules)
    {
        return !empty(
            Arr::where($rules, function ($rule) {
                return $rule instanceof ArrayRule;
            })
        );
    }

    /**
     * Sometimes the rules should be applied.
     *
     * When the callback adds rules, the stored outcome and errors are discarded so the next check
     * runs the full rule set.
     *
     * @param string $key The key to apply the rules to.
     * @param array|string $rules The rules to apply.
     * @param callable $callback The callback to check if the rules should be applied.
     *
     * @return void
     * 
     * @since 1.0.0
     */
    public function sometimes(string $key, $rules, $callback)
    {
        if ($callback($this->data)) {
            $rules = [$key => $rules];
            $rules = $this->parse_rules($rules);
            $this->rules = array_merge($this->rules, $rules);
            $this->reset_outcome();
        }
    }
}
