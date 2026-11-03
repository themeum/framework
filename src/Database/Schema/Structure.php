<?php
/**
 * Fluent builder for defining database table columns, indexes, and constraints.
 * Collects column definitions and table-level commands before handing them to the schema compiler.
 * Provides a chainable API for migrations and programmatic schema changes.
 *
 * @package    Framework
 * @subpackage Database\Schema
 * @since      1.0.0
 */
namespace Framework\Database\Schema;

defined('ABSPATH') || exit;

use Framework\Database\Constants\ColumnTypes;
use Framework\Database\Connection\Connection;
use Framework\Database\Schema\Definitions\Definition;
use Framework\Database\Schema\Definitions\ForeignKeyDefinition;
use Exception;

use function Framework\throw_unless;

class Structure
{
    /**
     * Mode used when the structure describes a new table.
     *
     * @var string
     *
     * @since 1.0.0
     */
    public const MODE_CREATE = 'create';

    /**
     * Mode used when the structure describes changes to an existing table.
     *
     * @var string
     *
     * @since 1.0.0
     */
    public const MODE_ALTER = 'alter';

    /**
     * The maximum length a key or constraint name may have.
     *
     * @var int
     *
     * @since 1.0.0
     */
    public const MAX_KEY_NAME_LENGTH = 64;

    /**
     * The name of the table (without prefix).
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected $table = null;

    /**
     * The operation the structure describes.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $mode = self::MODE_CREATE;

    /**
     * The database connection instance.
     *
     * @var Connection
     *
     * @since 1.0.0
     */
    protected $connection = null;

    /**
     * The table prefix.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $prefix = '';

    /**
     * The columns defined for the table.
     *
     * @var array<string, Definition>
     *
     * @since 1.0.0
     */
    protected $columns = [];

    /**
     * The storage engine for the table.
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected $engine = null;

    /**
     * The character set for the table.
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected $charset = null;

    /**
     * The collation for the table.
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected $collate = null;

    /**
     * The commands for the table structure.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $commands = [];

    /**
     * Structure constructor.
     *
     * @param string $table The table name (without prefix)
     * @param Connection $connection The database connection instance
     * @param string $mode The operation the structure describes
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(string $table, Connection $connection, string $mode = self::MODE_CREATE)
    {
        $this->table = $table;
        $this->connection = $connection;
        $this->mode = $mode;
        $db = $this->connection->get_db();

        $this->prefix = $db->prefix;
        $this->engine = 'InnoDB';
        $this->charset = $db->charset;
        $this->collate = $db->collate;
    }

    /**
     * Determine whether the structure describes changes to an existing table.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function is_altering()
    {
        return $this->mode === static::MODE_ALTER;
    }

    /**
     * Expose the connection instance.
     *
     * @return Connection
     *
     * @since 1.0.0
     */
    public function connection()
    {
        return $this->connection;
    }

    /**
     * Get the full table name with prefix.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_table()
    {
        return $this->table;
    }

    /**
     * Wrap the table name with the query compiler.
     *
     * @param string $table The table name
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function wrap_table($table)
    {
        return $this->connection()
            ->get_query_compiler()
            ->wrap_table($table);
    }

    /**
     * Get the character set for the table.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function get_charset()
    {
        return $this->charset;
    }

    /**
     * Get the storage engine for the table.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function get_engine()
    {
        return $this->engine;
    }

    /**
     * Get the collation for the table.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function get_collate()
    {
        return $this->collate;
    }

    /**
     * Get the commands for the table structure.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function get_commands()
    {
        return $this->commands;
    }

    /**
     * Set the storage engine for the table.
     *
     * @param string $engine The engine name (e.g., 'InnoDB', 'MyISAM')
     *
     * @return void
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    public function engine(string $engine)
    {
        $available_engines = ['InnoDB', 'MyISAM'];

        throw_unless(in_array($engine, $available_engines), "Invalid engine: $engine");

        $this->engine = $engine;
    }

    /**
     * Set the character set for the table.
     *
     * @param string $charset The charset (e.g., 'utf8mb4', 'utf8')
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function charset(string $charset)
    {
        $this->charset = $charset;
    }

    /**
     * Set the collation for the table.
     *
     * @param string $collate The collation (e.g., 'utf8mb4_unicode_ci', 'utf8_general_ci')
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function collate(string $collate)
    {
        $this->collate = $collate;
    }

    /**
     * Get the columns defined for the table.
     *
     * @return array<string, Definition>
     *
     * @since 1.0.0
     */
    public function get_columns()
    {
        return $this->columns;
    }

