<?php

declare(strict_types=1);

if (!defined('YOURLS_ABSPATH')) {
    die();
}

if (!defined('RRM_PLUGIN_DIR')) {
    define('RRM_PLUGIN_DIR', __DIR__ . '/../');
}

require_once __DIR__ . '/class-random-redirect-manager.php';

new RandomRedirectManager();
