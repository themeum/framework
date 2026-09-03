<?php
/**
 * A lock backed by the options table.
 * Relies on the unique index over option_name, which makes INSERT IGNORE a genuine atomic
 * acquisition, so a site without a persistent object cache still gets a real lock. This is the
 * same mechanism WordPress core uses for its own locking in WP_Upgrader::create_lock().
 *
 * Rows are written and read as raw statements through the DB facade rather than the options API,
 * because the options cache sits in front of that API and would defeat the atomicity the lock
 * depends on. The facade is still the right seam for those statements: it carries the query log
 * and the error handling every other query in the framework goes through, and the cache already
 * reaches for it in DatabaseStore.
 *
 * @package    Framework
 * @subpackage Cache
 * @since      1.0.0
 */
namespace Framework\Cache\Locks;

defined('ABSPATH') || exit;

use Framework\Cache\Lock;
use Framework\Supports\Facades\DB;
use Throwable;

class DatabaseLock extends Lock
{
    /**
     * The prefix every lock row's option name carries.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $prefix;

    /**
     * The value written for this caller's hold, kept so release can match on it exactly.
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected $payload;

    /**
     * Create an options table backed lock.
     *
     * @param string $name The lock name.
     * @param int $seconds The number of seconds the lock survives for.
     * @param string $prefix The prefix the lock row's option name carries.
     * @param string|null $owner The owner token, or null to generate one.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(string $name, int $seconds = 60, string $prefix = 'framework_', $owner = null)
    {
        parent::__construct($name, $seconds, $owner);

        $this->prefix = $prefix;
    }

    /**
     * Attempt to acquire the lock without waiting.
     *
     * A losing insert is not the end of the attempt: the row may belong to a caller that died,
     * so an expired row is removed with a compare and delete on the exact value that was read,
     * and the insert is retried once. Matching on that value means a holder that renewed between
     * the read and the delete is never evicted.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function acquire()
    {
        $this->payload = $this->owner . '|' . (time() + $this->seconds);

        if ($this->insert($this->payload)) {
            return true;
        }

        $existing = $this->read();

        if (is_null($existing) || !$this->is_expired($existing)) {
            return false;
        }

        $this->compare_and_delete($existing);

        return $this->insert($this->payload);
    }

    /**
     * Release the lock, but only when this caller still holds it.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function release()
    {
        if (is_null($this->payload)) {
            return false;
        }

        $released = $this->compare_and_delete($this->payload);

        if ($released) {
            $this->payload = null;
        }

        return $released;
    }

    /**
     * Remove every expired lock row left behind by callers that never released.
     *
     * @param string $prefix The prefix the lock rows' option names carry.
     *
     * @return int The number of rows removed.
     *
     * @since 1.0.0
     */
    public static function gc(string $prefix = 'framework_')
    {
        $db = static::connection();

        if (is_null($db)) {
            return 0;
        }

        try {
            return (int) DB::delete(
                "DELETE FROM {$db->options}
                 WHERE option_name LIKE %s
                 AND CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) < %d",
                [
                    $db->esc_like($prefix . 'lock_') . '%',
                    time(),
                ]
            );
        } catch (Throwable $exception) {
            // Left behind for the next gc() run; an expired row still reads as acquirable.
            return 0;
        }
    }

    /**
     * Get the database handle the lock statements run against.
     *
     * Returns null whenever the connection cannot be resolved or carries no options table, so
     * that a lock on a half booted installation reports a failed acquisition instead of raising.
     *
     * @return \wpdb|null
     *
     * @since 1.0.0
     */
    protected static function connection()
    {
        try {
            $db = DB::get_db();
        } catch (Throwable $exception) {
            return null;
        }

        if (!is_object($db) || !isset($db->options)) {
            return null;
        }

        return $db;
    }

    /**
     * Get the option name the lock row is stored under.
     *
     * The name is hashed so that an arbitrarily long lock name still fits the column's index.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function option_name()
    {
        return $this->prefix . 'lock_' . md5($this->name);
    }

    /**
     * Insert the lock row, succeeding only when no row already exists.
     *
     * @param string $payload The value to write.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function insert(string $payload)
    {
        $db = static::connection();

        if (is_null($db)) {
            return false;
        }

        try {
            return (bool) DB::insert(
                "INSERT IGNORE INTO {$db->options} (option_name, option_value, autoload)
                 VALUES (%s, %s, 'no')",
                [
                    $this->option_name(),
                    $payload,
                ]
            );
        } catch (Throwable $exception) {
            return false;
        }
    }

    /**
     * Read the value currently held for the lock.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    protected function read()
    {
        $db = static::connection();

        if (is_null($db)) {
            return null;
        }

        try {
            $rows = DB::select(
                "SELECT option_value FROM {$db->options} WHERE option_name = %s LIMIT 1",
                [$this->option_name()]
            );
        } catch (Throwable $exception) {
            return null;
        }

        if (empty($rows) || !isset($rows[0]['option_value'])) {
            return null;
        }

        return (string) $rows[0]['option_value'];
    }

    /**
     * Delete the lock row only when it still holds the exact value given.
     *
     * @param string $payload The value the row must still hold.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function compare_and_delete(string $payload)
    {
        $db = static::connection();

        if (is_null($db)) {
            return false;
        }

        try {
            $deleted = DB::delete(
                "DELETE FROM {$db->options} WHERE option_name = %s AND option_value = %s",
                [
                    $this->option_name(),
                    $payload,
                ]
            );
        } catch (Throwable $exception) {
            return false;
        }

        return (int) $deleted === 1;
    }

    /**
     * Determine whether a stored lock value has passed its expiry.
     *
     * @param string $value The stored value.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function is_expired(string $value)
    {
        $position = strrpos($value, '|');

        if ($position === false) {
            return true;
        }

        return (int) substr($value, $position + 1) <= time();
    }
}