    /**
     * Add an auto-incrementing BIGINT primary key column named 'ID'.
     *
     * @param string $name The name.
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function id(string $name = 'id')
    {
        return $this->big_increment($name)->primary();
    }

    /**
     * Add a TIMESTAMP column.
     *
     * @param string $name The column name
     * @param int $precision The precision for the timestamp
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function timestamp(string $name, $precision = 0)
    {
        return $this->add_column(ColumnTypes::TIMESTAMP, $name, compact('precision'));
    }

    /**
     * Add 'created_at' and 'updated_at' timestamp columns.
     *
     * @param int $precision The precision for the timestamp columns
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function timestamps($precision = 0)
    {
        $this->timestamp('created_at', $precision)->use_current();
        $this->timestamp('updated_at', $precision)->nullable();
    }

    /**
     * Add 'created_at' and 'updated_at' datetime columns.
     *
     * DATETIME avoids TIMESTAMP's 2038 upper bound and its session time zone
     * conversion, storing the value exactly as written.
     *
     * @param int $precision The precision for the datetime columns
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function datetimes($precision = 0)
    {
        $this->datetime('created_at', $precision)->use_current();
        $this->datetime('updated_at', $precision)->nullable();
    }

    /**
     * Add an INTEGER column.
     *
     * @param string $name The column name
     * @param bool $auto_increment Whether the column is auto-increment
     * @param bool $unsigned Whether the column is unsigned
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function integer(string $name, $auto_increment = false, $unsigned = false)
    {
        return $this->add_column(ColumnTypes::INTEGER, $name, compact('auto_increment', 'unsigned'));
    }

    /**
     * Add a TINYINT column.
     *
     * @param string $name The column name
     * @param bool $auto_increment Whether the column is auto-increment
     * @param bool $unsigned Whether the column is unsigned
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function tiny_integer(string $name, $auto_increment = false, $unsigned = false)
    {
        return $this->add_column(ColumnTypes::TINYINT, $name, compact('auto_increment', 'unsigned'));
    }

    /**
     * Add a BIGINT column.
     *
     * @param string $name The column name
     * @param bool $auto_increment Whether the column is auto-increment
     * @param bool $unsigned Whether the column is unsigned
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function big_integer(string $name, $auto_increment = false, $unsigned = false)
    {
        return $this->add_column(ColumnTypes::BIGINT, $name, compact('auto_increment', 'unsigned'));
    }

    /**
     * Add an unsigned BIGINT column.
     *
     * @param string $name The column name
     * @param bool $auto_increment Whether the column is auto-increment
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function unsigned_big_integer(string $name, $auto_increment = false)
    {
        return $this->big_integer($name, $auto_increment, true);
    }

    /**
     * Add an unsigned INTEGER column.
     *
     * @param string $name The column name
     * @param bool $auto_increment Whether the column is auto-increment
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function unsigned_integer(string $name, $auto_increment = false)
    {
        return $this->integer($name, $auto_increment, true);
    }

    /**
     * Add an unsigned TINYINT column.
     *
     * @param string $name The column name
     * @param bool $auto_increment Whether the column is auto-increment
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function unsigned_tiny_integer(string $name, $auto_increment = false)
    {
        return $this->tiny_integer($name, $auto_increment, true);
    }

    /**
     * Add an auto-incrementing unsigned INTEGER column.
     *
     * @param string $name The column name
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function increment(string $name)
    {
        return $this->unsigned_integer($name, true);
    }

    /**
     * Add an auto-incrementing unsigned BIGINT column.
     *
     * @param string $name The column name
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function big_increment(string $name)
    {
        return $this->unsigned_big_integer($name, true);
    }

    /**
     * Add a DECIMAL column.
     *
     * @param string $name The column name
     * @param int $total The total number of digits
     * @param int $places The number of decimal places
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function decimal(string $name, $total = 8, $places = 2)
    {
        return $this->add_column(ColumnTypes::DECIMAL, $name, compact('total', 'places'));
    }

    /**
     * Add a TEXT column.
     *
     * @param string $name The column name
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function text(string $name)
    {
        return $this->add_column(ColumnTypes::TEXT, $name);
    }

    /**
     * Add a STRING (VARCHAR) column.
     *
     * @param string $name The column name
     * @param int|null $length The length of the string
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function string(string $name, $length = null)
    {
        $length = $length ?: 255;

        return $this->add_column(ColumnTypes::STRING, $name, compact('length'));
    }

    /**
     * Add a MEDIUM TEXT column.
     *
     * @param string $name The column name
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function medium_text(string $name)
    {
        return $this->add_column(ColumnTypes::MEDIUM_TEXT, $name);
    }

    /**
     * Add a LONG TEXT column.
     *
     * @param string $name The column name
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function long_text(string $name)
    {
        return $this->add_column(ColumnTypes::LONG_TEXT, $name);
    }

    /**
     * Add a BOOLEAN column.
     *
     * @param string $name The column name
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function boolean(string $name)
    {
        return $this->add_column(ColumnTypes::BOOLEAN, $name);
    }

    /**
     * Add a FLOAT column.
     *
     * @param string $name The column name
     * @param int $precision The precision for the float
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function float(string $name, $precision = 53)
    {
        return $this->add_column(ColumnTypes::FLOAT, $name, compact('precision'));
    }

    /**
     * Add a DOUBLE column.
     *
     * @param string $name The column name
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function double(string $name)
    {
        return $this->add_column(ColumnTypes::DOUBLE, $name);
    }

    /**
     * Add a DATE column.
     *
     * @param string $name The column name
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function date(string $name)
    {
        return $this->add_column(ColumnTypes::DATE, $name);
    }

    /**
     * Add a DATETIME column.
     *
     * @param string $name The column name
     * @param int $precision The precision for the datetime
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function datetime(string $name, $precision = 0)
    {
        return $this->add_column(ColumnTypes::DATETIME, $name, compact('precision'));
    }

    /**
     * Add a TIME column.
     *
     * @param string $name The column name
     * @param int $precision The precision for the time
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function time(string $name, $precision = 0)
    {
        return $this->add_column(ColumnTypes::TIME, $name, compact('precision'));
    }

    /**
     * Add an ENUM column.
     *
     * @param string $name The column name
     * @param array $values The values for the enum
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function enum(string $name, array $values)
    {
        return $this->add_column(ColumnTypes::ENUM, $name, compact('values'));
    }

    /**
     * Add a SET column.
     *
     * @param string $name The column name
     * @param array $values The values for the set
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function set(string $name, array $values)
    {
        return $this->add_column(ColumnTypes::SET, $name, compact('values'));
    }

    /**
     * Add an IP address column.
     *
     * @param string $name The column name
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function ip_address(string $name)
    {
        return $this->add_column(ColumnTypes::IP_ADDRESS, $name);
    }

    /**
     * Add a UUID column.
     *
     * @param string $name The column name
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function uuid(string $name)
    {
        return $this->add_column(ColumnTypes::UUID, $name);
    }

    /**
     * Add a YEAR column.
     *
     * @param string $name The column name
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    public function year(string $name)
    {
        return $this->add_column(ColumnTypes::YEAR, $name);
    }

    /**
     * Add a column definition to the table.
     *
     * @param string $type The column type
     * @param string $name The column name
     * @param array $parameters Additional parameters for the column
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    protected function add_column($type, $name, array $parameters = [])
    {
        return $this->add_column_definition(
            new Definition(
                array_merge(compact('type', 'name'), $parameters)
            )
        );
    }

    /**
     * Add a column definition object to the columns array.
     *
     * @param Definition $definition The column definition
     *
     * @return Definition
     *
     * @since 1.0.0
     */
    protected function add_column_definition(Definition $definition)
    {
        $this->columns[] = $definition;

        return $definition;
    }

