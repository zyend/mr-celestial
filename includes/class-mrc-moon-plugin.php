<?php

if (!defined('ABSPATH')) {
    exit;
}

final class MRC_Moon_Plugin {
    private static ?self $instance = null;
    private MRC_Moon_Time $time_service;
    private MRC_Moon_Geocoder $geocoder;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->time_service = new MRC_Moon_Time();
        $this->geocoder = new MRC_Moon_Geocoder();

        // Keep the original shortcode so existing pages continue to work.
        add_shortcode('moon_sign_calculator', [$this, 'render_shortcode']);
        // Clearer alias for new placements.
        add_shortcode('celestial_sign_calculator', [$this, 'render_shortcode']);

        add_action('wp_ajax_mrc_moon_location_search', [$this, 'ajax_location_search']);
        add_action('wp_ajax_nopriv_mrc_moon_location_search', [$this, 'ajax_location_search']);
        add_action('wp_ajax_mrc_moon_prepare_time', [$this, 'ajax_prepare_time']);
        add_action('wp_ajax_nopriv_mrc_moon_prepare_time', [$this, 'ajax_prepare_time']);

        if (is_admin()) {
            new MRC_Moon_Admin();
        }
    }

    public function render_shortcode(array|string $atts = []): string {
        $atts = shortcode_atts([
            'title'       => 'Find Your Celestial Signs',
            'button_text' => 'Calculate My Celestial Signs',
        ], is_array($atts) ? $atts : [], 'moon_sign_calculator');

        wp_enqueue_style(
            'mrc-moon-sign',
            MRC_MOON_URL . 'assets/css/moon-sign.css',
            [],
            MRC_MOON_VERSION
        );

        wp_enqueue_script(
            'mrc-moon-sign',
            MRC_MOON_URL . 'assets/js/moon-sign.js',
            [],
            MRC_MOON_VERSION,
            true
        );

        wp_localize_script('mrc-moon-sign', 'mrcMoonSign', [
            'ajaxUrl'         => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('mrc_moon_sign'),
            'wasmInstalled'   => MRC_Moon_Wasm_Installer::is_installed(),
            'citiesInstalled' => MRC_Moon_Geocoder::is_installed(),
            'wasmWrapperUrl'  => MRC_Moon_Wasm_Installer::get_wrapper_url(),
            'settingsUrl'     => current_user_can('manage_options') ? admin_url('options-general.php?page=mrc-moon-sign') : '',
            'signs'           => $this->signs(),
            'bodies'          => $this->celestial_bodies(),
            'i18n'            => [
                'locationRequired' => __('Please choose a birth location from the suggestions.', 'moonrise-moon-sign'),
                'searching'        => __('Searching…', 'moonrise-moon-sign'),
                'noLocations'      => __('No matching locations found.', 'moonrise-moon-sign'),
                'locationError'    => __('The local birth-location search is temporarily unavailable.', 'moonrise-moon-sign'),
                'calculating'      => __('Calculating…', 'moonrise-moon-sign'),
                'loadingEngine'    => __('Loading Swiss Ephemeris…', 'moonrise-moon-sign'),
                'engineError'      => __('The Celestial Signs calculator engine could not load. Please try again.', 'moonrise-moon-sign'),
                'setupRequired'    => __('The Celestial Signs calculator is not fully configured yet.', 'moonrise-moon-sign'),
                'genericError'     => __('Something went wrong. Please try again.', 'moonrise-moon-sign'),
            ],
        ]);

        $instance_id = 'mrc-moon-' . wp_unique_id();
        $current_year = (int) gmdate('Y');

        ob_start();
        ?>
        <div class="mrc-moon-sign" id="<?php echo esc_attr($instance_id); ?>">
            <?php if (trim((string) $atts['title']) !== '') : ?>
                <h2 class="mrc-moon-sign__title"><?php echo esc_html((string) $atts['title']); ?></h2>
            <?php endif; ?>

            <form class="mrc-moon-sign__form" novalidate>
                <div class="mrc-moon-sign__field">
                    <label for="<?php echo esc_attr($instance_id); ?>-date"><?php esc_html_e('Birth Date', 'moonrise-moon-sign'); ?></label>
                    <input
                        id="<?php echo esc_attr($instance_id); ?>-date"
                        name="birth_date"
                        type="date"
                        min="1800-01-01"
                        max="<?php echo esc_attr($current_year . '-12-31'); ?>"
                        required
                    >
                </div>

                <div class="mrc-moon-sign__field">
                    <label for="<?php echo esc_attr($instance_id); ?>-time"><?php esc_html_e('Birth Time', 'moonrise-moon-sign'); ?></label>
                    <input
                        id="<?php echo esc_attr($instance_id); ?>-time"
                        name="birth_time"
                        type="time"
                        step="60"
                        required
                    >
                    <small><?php esc_html_e('Use the exact local time shown on the birth record when possible.', 'moonrise-moon-sign'); ?></small>
                </div>

                <div class="mrc-moon-sign__field mrc-moon-sign__location-field">
                    <label for="<?php echo esc_attr($instance_id); ?>-location"><?php esc_html_e('Birth Location', 'moonrise-moon-sign'); ?></label>
                    <input
                        id="<?php echo esc_attr($instance_id); ?>-location"
                        class="mrc-moon-sign__location"
                        name="birth_location"
                        type="text"
                        placeholder="<?php esc_attr_e('City, State/Region, Country', 'moonrise-moon-sign'); ?>"
                        autocomplete="off"
                        aria-autocomplete="list"
                        aria-expanded="false"
                        required
                    >
                    <input class="mrc-moon-sign__timezone" name="birth_timezone" type="hidden" value="">
                    <input class="mrc-moon-sign__latitude" name="birth_latitude" type="hidden" value="">
                    <input class="mrc-moon-sign__longitude" name="birth_longitude" type="hidden" value="">
                    <div class="mrc-moon-sign__suggestions" role="listbox" hidden></div>
                    <small><?php esc_html_e('Start typing a city, then choose the correct location.', 'moonrise-moon-sign'); ?></small>
                </div>

                <div class="mrc-moon-sign__error" role="alert" hidden></div>

                <button class="mrc-moon-sign__submit" type="submit">
                    <?php echo esc_html((string) $atts['button_text']); ?>
                </button>
            </form>

            <div class="mrc-moon-sign__result" aria-live="polite" hidden></div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function ajax_location_search(): void {
        $this->verify_nonce();

        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
        if (mb_strlen($query) < 2) {
            wp_send_json_success(['results' => []]);
        }

        if (!$this->allow_request('location', 90, MINUTE_IN_SECONDS)) {
            wp_send_json_error(['message' => __('Too many location searches. Please wait a moment and try again.', 'moonrise-moon-sign')], 429);
        }

        $results = $this->geocoder->search($query);
        if (is_wp_error($results)) {
            wp_send_json_error(['message' => $results->get_error_message()], 503);
        }

        wp_send_json_success(['results' => $results]);
    }

    public function ajax_prepare_time(): void {
        $this->verify_nonce();

        if (!$this->allow_request('calculate', 30, MINUTE_IN_SECONDS)) {
            wp_send_json_error(['message' => __('Too many calculations. Please wait a moment and try again.', 'moonrise-moon-sign')], 429);
        }

        $date = isset($_POST['date']) ? sanitize_text_field(wp_unslash($_POST['date'])) : '';
        $time = isset($_POST['time']) ? sanitize_text_field(wp_unslash($_POST['time'])) : '';
        $timezone = isset($_POST['timezone']) ? sanitize_text_field(wp_unslash($_POST['timezone'])) : '';
        $location = isset($_POST['location']) ? sanitize_text_field(wp_unslash($_POST['location'])) : '';
        $latitude_raw = isset($_POST['latitude']) ? sanitize_text_field(wp_unslash($_POST['latitude'])) : '';
        $longitude_raw = isset($_POST['longitude']) ? sanitize_text_field(wp_unslash($_POST['longitude'])) : '';

        if ($date === '' || $time === '' || $timezone === '' || $location === '' || $latitude_raw === '' || $longitude_raw === '') {
            wp_send_json_error(['message' => __('Please complete all birth information fields.', 'moonrise-moon-sign')], 400);
        }

        if (!is_numeric($latitude_raw) || !is_numeric($longitude_raw)) {
            wp_send_json_error(['message' => __('The selected birth location is missing valid coordinates.', 'moonrise-moon-sign')], 400);
        }

        $latitude = (float) $latitude_raw;
        $longitude = (float) $longitude_raw;
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            wp_send_json_error(['message' => __('The selected birth location has invalid coordinates.', 'moonrise-moon-sign')], 400);
        }

        $result = $this->time_service->prepare($date, $time, $timezone);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success([
            'utcIso'       => $result['utc_iso'],
            'utcDisplay'   => $result['utc_display'],
            'localDisplay' => $result['local_display'],
            'utcOffset'    => $result['utc_offset'],
            'location'     => $location,
            'timezone'     => $timezone,
            'latitude'     => $latitude,
            'longitude'    => $longitude,
        ]);
    }

    private function signs(): array {
        $data = [
            ['name' => 'Aries',       'glyph' => '♈', 'slug' => 'aries-healing-crystals'],
            ['name' => 'Taurus',      'glyph' => '♉', 'slug' => 'taurus-healing-crystals'],
            ['name' => 'Gemini',      'glyph' => '♊', 'slug' => 'gemini-healing-crystals'],
            ['name' => 'Cancer',      'glyph' => '♋', 'slug' => 'cancer-healing-crystals'],
            ['name' => 'Leo',         'glyph' => '♌', 'slug' => 'leo-healing-crystals'],
            ['name' => 'Virgo',       'glyph' => '♍', 'slug' => 'virgo-healing-crystals'],
            ['name' => 'Libra',       'glyph' => '♎', 'slug' => 'libra-healing-crystals'],
            ['name' => 'Scorpio',     'glyph' => '♏', 'slug' => 'scorpio-healing-crystals'],
            ['name' => 'Sagittarius', 'glyph' => '♐', 'slug' => 'sagittarius-healing-crystals'],
            ['name' => 'Capricorn',   'glyph' => '♑', 'slug' => 'capricorn-healing-crystals'],
            ['name' => 'Aquarius',    'glyph' => '♒', 'slug' => 'aquarius-healing-crystals'],
            ['name' => 'Pisces',      'glyph' => '♓', 'slug' => 'pisces-healing-crystals'],
        ];

        return array_map(static function (array $sign): array {
            $sign['url'] = home_url('/zodiac/' . $sign['slug'] . '/');
            unset($sign['slug']);
            return $sign;
        }, $data);
    }

    private function celestial_bodies(): array {
        return [
            ['name' => 'Sun',     'sentence_name' => 'The Sun',        'constant' => 'SE_SUN',     'symbol' => '☉', 'type' => 'planet'],
            ['name' => 'Moon',    'sentence_name' => 'The Moon',       'constant' => 'SE_MOON',    'symbol' => '☽', 'type' => 'planet'],
            ['name' => 'Rising',  'sentence_name' => 'Your Ascendant', 'constant' => '',           'symbol' => 'ASC', 'type' => 'ascendant'],
            ['name' => 'Mercury', 'sentence_name' => 'Mercury',        'constant' => 'SE_MERCURY', 'symbol' => '☿', 'type' => 'planet'],
            ['name' => 'Venus',   'sentence_name' => 'Venus',    'constant' => 'SE_VENUS',   'symbol' => '♀', 'type' => 'planet'],
            ['name' => 'Mars',    'sentence_name' => 'Mars',     'constant' => 'SE_MARS',    'symbol' => '♂', 'type' => 'planet'],
            ['name' => 'Jupiter', 'sentence_name' => 'Jupiter',  'constant' => 'SE_JUPITER', 'symbol' => '♃', 'type' => 'planet'],
            ['name' => 'Saturn',  'sentence_name' => 'Saturn',   'constant' => 'SE_SATURN',  'symbol' => '♄', 'type' => 'planet'],
            ['name' => 'Uranus',  'sentence_name' => 'Uranus',   'constant' => 'SE_URANUS',  'symbol' => '♅', 'type' => 'planet'],
            ['name' => 'Neptune', 'sentence_name' => 'Neptune',  'constant' => 'SE_NEPTUNE', 'symbol' => '♆', 'type' => 'planet'],
            ['name' => 'Pluto',      'sentence_name' => 'Pluto',               'constant' => 'SE_PLUTO',     'symbol' => '♇',  'type' => 'planet'],
            ['name' => 'North Node', 'sentence_name' => 'The True North Node', 'constant' => 'SE_TRUE_NODE', 'symbol' => '☊ᵀ', 'type' => 'true_node'],
            ['name' => 'South Node', 'sentence_name' => 'The South Node',      'constant' => '',             'symbol' => '☋',  'type' => 'south_node'],
        ];
    }

    private function verify_nonce(): void {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'mrc_moon_sign')) {
            wp_send_json_error(['message' => __('Your session expired. Please refresh the page and try again.', 'moonrise-moon-sign')], 403);
        }
    }

    private function allow_request(string $action, int $limit, int $window): bool {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = 'mrc_moon_rl_' . md5($action . '|' . $ip);
        $count = (int) get_transient($key);

        if ($count >= $limit) {
            return false;
        }

        set_transient($key, $count + 1, $window);
        return true;
    }
}
