<?php

declare(strict_types=1);

final class RandomRedirectManagerTest extends PHPUnit\Framework\TestCase
{
    private const OPTION_NAME = 'random_redirect_settings';

    public function test_plugin_bootstrap_structure_files_exist(): void
    {
        $root = dirname(__DIR__);

        $this->assertFileExists($root . '/plugin.php');
        $this->assertFileExists($root . '/includes/bootstrap.php');
        $this->assertFileExists($root . '/includes/class-random-redirect-manager.php');
        $this->assertFileExists($root . '/assets/admin.css');
        $this->assertFileExists($root . '/assets/admin.js');
    }

    public function test_plugin_class_is_loaded(): void
    {
        $this->assertTrue(class_exists('RandomRedirectManager'));
    }

    /**
     * @dataProvider registeredHooksProvider
     */
    public function test_plugin_registers_expected_hooks(string $hook, string $method): void
    {
        $this->assertHookHasPluginMethod($hook, $method);
    }

    public static function registeredHooksProvider(): array
    {
        return [
            'admin page registration' => ['plugins_loaded', 'addAdminPage'],
            'form submission' => ['load-random_redirect_settings', 'processFormSubmission'],
            'request interception' => ['shutdown', 'checkRequest'],
        ];
    }

    public function test_settings_are_loaded_as_array(): void
    {
        $settings = $this->readPrivateProperty($this->pluginInstance(), 'settings');

        $this->assertIsArray($settings);
    }

    /**
     * @dataProvider keywordProvider
     */
    public function test_keyword_sanitizer_matches_form_pattern($input, string $expected): void
    {
        $method = $this->privateMethod('sanitizeKeyword');

        $this->assertSame($expected, $method->invoke($this->pluginInstance(), $input));
    }

    public static function keywordProvider(): array
    {
        return [
            'simple' => ['promo', 'promo'],
            'nested' => ['/folder//promo/', 'folder/promo'],
            'underscore and hyphen' => ['sale_2026-test', 'sale_2026-test'],
            'colon rejected' => ['foo:bar', ''],
            'array rejected' => [['promo'], ''],
        ];
    }

    public function test_url_sanitizer_rejects_invalid_and_reindexes(): void
    {
        $method = $this->privateMethod('sanitizeUrlArray');

        $this->assertSame(
            ['https://example.com/a', 'https://example.com/b'],
            $method->invoke($this->pluginInstance(), [
                'https://example.com/a',
                'not a url',
                '',
                'https://example.com/b',
            ])
        );
    }

    public function test_chance_sanitizer_rejects_negative_and_invalid_values(): void
    {
        $method = $this->privateMethod('sanitizeChanceArray');

        $this->assertSame([25.5, 0.0, 0.0, 0.0], $method->invoke($this->pluginInstance(), [
            '25.5',
            '-1',
            'nope',
            '',
        ]));
    }

    public function test_same_site_shortlink_resolver_leaves_unmatched_urls_unchanged(): void
    {
        $method = $this->privateMethod('resolveYourlsShortlink');

        $this->assertSame(
            'https://example.com/outside',
            $method->invoke($this->pluginInstance(), 'https://example.com/outside')
        );
    }

    public function test_weighted_random_url_returns_one_configured_url(): void
    {
        $method = $this->privateMethod('getWeightedRandomUrl');
        $urls = ['https://example.com/a', 'https://example.com/b'];

        $this->assertContains($method->invoke($this->pluginInstance(), $urls, [0, 0]), $urls);
        $this->assertSame('https://example.com/b', $method->invoke($this->pluginInstance(), $urls, [0, 100]));
    }

    private function assertHookHasPluginMethod(string $hook, string $method): void
    {
        $filters = yourls_get_filters($hook);
        $this->assertIsArray($filters, "Hook {$hook} is not registered.");

        foreach ($filters as $priorityBucket) {
            if (!is_array($priorityBucket)) {
                continue;
            }
            foreach ($priorityBucket as $entry) {
                $callback = $entry['function'] ?? null;
                if (is_array($callback) && ($callback[0] ?? null) instanceof RandomRedirectManager && ($callback[1] ?? null) === $method) {
                    $this->assertSame(1, $entry['accepted_args'] ?? null);
                    return;
                }
            }
        }

        $this->fail("RandomRedirectManager::{$method} is not registered on {$hook}.");
    }

    private function pluginInstance(): RandomRedirectManager
    {
        $filters = yourls_get_filters('shutdown');
        $this->assertIsArray($filters);

        foreach ($filters as $priorityBucket) {
            if (!is_array($priorityBucket)) {
                continue;
            }
            foreach ($priorityBucket as $entry) {
                $callback = $entry['function'] ?? null;
                if (is_array($callback) && ($callback[0] ?? null) instanceof RandomRedirectManager) {
                    return $callback[0];
                }
            }
        }

        $this->fail('RandomRedirectManager instance was not found in YOURLS hooks.');
    }

    private function privateMethod(string $method): ReflectionMethod
    {
        $reflectionMethod = new ReflectionMethod('RandomRedirectManager', $method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod;
    }

    private function readPrivateProperty(RandomRedirectManager $plugin, string $property)
    {
        $reflectionProperty = new ReflectionProperty($plugin, $property);
        $reflectionProperty->setAccessible(true);

        return $reflectionProperty->getValue($plugin);
    }
}
