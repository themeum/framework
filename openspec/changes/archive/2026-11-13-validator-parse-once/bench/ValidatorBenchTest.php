<?php
namespace Framework\Tests\Unit\Validation;

use Framework\Tests\Unit\TestCase;
use Framework\Validation\RuleFactory;
use Framework\Validation\RuleParser;
use Framework\Validation\Validator;
use Framework\Sanitizer;

class ValidatorBenchTest extends TestCase
{
    public function test_bench(): void
    {
        $fields = [];
        $base = ['required|numeric|min:0', 'required|string|max:50', 'nullable|string|max:255', 'required|integer|min:1'];
        for ($f = 0; $f < 26; $f++) { $fields["f$f"] = $base[$f % 4]; }
        $rules = ['variants' => 'required|array'];
        foreach ($fields as $name => $r) { $rules["variants.*.$name"] = $r; }
        $variant = [];
        foreach ($fields as $name => $r) { $variant[$name] = str_contains($r, 'string') ? 'abc' : 5; }
        $data = ['variants' => array_fill(0, 180, $variant)];

        $t = microtime(true);
        $v = Validator::make($data, $rules);
        $parse = microtime(true) - $t;
        $t = microtime(true); $v->passes(); $passes = microtime(true) - $t;
        $t = microtime(true); $v->validated(); $validated = microtime(true) - $t;

        // Isolate: RuleFactory::make per expanded key vs once per raw rule
        $t = microtime(true);
        foreach ($v->get_rules() as $k => $_) { $raw = $rules[preg_replace('/\.\d+\./', '.*.', $k)]; RuleFactory::make(explode('|', $raw)); }
        $per_key = microtime(true) - $t;
        $t = microtime(true);
        foreach ($rules as $raw) { RuleFactory::make(explode('|', $raw)); }
        $per_rule = microtime(true) - $t;

        $t = microtime(true); (new RuleParser($data, $rules))->explode_wildcards(); $explode = microtime(true) - $t;

        $srules = []; foreach ($fields as $name => $r) { $srules["variants.*.$name"] = 'text'; }
        $t = microtime(true); Sanitizer::make($data, $srules); $san = microtime(true) - $t;

        fwrite(STDERR, sprintf("\nkeys=%d\nValidator::make (parse) %.1f ms\n  of which explode_wildcards %.1f ms\npasses() %.1f ms\nvalidated() %.1f ms\nRuleFactory per expanded key %.1f ms\nRuleFactory once per raw rule %.2f ms\nSanitizer 4680 keys %.1f ms\n",
            count($v->get_rules()), $parse*1e3, $explode*1e3, $passes*1e3, $validated*1e3, $per_key*1e3, $per_rule*1e3, $san*1e3));
        $t = microtime(true); for ($i = 0; $i < 4681 * 3; $i++) { \Framework\Validation\Rule::string(); } fwrite(STDERR, sprintf("new rule x14043 (constructor+message lookups) %.1f ms\n", (microtime(true)-$t)*1e3)); $this->assertTrue(true);
    }
}
