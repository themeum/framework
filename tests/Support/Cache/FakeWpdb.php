<?php

namespace Framework\Tests\Support\Cache;

/**
 * A minimal wpdb double that understands exactly the two DELETE shapes DatabaseStore issues.
 *
 * It is not a SQL engine: it recognises the query::affecting_statement() output produced by
 * DatabaseStore's sweep and matches it against an in-memory row list, purely so the gc() backlog
 * draining logic can be exercised without a real database connection.
 */
class FakeWpdb
{
    public string $options = 'wp_options';

    public string $sitemeta = 'wp_sitemeta';

    public int $rows_affected = 0;

    /** @var string[] */
    public array $option_rows = [];

    /** @var array<int, array{site_id:int, meta_key:string}> */
    public array $sitemeta_rows = [];

    public function esc_like($text)
    {
        return addcslashes((string) $text, '_%\\');
    }

    public function prepare($query, $args)
    {
        $args = is_array($args) ? array_values($args) : array_slice(func_get_args(), 1);

        return preg_replace_callback('/%[sd]/', function () use (&$args) {
            $value = array_shift($args);

            return is_int($value) ? (string) $value : "'" . $value . "'";
        }, $query);
    }

    public function query($sql)
    {
        if (stripos($sql, (string) $this->sitemeta) !== false) {
            return $this->query_sitemeta($sql);
        }

        return $this->query_options($sql);
    }

    protected function query_options($sql)
    {
        $patterns = $this->extract_patterns($sql, 'option_name');
        $limit = $this->extract_limit($sql);

        $removed = [];

        foreach ($this->option_rows as $index => $name) {
            if (count($removed) >= $limit) {
                break;
            }

            if ($this->matches_any($name, $patterns)) {
                $removed[] = $index;
            }
        }

        foreach ($removed as $index) {
            unset($this->option_rows[$index]);
        }

        $this->rows_affected = count($removed);

        return true;
    }

    protected function query_sitemeta($sql)
    {
        preg_match('/site_id = (\d+)/', $sql, $site_match);

        $patterns = $this->extract_patterns($sql, 'meta_key');
        $limit = $this->extract_limit($sql);
        $site_id = isset($site_match[1]) ? (int) $site_match[1] : null;

        $removed = [];

        foreach ($this->sitemeta_rows as $index => $row) {
            if (count($removed) >= $limit) {
                break;
            }

            if (!is_null($site_id) && $row['site_id'] !== $site_id) {
                continue;
            }

            if ($this->matches_any($row['meta_key'], $patterns)) {
                $removed[] = $index;
            }
        }

        foreach ($removed as $index) {
            unset($this->sitemeta_rows[$index]);
        }

        $this->rows_affected = count($removed);

        return true;
    }

    protected function extract_patterns($sql, $column)
    {
        preg_match_all('/' . preg_quote($column, '/') . " LIKE '([^']*)'/", $sql, $matches);

        return $matches[1];
    }

    protected function extract_limit($sql)
    {
        return preg_match('/LIMIT (\d+)/', $sql, $matches) ? (int) $matches[1] : PHP_INT_MAX;
    }

    protected function matches_any($value, array $patterns)
    {
        foreach ($patterns as $pattern) {
            $prefix = rtrim($pattern, '%');
            $prefix = str_replace(['\\_', '\\%', '\\\\'], ['_', '%', '\\'], $prefix);

            if (strncmp($value, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }

        return false;
    }
}
