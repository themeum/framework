<?php

namespace Framework\Tests\Support\RateLimiting;

/**
 * A minimal options table standing in for wpdb, understanding only the statements the database
 * lock issues: an INSERT IGNORE that must fail on a duplicate option_name, a single row SELECT,
 * a DELETE matching both name and value, and the sweep that removes expired rows.
 *
 * The lock reaches the database through the DB facade, so this double also carries the handful of
 * wpdb properties Connection inspects after every statement: rows_affected, which it returns from
 * affecting_statement() and rejects when negative, and last_error, which it turns into a
 * QueryException.
 */
class LockWpdb
{
    public string $options = 'wp_options';

    /** @var array<string, string> */
    public array $rows = [];

    public int $insert_attempts = 0;

    public int $rows_affected = 0;

    public string $last_error = '';

    public function esc_like($text)
    {
        return addcslashes((string) $text, '_%\\');
    }

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        $args = array_values($args);

        return preg_replace_callback('/%[sd]/', function ($match) use (&$args) {
            $value = array_shift($args);

            return $match[0] === '%d' ? (string) (int) $value : "'" . $value . "'";
        }, $query);
    }

    public function query($sql)
    {
        $this->rows_affected = 0;

        if (stripos($sql, 'INSERT IGNORE') !== false) {
            return $this->rows_affected = $this->insert($sql);
        }

        if (stripos($sql, 'DELETE') !== false) {
            return $this->rows_affected = $this->delete($sql);
        }

        return 0;
    }

    public function get_results($sql, $output = null)
    {
        $value = $this->get_var($sql);

        if (is_null($value)) {
            return [];
        }

        return [['option_value' => $value]];
    }

    public function get_var($sql)
    {
        if (!preg_match("/option_name = '([^']*)'/", $sql, $match)) {
            return null;
        }

        return $this->rows[$match[1]] ?? null;
    }

    protected function insert($sql)
    {
        $this->insert_attempts++;

        if (!preg_match_all("/'([^']*)'/", $sql, $matches)) {
            return 0;
        }

        [$name, $value] = $matches[1];

        if (array_key_exists($name, $this->rows)) {
            return 0;
        }

        $this->rows[$name] = $value;

        return 1;
    }

    protected function delete($sql)
    {
        if (preg_match("/option_name LIKE '([^']*)'.*< (\d+)/s", $sql, $sweep)) {
            return $this->sweep($sweep[1], (int) $sweep[2]);
        }

        preg_match("/option_name = '([^']*)'/", $sql, $name_match);
        preg_match("/option_value = '([^']*)'/", $sql, $value_match);

        if (empty($name_match)) {
            return 0;
        }

        $name = $name_match[1];

        if (!array_key_exists($name, $this->rows)) {
            return 0;
        }

        if (!empty($value_match) && $this->rows[$name] !== $value_match[1]) {
            return 0;
        }

        unset($this->rows[$name]);

        return 1;
    }

    protected function sweep($pattern, $before)
    {
        $prefix = str_replace(['%', '\\_'], ['', '_'], $pattern);
        $removed = 0;

        foreach ($this->rows as $name => $value) {
            if (strpos($name, $prefix) !== 0) {
                continue;
            }

            $position = strrpos($value, '|');

            if ($position === false || (int) substr($value, $position + 1) >= $before) {
                continue;
            }

            unset($this->rows[$name]);
            $removed++;
        }

        return $removed;
    }
}
