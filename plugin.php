<?php
/**
 * Plugin Name: Random Redirect Manager
 * Plugin URI: https://github.com/lammersbjorn/YOURLS-Random-Redirect-Manager
 * Description: Redirects predefined keywords to a random URL from a list with customizable chance percentages. Creates shortlinks automatically.
 * Version: 3.2
 * Author: Bjorn Lammers
 * Author URI: https://github.com/lammersbjorn
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Requires at least: YOURLS 1.7.3
 * Tested up to: YOURLS 1.10.3
 * Requires PHP: 7.4
 * Tested up to PHP: 8.5
 */

if (!defined('YOURLS_ABSPATH')) {
    die();
}

require_once __DIR__ . '/includes/bootstrap.php';
