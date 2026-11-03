<?php

namespace Framework\Tests\Unit\Database\Schema;

use Framework\Database\Schema\Structure;
use Framework\Tests\Unit\TestCase;
use Exception;

class CompilerTest extends TestCase
{
    public function test_create_compiles_columns_and_keys(): void
    {
        $structure = $this->make_structure('posts', ['prefix' => 'wp_']);
        $structure->id();
        $structure->string('title');
        $structure->index(['title']);

        $this->assertSame(
            'CREATE TABLE IF NOT EXISTS `wp_posts` ('
            . '`id` bigint unsigned not null auto_increment, '
            . '`title` varchar(255) not null,'
            . ' PRIMARY KEY (`id`),'
            . '  KEY `posts_title_index` (`title`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $structure->get_table_structure()
        );
    }

    public function test_create_names_foreign_key_constraints(): void
    {
        $structure = $this->make_structure('blogs', ['prefix' => 'wp_']);
        $structure->big_integer('category_id')->unsigned()->nullable();
        $structure->foreign('category_id')->references('id')->on('categories')->null_on_delete();

        $this->assertStringContainsString(
            'CONSTRAINT `blogs_category_id_foreign` FOREIGN KEY (`category_id`) '
            . 'REFERENCES `wp_categories` (`id`) ON DELETE set null',
            $structure->get_table_structure()
        );
    }

    public function test_long_foreign_key_names_are_shortened_to_the_identifier_limit(): void
    {
        $table = 'organisation_membership_subscription_invoices';
        $structure = $this->make_structure($table, ['prefix' => 'wp_']);
        $structure->big_integer('billing_contact_person_id')->unsigned();
        $structure->foreign('billing_contact_person_id')->references('id')->on('people');

        preg_match('/CONSTRAINT `([^`]+)`/', $structure->get_table_structure(), $matches);

        $this->assertLessThanOrEqual(64, strlen($matches[1]));
        $this->assertStringStartsWith($table, $matches[1]);
    }

    public function test_a_shortened_foreign_key_name_matches_the_name_used_to_drop_it(): void
    {
        $table = 'organisation_membership_subscription_invoices';
        $column = 'billing_contact_person_id';

        $create = $this->make_structure($table, ['prefix' => 'wp_']);
        $create->big_integer($column)->unsigned();
        $create->foreign($column)->references('id')->on('people');

        preg_match('/CONSTRAINT `([^`]+)`/', $create->get_table_structure(), $matches);

        $drop = $this->make_alter_structure($table, ['prefix' => 'wp_']);
        $drop->drop_foreign([$column]);

        $this->assertStringContainsString(
            sprintf('DROP FOREIGN KEY `%s`', $matches[1]),
            $drop->get_table_structure()
        );
    }

    public function test_long_index_names_are_shortened_and_stay_reproducible(): void
    {
        $table = 'organisation_membership_subscription_invoices';
        $keys = ['billing_contact_person_id', 'settlement_currency_code'];

        $create = $this->make_structure($table, ['prefix' => 'wp_']);
        $create->big_integer('billing_contact_person_id')->unsigned();
        $create->string('settlement_currency_code');
        $create->index($keys);

        preg_match('/KEY `([^`]+)` \(/', $create->get_table_structure(), $matches);

        $this->assertLessThanOrEqual(64, strlen($matches[1]));

        $drop = $this->make_alter_structure($table, ['prefix' => 'wp_']);
        $drop->drop_index($keys);

        $this->assertStringContainsString(
            sprintf('DROP INDEX `%s`', $matches[1]),
            $drop->get_table_structure()
        );
    }

    public function test_key_names_within_the_limit_are_left_untouched(): void
    {
        $structure = $this->make_structure('posts', ['prefix' => 'wp_']);

        $this->assertSame('posts_title_index', $structure->format_key_name('posts_title_index'));
    }