    /**
     * Define the primary key for the table.
     *
     * @param string|array $keys The column(s) to use as primary key
     * @param string|null $key_name Optional constraint name
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function primary($keys, $key_name = null)
    {
        if (!is_array($keys)) {
            $keys = [$keys];
        }

        if (!empty($key_name)) {
            $key_name = $this->format_key_name($key_name);
        }

        $this->commands['primary'] = [
            'name' => $key_name,
            'keys' => $keys,
        ];

        return $this;
    }

    /**
     * Define a unique key for the table.
     *
     * @param string|array $keys The column(s) to use as unique key
     * @param string|null $key_name Optional constraint name
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function unique($keys, $key_name = null)
    {
        if (!is_array($keys)) {
            $keys = [$keys];
        }

        if (empty($key_name)) {
            $key_name = sprintf('%s_%s_unique', $this->get_table(), implode('_', $keys));
        }

        $key_name = $this->format_key_name($key_name);

        $this->commands['unique'][] = [
            'name' => $key_name,
            'keys' => $keys,
        ];

        return $this;
    }

    /**
     * Define an index for the table.
     *
     * @param string|array $keys The column(s) to index
     * @param string|null $key_name Optional index name
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function index($keys, $key_name = null)
    {
        if (!is_array($keys)) {
            $keys = [$keys];
        }

        if (empty($key_name)) {
            $key_name = sprintf('%s_%s_index', $this->get_table(), implode('_', $keys));
        }

        $key_name = $this->format_key_name($key_name);

        $this->commands['index'][] = [
            'name' => $key_name,
            'keys' => $keys,
        ];

        return $this;
    }

    /**
     * Define a foreign key constraint.
     *
     * @param string $column The column.
     * @param mixed $name The name.
     *
     * @return ForeignKeyDefinition
     *
     * @since 1.0.0
     */
    public function foreign(string $column, $name = null)
    {
        $command = new ForeignKeyDefinition(compact('column', 'name'));

        $this->commands['foreign'][] = $command;

        return $command;
    }

