<?php

namespace Example\App\Services;

use Framework\Validation\Rule;
use Framework\Validation\Validator;

/**
 * Builds a large product payload and its rule set, to exercise the validator
 * with thousands of wildcard-expanded keys.
 */
class ValidationStressService
{
    public const ATTRIBUTES_PER_VARIANT = 3;

    public function rules(): array
    {
        return [
            // Product level.
            'product' => 'required|array',
            'product.title' => 'required|string|min:3|max:120',
            'product.slug' => 'required|string|alpha_dash|max:120',
            'product.status' => 'required|in:draft,published,archived',
            'product.type' => 'required|in:simple,variable',
            'product.description' => 'nullable|string|max:5000',
            'product.vendor_email' => 'required|string|email',
            'product.website' => 'nullable|string|url',
            'product.published_at' => 'nullable|date',
            'product.featured' => 'required|boolean',
            'product.tax_rate' => 'required|numeric|min:0|max:100',
            'product.tags' => 'nullable|array|max:20',
            'product.tags.*' => 'required|string|max:30',

            // Variants.
            'variants' => 'required|array|min:1',
            'variants.*.sku' => 'required|string|alpha_dash|max:50',
            'variants.*.name' => 'required|string|min:2|max:100',
            'variants.*.price' => 'required|numeric|min:0',
            'variants.*.sale_price' => 'nullable|numeric|min:0',
            'variants.*.cost' => 'nullable|numeric|min:0',
            'variants.*.stock' => 'required|integer|min:0',
            'variants.*.low_stock_threshold' => 'nullable|integer|min:0',
            'variants.*.weight' => 'nullable|numeric|min:0',
            'variants.*.length' => 'nullable|numeric|min:0',
            'variants.*.width' => 'nullable|numeric|min:0',
            'variants.*.height' => 'nullable|numeric|min:0',
            'variants.*.color' => 'required|string|max:30',
            'variants.*.size' => 'required|in:xs,s,m,l,xl,xxl',
            'variants.*.material' => 'nullable|string|max:50',
            'variants.*.barcode' => 'nullable|string|alpha_num|max:20',
            'variants.*.is_active' => 'required|boolean',
            'variants.*.is_default' => 'required|boolean',
            'variants.*.backorder' => 'required|in:no,notify,yes',
            'variants.*.position' => 'required|integer|min:0',
            'variants.*.image_url' => 'nullable|string|url',
            'variants.*.available_from' => 'nullable|date',
            'variants.*.shipping_class' => 'nullable|string|max:50',
            'variants.*.tax_class' => 'nullable|string|max:50',
            'variants.*.note' => 'nullable|string|max:255',
            'variants.*.digital' => [
                Rule::when(
                    fn ($data) => ($data['product']['type'] ?? null) === 'variable',
                    'required|boolean',
                    'nullable|boolean'
                ),
            ],
            'variants.*.discount_percent' => [
                'nullable',
                function ($value, $key) {
                    return $value === null || ($value >= 0 && $value <= 90)
                        ? true
                        : "The {$key} must be between 0 and 90.";
                },
            ],

            // Nested wildcard: every variant carries a list of attributes.
            'variants.*.attributes' => 'required|array|min:1',
            'variants.*.attributes.*.name' => 'required|string|max:50',
            'variants.*.attributes.*.value' => 'required|string|max:100',
        ];
    }

    public function payload(int $variants, bool $invalid = false): array
    {
        $sizes = ['xs', 's', 'm', 'l', 'xl', 'xxl'];
        $items = [];

        for ($i = 0; $i < $variants; $i++) {
            $attributes = [];

            for ($a = 0; $a < static::ATTRIBUTES_PER_VARIANT; $a++) {
                $attributes[] = ['name' => "attribute-{$a}", 'value' => "value-{$i}-{$a}"];
            }

            $items[] = [
                'sku' => "SKU-{$i}",
                'name' => "Variant {$i}",
                'price' => 19.99 + $i,
                'sale_price' => 17.99 + $i,
                'cost' => 9.5,
                'stock' => 100 + $i,
                'low_stock_threshold' => 5,
                'weight' => 0.4,
                'length' => 30,
                'width' => 20,
                'height' => 2,
                'color' => 'black',
                'size' => $sizes[$i % count($sizes)],
                'material' => 'cotton',
                'barcode' => "BC{$i}",
                'is_active' => true,
                'is_default' => $i === 0,
                'backorder' => 'notify',
                'position' => $i,
                'image_url' => "https://example.test/images/{$i}.jpg",
                'available_from' => '2026-11-13',
                'shipping_class' => 'standard',
                'tax_class' => 'reduced',
                'note' => null,
                'digital' => false,
                'discount_percent' => 10,
                'attributes' => $attributes,
            ];
        }

        // One broken variant in every ten, each breaking a different kind of rule.
        if ($invalid) {
            foreach ($items as $i => &$item) {
                if ($i % 10 !== 0) {
                    continue;
                }

                $item['sku'] = 'bad sku!';
                $item['price'] = -5;
                $item['stock'] = 'many';
                $item['size'] = 'huge';
                $item['image_url'] = 'not-a-url';
                $item['discount_percent'] = 95;
                $item['attributes'][0]['value'] = '';
            }
            unset($item);
        }

        return [
            'product' => [
                'title' => 'Stress Test Tee',
                'slug' => 'stress-test-tee',
                'status' => 'published',
                'type' => 'variable',
                'description' => 'A product with a very large number of variants.',
                'vendor_email' => 'vendor@example.test',
                'website' => 'https://example.test',
                'published_at' => '2026-11-13',
                'featured' => true,
                'tax_rate' => 7.5,
                'tags' => ['apparel', 'cotton', 'stress-test'],
            ],
            'variants' => $items,
        ];
    }

    /**
     * Validate the payload and report timings, key counts and errors.
     */
    public function run(int $variants, bool $invalid = false): array
    {
        $rules = $this->rules();
        $data = $this->payload($variants, $invalid);

        $started = microtime(true);
        $validator = Validator::make($data, $rules);
        $parsed = microtime(true);
        $passes = $validator->passes();
        $checked = microtime(true);
        $validated = $passes ? $validator->validated() : null;
        $finished = microtime(true);

        $errors = $validator->errors();
        $errors_by_field = [];

        foreach (array_keys($errors) as $key) {
            $field = preg_replace('/\.\d+(?=\.|$)/', '.*', $key);
            $errors_by_field[$field] = ($errors_by_field[$field] ?? 0) + 1;
        }

        return [
            'variants' => $variants,
            'invalid' => $invalid,
            'rule_entries' => count($rules),
            'expanded_keys' => count($validator->get_rules()),
            'passes' => $passes,
            'error_keys' => count($errors),
            'errors_by_field' => $errors_by_field,
            'sample_errors' => array_slice($errors, 0, 10, true),
            'validated_variants' => $validated === null ? null : count($validated['variants'] ?? []),
            'timings_ms' => [
                'parse' => round(($parsed - $started) * 1000, 2),
                'passes' => round(($checked - $parsed) * 1000, 2),
                'validated' => round(($finished - $checked) * 1000, 2),
                'total' => round(($finished - $started) * 1000, 2),
            ],
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ];
    }
}