    public function test_distinct_long_key_names_do_not_collide_after_shortening(): void
    {
        $structure = $this->make_structure('posts', ['prefix' => 'wp_']);
        $prefix = str_repeat('a', 60);

        $this->assertNotSame(
            $structure->format_key_name($prefix . '_first_column_index'),
            $structure->format_key_name($prefix . '_second_column_index')
        );
    }

    public function test_create_honours_an_explicit_foreign_key_name(): void
    {
        $structure = $this->make_structure('blogs', ['prefix' => 'wp_']);
        $structure->big_integer('category_id')->unsigned();
        $structure->foreign('category_id', 'custom_fk')->references('id')->on('categories');

        $this->assertStringContainsString(
            'CONSTRAINT `custom_fk` FOREIGN KEY (`category_id`)',
            $structure->get_table_structure()
        );
    }

    public function test_create_never_emits_a_position_modifier(): void
    {
        $structure = $this->make_structure('posts', ['prefix' => 'wp_']);
        $structure->string('title')->after('id');
        $structure->string('slug')->first();

        $sql = $structure->get_table_structure();

        $this->assertStringNotContainsString('AFTER', $sql);
        $this->assertStringNotContainsString('FIRST', $sql);
    }

    public function test_alter_combines_every_clause_into_one_statement(): void
    {
        $structure = $this->make_alter_structure('posts', ['prefix' => 'wp_']);
        $structure->string('slug')->after('title');
        $structure->string('title', 500)->change();
        $structure->drop_column('legacy_ref');
        $structure->unique(['slug']);

        $this->assertSame(
            'ALTER TABLE `wp_posts` '
            . 'ADD COLUMN `slug` varchar(255) not null AFTER `title`, '
            . 'MODIFY COLUMN `title` varchar(500) not null, '
            . 'ADD UNIQUE KEY `posts_slug_unique` (`slug`), '
            . 'DROP COLUMN `legacy_ref`',
            $structure->get_table_structure()
        );
    }

    public function test_alter_places_a_column_first(): void
    {
        $structure = $this->make_alter_structure('posts', ['prefix' => 'wp_']);
        $structure->string('slug')->first();

        $this->assertSame(
            'ALTER TABLE `wp_posts` ADD COLUMN `slug` varchar(255) not null FIRST',
            $structure->get_table_structure()
        );
    }

    public function test_alter_adds_an_index_and_a_named_foreign_key(): void
    {
        $structure = $this->make_alter_structure('blogs', ['prefix' => 'wp_']);
        $structure->index(['status']);
        $structure->foreign('author_id')->references('id')->on('users')->cascade_on_delete();

        $this->assertSame(
            'ALTER TABLE `wp_blogs` '
            . 'ADD INDEX `blogs_status_index` (`status`), '
            . 'ADD CONSTRAINT `blogs_author_id_foreign` FOREIGN KEY (`author_id`) '
            . 'REFERENCES `wp_users` (`id`) ON DELETE cascade',
            $structure->get_table_structure()
        );
    }

    public function test_alter_drops_keys_by_derived_name(): void
    {
        $structure = $this->make_alter_structure('posts', ['prefix' => 'wp_']);
        $structure->drop_index(['title']);
        $structure->drop_unique(['slug']);
        $structure->drop_foreign(['author_id']);
        $structure->drop_primary();

        $this->assertSame(
            'ALTER TABLE `wp_posts` '
            . 'DROP FOREIGN KEY `posts_author_id_foreign`, '
            . 'DROP INDEX `posts_title_index`, '
            . 'DROP INDEX `posts_slug_unique`, '
            . 'DROP PRIMARY KEY',
            $structure->get_table_structure()
        );
    }

    public function test_alter_drops_keys_by_explicit_name(): void
    {
        $structure = $this->make_alter_structure('posts', ['prefix' => 'wp_']);
        $structure->drop_index('legacy_index');
        $structure->drop_foreign('wp_posts_ibfk_1');

        $this->assertSame(
            'ALTER TABLE `wp_posts` '
            . 'DROP FOREIGN KEY `wp_posts_ibfk_1`, '
            . 'DROP INDEX `legacy_index`',
            $structure->get_table_structure()
        );
    }

