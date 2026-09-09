<?php
/**
 * The messages bag class.
 *
 * @package    Framework
 * @subpackage Supports
 * @since      1.0.0
 */
namespace Framework\Supports;

use InvalidArgumentException;

use function Framework\app;
use function Framework\config;
use function Framework\throw_if;

defined('ABSPATH') || exit;

// phpcs:disable Generic.Files.LineLength.TooLong

class MessagesBag
{
    /**
     * The messages bag where all the app defined user faced messages are stored.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $messages = [];

    /**
     * Create a new messages bag instance.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        $this->messages = $this->messages();
    }

    /**
     * Get the default messages.
     * These values can be overridden by the app defined messages
     * at config/messages.php file.
     * Plugin developers can use translated message text there.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function defaults()
    {
        return [
            'validator' => [
                'failed' => 'Validation failed!',
                'invalid' => 'The %s field is invalid.',
                'invalid_rule' => 'There is no validation rule defined as %s.',
                'required' => [
                    'default' => 'The {name} field is required.',
                ],
                'required_if' => [
                    'default' => 'The {name} field is required.',
                ],
                'required_unless' => [
                    'default' => 'The {name} field is required.',
                ],
                'prohibited' => [
                    'default' => 'The {name} field is prohibited.',
                ],
                'prohibited_if' => [
                    'default' => 'The {name} field is prohibited.',
                ],
                'prohibited_unless' => [
                    'default' => 'The {name} field is prohibited.',
                ],
                'boolean' => [
                    'default' => 'The {name} field must be a boolean.',
                ],
                'object' => [
                    'default' => 'The {name} field must be an object.',
                ],
                'exists' => [
                    'default' => 'The selected {name} does not exist.',
                ],
                'unique' => [
                    'default' => 'The {name} has already been taken.',
                ],
                'in' => [
                    'default' => 'The {name} field must be in the list of "{args}".',
                ],
                'not_in' => [
                    'default' => 'The {name} field must not be in the list of "{args}".',
                ],
                'same_as' => [
                    'default' => 'The {name} field must match the {args} field.',
                ],
                'string' => [
                    'min' => 'The {name} field must be at least {min} characters long.',
                    'max' => 'The {name} field must be at most {max} characters long.',
                    'length' => 'The {name} field must be {length} characters long.',
                    'size' => 'The {name} field must be {size} characters long.',
                    'email' => 'The {name} field must be a valid email address.',
                    'url' => 'The {name} field must be a valid url.',
                    'regex' => 'The {name} field format is invalid.',
                    'ip' => 'The {name} field must be a valid ip address.',
                    'alpha' => 'The {name} field must only contain alphabetic characters.',
                    'alpha_num' => 'The {name} field must only contain alphabetic characters and numbers.',
                    'alpha_dash' => 'The {name} field must only contain alphabetic characters, dashes and underscores.',
                    'between' => 'The {name} field must be between {min} and {max} characters long.',
                    'starts_with' => 'The {name} field must start with {starts_with}.',
                    'ends_with' => 'The {name} field must end with {ends_with}.',
                    'doesnt_start_with' => 'The {name} field must not start with {doesnt_start_with}.',
                    'doesnt_end_with' => 'The {name} field must not end with {doesnt_end_with}.',
                    'exactly' => 'The {name} field must be exactly {exactly} characters long.',
                    'default' => 'The {name} field must be a string.',
                ],
                'array' => [
                    'default' => 'The field {name} must be an array.',
                    'min' => 'The field {name} must have at least {min} items.',
                    'max' => 'The field {name} must have at most {max} items.',
                    'size' => 'The field {name} must have exactly {size} items.',
                    'exactly' => 'The field {name} must have exactly {exactly} items.',
                    'contains' => 'The field {name} must contain {contains}.',
                ],
                'numeric' => [
                    'default' => 'The field {name} must be a number.',
                    'min' => 'The field {name} must be greater than or equal to {min}.',
                    'max' => 'The field {name} must be less than or equal to {max}.',
                    'integer' => 'The field {name} must be an integer.',
                    'int' => 'The field {name} must be an integer.',
                    'float' => 'The field {name} must be a float.',
                    'decimal' => 'The field {name} must be a decimal.',
                    'gt' => 'The field {name} must be greater than {gt}.',
                    'gte' => 'The field {name} must be greater than or equal to {gte}.',
                    'lt' => 'The field {name} must be less than {lt}.',
                    'lte' => 'The field {name} must be less than or equal to {lte}.',
                ],
                'date' => [
                    'default' => 'The {name} field must be a valid date.',
                    'after' => 'The {name} field must be a date after {after}.',
                    'format' => 'The {name} field must match the format {format}.',
                    'datetime' => 'The {name} field must be a valid datetime.',
                ],
                'file' => [
                    'default' => 'The {name} field must be a file.',
                    'size' => 'The {name} field must be less than {size} kilobytes.',
                    'min' => 'The {name} field must be at least {min} kilobytes.',
                    'max' => 'The {name} field must be less than {max} kilobytes.',
                    'between' => 'The {name} field must be between {min} and {max} kilobytes.',
                    'image' => 'The {name} field must be an image.',
                    'types' => 'The {name} field must be a file of type: {types}.',
                    'extensions' => 'The {name} field must have one of the following extensions: {extensions}.',
                ],
            ],
            'auth' => [
                'logged_in_required' => 'You have to be logged in.',
                'admin_required' => 'You have to be logged in and have admin privileges.',
                'unauthorized_request' => 'You are not authorized to make this request.',
                'unauthorized_action' => 'You are not authorized to %s this resource.',
                'no_policy' => 'No policy found for this resource. Pass the model instance or class name as the second argument.',
                'ability_not_defined' => 'The ability %s is not defined in the policy for this resource.',
                'upload_forbidden' => 'You are not authorized to upload files.',
                'invalid_upload_path' => 'Invalid upload path.',
            ],
            'model' => [
                'not_found' => 'No query results for model [%s] with ids: %s',
            ],
            'filesystem' => [
                'file_not_found' => 'file does not exists at [%s]',
            ],
            'upload' => [
                'directory_unavailable' => 'Upload directory is not available.',
                'move_failed' => 'Could not move the file "%s" to "%s".',
                'ini_size_exceeded' => 'The file "%s" exceeds your upload_max_filesize ini directive (limit is %d KiB).',
                'form_size_exceeded' => 'The file "%s" exceeds the upload limit defined in your form.',
                'partial' => 'The file "%s" was only partially uploaded.',
                'no_file' => 'No file was uploaded.',
                'cant_write' => 'The file "%s" could not be written on disk.',
                'no_tmp_dir' => 'File could not be uploaded: missing temporary directory.',
                'stopped_by_extension' => 'File upload was stopped by a PHP extension.',
                'unknown_error' => 'The file "%s" was not uploaded due to an unknown error.',
            ],
        ];
    }

    /**
     * Get the app defined messages.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function app_defined_messages()
    {
        $app = app();

        if (!method_exists($app, 'config_path')) {
            return [];
        }

        return config('messages', []);
    }

    /**
     * Get the messages.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function messages()
    {
        return array_merge(
            $this->defaults(),
            $this->app_defined_messages(),
        );
    }

    /**
     * Get a message by key.
     *
     * @param string $key The key.
     * @param mixed $args The arguments.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function get(string $key, ...$args)
    {
        $args = !empty($args) ? Arr::flatten($args) : [];
        $message = Arr::get($this->messages(), $key, '');

        throw_if(is_array($message), 'You may forget to define the full path of the message key.', InvalidArgumentException::class);

        return vsprintf($message, $args);
    }
}
