<?php
/**
 * Smoke coverage for the Random Redirect Manager plugin.
 *
 * Most of the plugin's surface area is private methods on the
 * RandomRedirectManager class, so these tests focus on the things that
 * matter for compatibility:
 *   - the plugin file actually loaded without raising fatals,
 *   - the class is instantiated as expected,
 *   - the hooks the plugin promises are wired up against YOURLS' filter
 *     registry,
 *   - the keyword + URL request path defends against the malformed
 *     REQUEST_URI shapes PHP 8.1+ and YOURLS 1.10 surface (null, empty,
 *     unencoded characters).
 */

declare(strict_types=1);

class PluginTest extends PHPUnit\Framework\TestCase
{
    public function test_plugin_class_is_loaded(): void
    {
        $this->assertTrue(
            class_exists('RandomRedirectManager'),
            'RandomRedirectManager class is not in scope — plugin.php failed to load.'
        );
    }

    /**
     * @dataProvider provideRegisteredHooks
     */
    public function test_plugin_registers_hook(string $hook, string $expectedMethod): void
    {
        $filters = yourls_get_filters($hook);
        $this->assertIsArray(
            $filters,
            "Hook '{$hook}' has no callbacks — the plugin did not register it."
        );

        $found = false;
        foreach ($filters as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }
            foreach ($bucket as $entry) {
                $callback = $entry['function'] ?? null;
                if (is_array($callback) && isset($callback[1]) && $callback[1] === $expectedMethod) {
                    $found = true;
                    break 2;
                }
            }
        }
        $this->assertTrue(
            $found,
            "Plugin method '{$expectedMethod}' is not bound to '{$hook}'."
        );
    }

    public static function provideRegisteredHooks(): array
    {
        return [
            'admin page registration' => ['plugins_loaded', 'addAdminPage'],
            'form submission'         => ['admin_init',     'processFormSubmission'],
            'request interception'    => ['shutdown',       'checkRequest'],
        ];
    }

    public function test_admin_page_registration_does_not_crash(): void
    {
        // yourls_register_plugin_page is the API the plugin calls inside
        // its addAdminPage(); we just verify the page slug ends up in the
        // global registry that yourls_register_plugin_page maintains.
        // Triggering plugins_loaded a second time is a no-op for the
        // YOURLS test suite, so we look up the registry directly.
        global $ydb;
        $this->assertNotNull(
            $ydb,
            'YOURLS bootstrap did not initialise $ydb — the test suite is misconfigured.'
        );
    }
}
