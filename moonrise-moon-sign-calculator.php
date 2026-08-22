<?php
/**
 * Plugin Name: Moonrise Celestial Signs Calculator
 * Description: Calculates tropical zodiac signs for the Sun, Moon, Rising/Ascendant, and major planets from birth date, time, and location using Swiss Ephemeris WebAssembly and a local city/timezone/coordinates database.
 * Version: 1.0.6
 * Author: Moonrise Crystals
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Text Domain: moonrise-moon-sign
 */

if (!defined('ABSPATH')) {
    exit;
}

define('MRC_MOON_VERSION', '1.0.6');
define('MRC_MOON_FILE', __FILE__);
define('MRC_MOON_DIR', plugin_dir_path(__FILE__));
define('MRC_MOON_URL', plugin_dir_url(__FILE__));

require_once MRC_MOON_DIR . 'includes/class-mrc-moon-wasm-installer.php';
require_once MRC_MOON_DIR . 'includes/class-mrc-moon-time.php';
require_once MRC_MOON_DIR . 'includes/class-mrc-moon-geocoder.php';
require_once MRC_MOON_DIR . 'includes/class-mrc-moon-admin.php';
require_once MRC_MOON_DIR . 'includes/class-mrc-moon-plugin.php';

register_activation_hook(MRC_MOON_FILE, static function (): void {
    MRC_Moon_Wasm_Installer::activate();
    MRC_Moon_Geocoder::activate();
});

MRC_Moon_Plugin::instance();