    /**
     * Guard an operation that is only valid while altering an existing table.
     *
     * @param string $operation The operation name.
     *
     * @return void
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    protected function guard_altering(string $operation)
    {
        throw_unless(
            $this->is_altering(),
            sprintf(
                'The [%s] operation is only available when altering an existing table.',
                $operation
            ),
            Exception::class
        );
    }

    /**
     * Drop one or more columns from the table.
     *
     * @param string|array $columns The column name(s) to drop
     *
     * @return $this
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    public function drop_column($columns)
    {
        $this->guard_altering('drop_column');

        if (!is_array($columns)) {
            $columns = [$columns];
        }

        foreach ($columns as $column) {
            $this->commands['drop_column'][] = $column;
        }

        return $this;
    }

    /**
     * Rename a column on the table.
     *
     * @param string $from The current column name
     * @param string $to The new column name
     *
     * @return $this
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    public function rename_column(string $from, string $to)
    {
        $this->guard_altering('rename_column');

        $this->commands['rename_column'][] = [
            'from' => $from,
            'to'   => $to,
        ];

        return $this;
    }

    /**
     * Drop an index from the table.
     *
     * @param string|array $keys The index name, or the column(s) it covers
     *
     * @return $this
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    public function drop_index($keys)
    {
        $this->guard_altering('drop_index');

        $this->commands['drop_index'][] = $this->resolve_key_name($keys, 'index');

        return $this;
    }

    /**
     * Drop a unique key from the table.
     *
     * @param string|array $keys The unique key name, or the column(s) it covers
     *
     * @return $this
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    public function drop_unique($keys)
    {
        $this->guard_altering('drop_unique');

        $this->commands['drop_index'][] = $this->resolve_key_name($keys, 'unique');

        return $this;
    }

    /**
     * Drop the primary key from the table.
     *
     * @return $this
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    public function drop_primary()
    {
        $this->guard_altering('drop_primary');

        $this->commands['drop_primary'] = true;

        return $this;
    }

    /**
     * Drop a foreign key constraint from the table.
     *
     * @param string|array $columns The constraint name, or the column(s) it covers
     *
     * @return $this
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    public function drop_foreign($columns)
    {
        $this->guard_altering('drop_foreign');

        $this->commands['drop_foreign'][] = $this->resolve_key_name($columns, 'foreign');

        return $this;
    }

    /**
     * Resolve a key name from either an explicit name or the columns it covers.
     *
     * @param string|array $keys The key name, or the column(s) it covers
     * @param string $suffix The conventional key name suffix
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function resolve_key_name($keys, string $suffix)
    {
        if (!is_array($keys)) {
            return $this->format_key_name($keys);
        }

        return $this->format_key_name(
            sprintf('%s_%s_%s', $this->get_table(), implode('_', $keys), $suffix)
        );
    }

    /**
     * Shorten a key or constraint name that exceeds the database identifier limit.
     *
     * MySQL and MariaDB reject identifiers longer than 64 characters. A name over
     * the limit is truncated and suffixed with a hash of the full name, so the
     * result stays collision free and is reproduced identically by the migration
     * that later drops the key.
     *
     * @param string $name The generated or explicit key name.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function format_key_name(string $name)
    {
        if (strlen($name) <= static::MAX_KEY_NAME_LENGTH) {
            return $name;
        }

        $hash = substr(md5($name), 0, 8);

        return sprintf(
            '%s_%s',
            substr($name, 0, static::MAX_KEY_NAME_LENGTH - (strlen($hash) + 1)),
            $hash
        );
    }

    /**
     * Get the compiler instance for compiling table structure.
     *
     * @return Compiler
     *
     * @since 1.0.0
     */
    protected function compiler()
    {
        return new Compiler($this->connection);
    }

    /**
     * Get the complete SQL statement for creating or altering the table.
     *
     * @return string
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    public function get_table_structure()
    {
        if ($this->is_altering()) {
            return $this->compiler()->compile_alter($this);
        }

        return $this->compiler()->compile_create($this);
    }
}