    public function test_alter_drops_several_columns(): void
    {
        $structure = $this->make_alter_structure('posts', ['prefix' => 'wp_']);
        $structure->drop_column(['one', 'two']);

        $this->assertSame(
            'ALTER TABLE `wp_posts` DROP COLUMN `one`, DROP COLUMN `two`',
            $structure->get_table_structure()
        );
    }

    public function test_alter_renames_a_column_using_its_existing_definition(): void
    {
        $structure = $this->make_alter_structure(
            'posts',
            [
                'prefix'  => 'wp_',
                'results' => [
                    [
                        'name'     => 'desc',
                        'type'     => 'text',
                        'nullable' => 'NO',
                        'default'  => null,
                        'extra'    => '',
                        'comment'  => 'body',
                    ],
                ],
            ]
        );

        $structure->rename_column('desc', 'description');

        $this->assertSame(
            'ALTER TABLE `wp_posts` '
            . "CHANGE COLUMN `desc` `description` text not null comment 'body'",
            $structure->get_table_structure()
        );
    }

    public function test_alter_rename_preserves_a_current_timestamp_default(): void
    {
        $structure = $this->make_alter_structure(
            'posts',
            [
                'prefix'  => 'wp_',
                'results' => [
                    [
                        'name'     => 'created',
                        'type'     => 'timestamp',
                        'nullable' => 'NO',
                        'default'  => 'CURRENT_TIMESTAMP',
                        'extra'    => '',
                        'comment'  => '',
                    ],
                ],
            ]
        );

        $structure->rename_column('created', 'created_at');

        $this->assertSame(
            'ALTER TABLE `wp_posts` '
            . 'CHANGE COLUMN `created` `created_at` timestamp not null default CURRENT_TIMESTAMP',
            $structure->get_table_structure()
        );
    }

    public function test_alter_rename_treats_a_bare_null_default_as_no_default(): void
    {
        $structure = $this->make_alter_structure(
            'posts',
            [
                'prefix'  => 'wp_',
                'results' => [
                    [
                        'name'     => 'excerpt',
                        'type'     => 'text',
                        'nullable' => 'YES',
                        'default'  => 'NULL',
                        'extra'    => '',
                        'comment'  => '',
                    ],
                ],
            ]
        );

        $structure->rename_column('excerpt', 'summary');

        $this->assertSame(
            'ALTER TABLE `wp_posts` CHANGE COLUMN `excerpt` `summary` text null',
            $structure->get_table_structure()
        );
    }

    public function test_alter_rename_does_not_requote_an_already_quoted_default(): void
    {
        $structure = $this->make_alter_structure(
            'posts',
            [
                'prefix'  => 'wp_',
                'results' => [
                    [
                        'name'     => 'status',
                        'type'     => "enum('draft','published')",
                        'nullable' => 'NO',
                        'default'  => "'draft'",
                        'extra'    => '',
                        'comment'  => '',
                    ],
                ],
            ]
        );

        $structure->rename_column('status', 'state');

        $this->assertSame(
            'ALTER TABLE `wp_posts` '
            . "CHANGE COLUMN `status` `state` enum('draft','published') not null default 'draft'",
            $structure->get_table_structure()
        );
    }

    public function test_alter_rename_quotes_an_unquoted_string_default(): void
    {
        $structure = $this->make_alter_structure(
            'posts',
            [
                'prefix'  => 'wp_',
                'results' => [
                    [
                        'name'     => 'status',
                        'type'     => 'varchar(20)',
                        'nullable' => 'NO',
                        'default'  => 'draft',
                        'extra'    => '',
                        'comment'  => '',
                    ],
                ],
            ]
        );

        $structure->rename_column('status', 'state');

        $this->assertSame(
            'ALTER TABLE `wp_posts` '
            . "CHANGE COLUMN `status` `state` varchar(20) not null default 'draft'",
            $structure->get_table_structure()
        );
    }

