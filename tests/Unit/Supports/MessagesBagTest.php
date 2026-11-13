<?php

namespace Framework\Tests\Unit\Supports;

use Framework\Supports\MessagesBag;
use Framework\Tests\Unit\TestCase;

class MessagesBagTest extends TestCase
{
    protected function bag_with(array $app_messages): MessagesBag
    {
        return new class ($app_messages) extends MessagesBag {
            protected array $app_messages;

            public function __construct(array $app_messages)
            {
                $this->app_messages = $app_messages;

                parent::__construct();
            }

            protected function app_defined_messages()
            {
                return $this->app_messages;
            }
        };
    }

    public function test_overriding_one_rule_message_keeps_the_other_rule_defaults(): void
    {
        $bag = $this->bag_with(['validator' => ['required' => ['default' => 'Please fill in {name}.']]]);

        $this->assertSame('Please fill in {name}.', $bag->get('validator.required.default'));
        $this->assertSame('The {name} field must be a string.', $bag->get('validator.string.default'));
        $this->assertSame('Validation failed!', $bag->get('validator.failed'));
    }

    public function test_overriding_one_constraint_message_keeps_its_siblings(): void
    {
        $bag = $this->bag_with(['validator' => ['string' => ['max' => 'Too long.']]]);

        $this->assertSame('Too long.', $bag->get('validator.string.max'));
        $this->assertSame('The {name} field must be at least {min} characters long.', $bag->get('validator.string.min'));
    }

    public function test_app_messages_can_add_new_keys(): void
    {
        $bag = $this->bag_with([
            'validator' => ['string' => ['slug' => 'Not a slug.']],
            'shop' => ['out_of_stock' => '%s is out of stock.'],
        ]);

        $this->assertSame('Not a slug.', $bag->get('validator.string.slug'));
        $this->assertSame('Tee is out of stock.', $bag->get('shop.out_of_stock', 'Tee'));
        $this->assertSame('You have to be logged in.', $bag->get('auth.logged_in_required'));
    }

    public function test_without_app_messages_the_defaults_are_used(): void
    {
        $bag = $this->bag_with([]);

        $this->assertSame('The {name} field is required.', $bag->get('validator.required.default'));
    }
}
