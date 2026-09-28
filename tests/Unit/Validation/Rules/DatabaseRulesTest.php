<?php

namespace Framework\Tests\Unit\Validation\Rules;

use Framework\Container;
use Framework\Database\Connection\Connection;
use Framework\Database\Connection\DatabaseManager;
use Framework\Tests\Support\Database\ModelTestWpdb;
use Framework\Tests\Unit\TestCase;
use Framework\Validation\Validator;

class DatabaseRulesTest extends TestCase
{
    protected function bootstrap_database(array $seed = []): ModelTestWpdb
    {
        global $wpdb;

        $wpdb = new ModelTestWpdb();

        foreach ($seed as $table => $rows) {
            $wpdb->seed($table, $rows);
        }

        $container = new Container();
        $container->instance('app', $container);
        $container->instance('db', new DatabaseManager(new Connection()));

        $this->set_container_instance($container);

        return $wpdb;
    }

    public function test_exists_passes_when_record_is_found(): void
    {
        $this->bootstrap_database(['products' => [['sku' => 'PRD-100']]]);

        $validator = Validator::make(['sku' => 'PRD-100'], ['sku' => 'exists:products']);

        $this->assertTrue($validator->passes());
    }

    public function test_exists_fails_when_record_is_missing(): void
    {
        $this->bootstrap_database(['products' => [['sku' => 'PRD-100']]]);

        $validator = Validator::make(['sku' => 'PRD-999'], ['sku' => 'exists:products']);

        $this->assertTrue($validator->fails());
        $this->assertSame(['The selected  does not exist.'], $validator->errors()['sku']);
    }

    public function test_exists_uses_explicit_column_argument(): void
    {
        $this->bootstrap_database(['products' => [['code' => 'ABC']]]);

        $validator = Validator::make(['sku' => 'ABC'], ['sku' => 'exists:products,code']);

        $this->assertTrue($validator->passes());
    }

    public function test_exists_queries_the_prefixed_table(): void
    {
        $wpdb = $this->bootstrap_database(['products' => [['sku' => 'PRD-100']]]);

        Validator::make(['sku' => 'PRD-100'], ['sku' => 'exists:products'])->passes();

        $this->assertStringContainsString('from `wp_products`', strtolower(end($wpdb->queries)));
    }

    public function test_exists_passes_when_all_array_values_exist(): void
    {
        $this->bootstrap_database([
            'products' => [
                ['id' => 1],
                ['id' => 4],
            ],
        ]);

        $validator = Validator::make(
            ['ids' => [1, 4]],
            ['ids' => 'array|exists:products,id']
        );

        $this->assertTrue($validator->passes());
    }

    public function test_exists_fails_when_any_array_value_is_missing(): void
    {
        $this->bootstrap_database([
            'products' => [
                ['id' => 1],
            ],
        ]);

        $validator = Validator::make(
            ['ids' => [1, 4]],
            ['ids' => 'array|exists:products,id']
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(['The selected  does not exist.'], $validator->errors()['ids']);
    }

    public function test_exists_uses_where_in_for_array_values(): void
    {
        $wpdb = $this->bootstrap_database([
            'products' => [
                ['id' => 1],
                ['id' => 4],
            ],
        ]);

        Validator::make(
            ['ids' => [1, 4]],
            ['ids' => 'array|exists:products,id']
        )->passes();

        $this->assertMatchesRegularExpression(
            '/where `id` in \(1,\s*4\)/i',
            end($wpdb->queries)
        );
    }

    public function test_unique_passes_when_no_record_matches(): void
    {
        $this->bootstrap_database(['users' => [['email' => 'taken@example.com']]]);

        $validator = Validator::make(['email' => 'free@example.com'], ['email' => 'unique:users']);

        $this->assertTrue($validator->passes());
    }

    public function test_unique_fails_when_record_already_exists(): void
    {
        $this->bootstrap_database(['users' => [['email' => 'taken@example.com']]]);

        $validator = Validator::make(['email' => 'taken@example.com'], ['email' => 'unique:users']);

        $this->assertTrue($validator->fails());
        $this->assertSame(['The  has already been taken.'], $validator->errors()['email']);
    }

    public function test_unique_appends_ignore_id_clause(): void
    {
        $wpdb = $this->bootstrap_database(['users' => []]);

        Validator::make(
            ['email' => 'jane@example.com'],
            ['email' => 'unique:users,email,5']
        )->passes();

        $this->assertMatchesRegularExpression('/and `id` != \'?5\'?/i', end($wpdb->queries));
    }

    public function test_unique_ignore_id_allows_existing_record(): void
    {
        $this->bootstrap_database([
            'users' => [
                ['id' => 5, 'email' => 'jane@example.com'],
            ],
        ]);

        $validator = Validator::make(
            ['email' => 'jane@example.com'],
            ['email' => 'unique:users,email,5']
        );

        $this->assertTrue($validator->passes());
    }

    public function test_unique_uses_explicit_column_argument(): void
    {
        $this->bootstrap_database(['users' => [['user_email' => 'taken@example.com']]]);

        $validator = Validator::make(
            ['email' => 'taken@example.com'],
            ['email' => 'unique:users,user_email']
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(['The  has already been taken.'], $validator->errors()['email']);
    }
}