    public function test_alter_rename_keeps_a_numeric_default_unquoted(): void
    {
        $structure = $this->make_alter_structure(
            'posts',
            [
                'prefix'  => 'wp_',
                'results' => [
                    [
                        'name'     => 'views',
                        'type'     => 'int(10) unsigned',
                        'nullable' => 'NO',
                        'default'  => '0',
                        'extra'    => '',
                        'comment'  => '',
                    ],
                ],
            ]
        );

        $structure->rename_column('views', 'view_count');

        $this->assertSame(
            'ALTER TABLE `wp_posts` '
            . 'CHANGE COLUMN `views` `view_count` int(10) unsigned not null default 0',
            $structure->get_table_structure()
        );
    }

    public function test_alter_rename_preserves_auto_increment(): void
    {
        $structure = $this->make_alter_structure(
            'posts',
            [
                'prefix'  => 'wp_',
                'results' => [
                    [
                        'name'     => 'id',
                        'type'     => 'bigint(20) unsigned',
                        'nullable' => 'NO',
                        'default'  => null,
                        'extra'    => 'auto_increment',
                        'comment'  => '',
                    ],
                ],
            ]
        );

        $structure->rename_column('id', 'post_id');

        $this->assertSame(
            'ALTER TABLE `wp_posts` '
            . 'CHANGE COLUMN `id` `post_id` bigint(20) unsigned not null auto_increment',
            $structure->get_table_structure()
        );
    }

    public function test_alter_rename_throws_when_the_column_is_missing(): void
    {
        $structure = $this->make_alter_structure('posts', ['prefix' => 'wp_', 'results' => []]);
        $structure->rename_column('missing', 'renamed');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Column [missing] does not exist on table [posts]');

        $structure->get_table_structure();
    }

    public function test_alter_throws_when_no_changes_are_defined(): void
    {
        $structure = $this->make_alter_structure('posts', ['prefix' => 'wp_']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No changes were defined for table [posts]');

        $structure->get_table_structure();
    }

    public function test_alter_verbs_are_rejected_while_creating(): void
    {
        $structure = $this->make_structure('posts', ['prefix' => 'wp_']);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('only available when altering an existing table');

        $structure->drop_column('title');
    }

    public function test_column_level_unique_is_promoted_on_alter(): void
    {
        $structure = $this->make_alter_structure('posts', ['prefix' => 'wp_']);
        $structure->string('slug')->unique();

        $this->assertSame(
            'ALTER TABLE `wp_posts` '
            . 'ADD COLUMN `slug` varchar(255) not null, '
            . 'ADD UNIQUE KEY `posts_slug_unique` (`slug`)',
            $structure->get_table_structure()
        );
    }

    public function test_datetimes_adds_created_at_and_updated_at_datetime_columns(): void
    {
        $structure = $this->make_alter_structure('posts', ['prefix' => 'wp_']);
        $structure->datetimes();

        $this->assertSame(
            'ALTER TABLE `wp_posts` '
            . 'ADD COLUMN `created_at` datetime not null default CURRENT_TIMESTAMP, '
            . 'ADD COLUMN `updated_at` datetime null',
            $structure->get_table_structure()
        );
    }

    public function test_datetimes_applies_precision_to_both_columns(): void
    {
        $structure = $this->make_alter_structure('posts', ['prefix' => 'wp_']);
        $structure->datetimes(6);

        $this->assertSame(
            'ALTER TABLE `wp_posts` '
            . 'ADD COLUMN `created_at` datetime(6) not null default CURRENT_TIMESTAMP(6), '
            . 'ADD COLUMN `updated_at` datetime(6) null',
            $structure->get_table_structure()
        );
    }

    public function test_alter_preserves_use_current_default_handling(): void
    {
        $structure = $this->make_alter_structure('posts', ['prefix' => 'wp_']);
        $structure->timestamp('published_at')->use_current();

        $this->assertSame(
            'ALTER TABLE `wp_posts` '
            . 'ADD COLUMN `published_at` timestamp not null default CURRENT_TIMESTAMP',
            $structure->get_table_structure()
        );
    }
}
