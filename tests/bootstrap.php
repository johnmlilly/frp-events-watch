<?php
/**
 * Loads the plugin without WordPress. Brain Monkey defines the hook functions and a few helpers
 * (untrailingslashit, is_wp_error); everything else WordPress provides is stubbed per test in
 * FrpTestCase.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
Brain\Monkey\setUp();

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('YEAR_IN_SECONDS', 31536000);

class WP_Error {
    private $message;
    public function __construct($code = '', $message = '') {
        $this->message = $message;
    }
    public function get_error_message() {
        return $this->message;
    }
}

function register_deactivation_hook($file, $callback) {
}

// Small pure helpers, same behavior as WordPress's.
function wp_parse_url($url, $component = -1) {
    return parse_url($url, $component);
}
function wp_make_link_relative($link) {
    return preg_replace('|^(https?:)?//[^/]+(/?.*)|i', '$2', $link);
}

require __DIR__ . '/FrpTestCase.php';
require dirname(__DIR__) . '/wordpress/frp-events-watch.php';
