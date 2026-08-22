<?php

if (!defined('ABSPATH')) {
    exit;
}

final class MRC_Moon_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_post_mrc_moon_install_wasm', [$this, 'handle_install_wasm']);
        add_action('admin_post_mrc_moon_install_cities', [$this, 'handle_install_cities']);
        add_action('admin_notices', [$this, 'render_dependency_notice']);
    }

    public function add_settings_page(): void {
        add_options_page(
            __('Celestial Signs Calculator', 'moonrise-moon-sign'),
            __('Celestial Signs Calculator', 'moonrise-moon-sign'),
            'manage_options',
            'mrc-moon-sign',
            [$this, 'render_settings_page']
        );
    }

    public function handle_install_wasm(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'moonrise-moon-sign'));
        }

        check_admin_referer('mrc_moon_install_wasm');
        $result = MRC_Moon_Wasm_Installer::install();

        $args = ['page' => 'mrc-moon-sign'];
        if (is_wp_error($result)) {
            update_option('mrc_moon_wasm_install_error', $result->get_error_message(), false);
            $args['mrc_wasm_install'] = 'error';
        } else {
            delete_option('mrc_moon_wasm_install_error');
            $args['mrc_wasm_install'] = 'success';
        }

        wp_safe_redirect(add_query_arg($args, admin_url('options-general.php')));
        exit;
    }

    public function handle_install_cities(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'moonrise-moon-sign'));
        }

        check_admin_referer('mrc_moon_install_cities');
        $result = MRC_Moon_Geocoder::install();

        $args = ['page' => 'mrc-moon-sign'];
        if (is_wp_error($result)) {
            update_option('mrc_moon_city_install_error', $result->get_error_message(), false);
            $args['mrc_city_install'] = 'error';
        } else {
            delete_option('mrc_moon_city_install_error');
            $args['mrc_city_install'] = 'success';
        }

        wp_safe_redirect(add_query_arg($args, admin_url('options-general.php')));
        exit;
    }

    public function render_dependency_notice(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $wasm_ok = MRC_Moon_Wasm_Installer::is_installed();
        $cities_ok = MRC_Moon_Geocoder::is_installed();
        if ($wasm_ok && $cities_ok) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && $screen->id === 'settings_page_mrc-moon-sign') {
            return;
        }

        $url = admin_url('options-general.php?page=mrc-moon-sign');
        echo '<div class="notice notice-warning"><p>';
        echo wp_kses_post(sprintf(
            __('Celestial Signs Calculator v6 still needs one or more local runtime assets. <a href="%s">Open the calculator settings</a> to install/repair them.', 'moonrise-moon-sign'),
            esc_url($url)
        ));
        echo '</p></div>';
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $wasm = MRC_Moon_Wasm_Installer::get_status();
        $cities = MRC_Moon_Geocoder::get_status();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Celestial Signs Calculator', 'moonrise-moon-sign'); ?></h1>
            <p><strong><?php esc_html_e('Version 6.0.0', 'moonrise-moon-sign'); ?></strong> — <?php esc_html_e('Calculates tropical zodiac positions for the Sun, Moon, Rising/Ascendant, Mercury, Venus, Mars, Jupiter, Saturn, Uranus, Neptune, and Pluto. Location lookup remains local, including latitude/longitude, and Swiss Ephemeris runs as WebAssembly in the visitor’s browser.', 'moonrise-moon-sign'); ?></p>

            <?php if (isset($_GET['mrc_wasm_install']) && $_GET['mrc_wasm_install'] === 'success') : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Swiss Ephemeris WebAssembly runtime installed successfully.', 'moonrise-moon-sign'); ?></p></div>
            <?php elseif (isset($_GET['mrc_wasm_install']) && $_GET['mrc_wasm_install'] === 'error') : ?>
                <div class="notice notice-error"><p><?php echo esc_html((string) ($wasm['error'] ?? __('The WebAssembly runtime could not be installed.', 'moonrise-moon-sign'))); ?></p></div>
            <?php endif; ?>

            <?php if (isset($_GET['mrc_city_install']) && $_GET['mrc_city_install'] === 'success') : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Local city/timezone database installed successfully.', 'moonrise-moon-sign'); ?></p></div>
            <?php elseif (isset($_GET['mrc_city_install']) && $_GET['mrc_city_install'] === 'error') : ?>
                <div class="notice notice-error"><p><?php echo esc_html((string) ($cities['error'] ?? __('The local city/timezone database could not be installed.', 'moonrise-moon-sign'))); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e('Location Database', 'moonrise-moon-sign'); ?></h2>
            <p><?php esc_html_e('The city database is downloaded once during installation, compiled into local search files with IANA timezone plus latitude/longitude, and then queried entirely from this WordPress site. Visitor searches do not call a third-party geocoding API.', 'moonrise-moon-sign'); ?></p>
            <table class="widefat striped" style="max-width:980px">
                <tbody>
                    <tr>
                        <td style="width:220px"><strong><?php esc_html_e('Search mode', 'moonrise-moon-sign'); ?></strong></td>
                        <td><?php esc_html_e('Local city → IANA timezone + coordinates database', 'moonrise-moon-sign'); ?></td>
                        <td><?php echo !empty($cities['installed']) ? '<strong style="color:#18752c">OK</strong>' : '<strong style="color:#b32d2e">Incomplete</strong>'; ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Locations', 'moonrise-moon-sign'); ?></strong></td>
                        <td><?php echo esc_html(number_format_i18n((int) ($cities['count'] ?? 0))); ?></td>
                        <td><code><?php echo esc_html((string) ($cities['version'] ?? '')); ?></code></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Database directory', 'moonrise-moon-sign'); ?></strong></td>
                        <td colspan="2"><code><?php echo esc_html((string) ($cities['directory'] ?? '')); ?></code></td>
                    </tr>
                </tbody>
            </table>

            <p>
                <a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=mrc_moon_install_cities'), 'mrc_moon_install_cities')); ?>">
                    <?php esc_html_e('Install / Repair Local City Database', 'moonrise-moon-sign'); ?>
                </a>
            </p>

            <h2><?php esc_html_e('Swiss Ephemeris Runtime', 'moonrise-moon-sign'); ?></h2>
            <table class="widefat striped" style="max-width:980px">
                <tbody>
                    <tr>
                        <td style="width:220px"><strong><?php esc_html_e('Calculation mode', 'moonrise-moon-sign'); ?></strong></td>
                        <td><?php esc_html_e('Swiss Ephemeris WebAssembly (browser)', 'moonrise-moon-sign'); ?></td>
                        <td><strong style="color:#18752c"><?php esc_html_e('Kinsta-safe', 'moonrise-moon-sign'); ?></strong></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Runtime directory', 'moonrise-moon-sign'); ?></strong></td>
                        <td><code><?php echo esc_html((string) ($wasm['runtime_dir'] ?? '')); ?></code></td>
                        <td><?php echo !empty($wasm['installed']) ? '<strong style="color:#18752c">OK</strong>' : '<strong style="color:#b32d2e">Incomplete</strong>'; ?></td>
                    </tr>
                    <?php foreach (($wasm['files'] ?? []) as $file => $file_status) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($file); ?></strong></td>
                            <td><?php echo esc_html(size_format((int) ($file_status['size'] ?? 0), 2)); ?></td>
                            <td><?php echo !empty($file_status['valid']) ? '<strong style="color:#18752c">OK</strong>' : '<strong style="color:#b32d2e">Missing / invalid</strong>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td><strong><?php esc_html_e('Browser reference test', 'moonrise-moon-sign'); ?></strong></td>
                        <td>
                            <button type="button" class="button" id="mrc-moon-browser-test" <?php disabled(empty($wasm['installed'])); ?>>
                                <?php esc_html_e('Test 10 Bodies + Rising', 'moonrise-moon-sign'); ?>
                            </button>
                            <span id="mrc-moon-browser-test-result" style="margin-left:8px"></span>
                        </td>
                        <td><?php esc_html_e('Moon ref + Honolulu Rising ref', 'moonrise-moon-sign'); ?></td>
                    </tr>
                </tbody>
            </table>

            <p>
                <a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=mrc_moon_install_wasm'), 'mrc_moon_install_wasm')); ?>">
                    <?php esc_html_e('Install / Repair Swiss Ephemeris WASM', 'moonrise-moon-sign'); ?>
                </a>
            </p>

            <?php if (!empty($wasm['installed'])) : ?>
                <script>
                (() => {
                    const button = document.getElementById('mrc-moon-browser-test');
                    const result = document.getElementById('mrc-moon-browser-test-result');
                    const wrapperUrl = <?php echo wp_json_encode(MRC_Moon_Wasm_Installer::get_wrapper_url()); ?>;
                    if (!button || !result || !wrapperUrl) return;

                    button.addEventListener('click', async () => {
                        button.disabled = true;
                        result.textContent = 'Running…';
                        result.style.color = '';

                        try {
                            const module = await import(wrapperUrl);
                            const swe = new module.default();
                            await swe.initSwissEph();
                            const jd = swe.julday(1995, 7, 15, 20.5);
                            const bodies = [
                                ['Sun', swe.SE_SUN],
                                ['Moon', swe.SE_MOON],
                                ['Mercury', swe.SE_MERCURY],
                                ['Venus', swe.SE_VENUS],
                                ['Mars', swe.SE_MARS],
                                ['Jupiter', swe.SE_JUPITER],
                                ['Saturn', swe.SE_SATURN],
                                ['Uranus', swe.SE_URANUS],
                                ['Neptune', swe.SE_NEPTUNE],
                                ['Pluto', swe.SE_PLUTO],
                            ];

                            let moonLongitude = null;
                            for (const [name, id] of bodies) {
                                const position = swe.calc_ut(jd, id, swe.SEFLG_SWIEPH);
                                const longitude = Number(position?.[0]);
                                if (!Number.isFinite(longitude)) {
                                    throw new Error(`No ${name} longitude returned.`);
                                }
                                if (name === 'Moon') {
                                    moonLongitude = ((longitude % 360) + 360) % 360;
                                }
                            }

                            const moonOk = Number.isFinite(moonLongitude) && Math.abs(moonLongitude - 339.2803) < 0.1;
                            const moonSign = Math.floor(moonLongitude / 30) === 11 ? 'Pisces' : 'Unexpected sign';

                            // Client-provided Rising Sign reference case:
                            // 1982-08-19 3:42 PM Honolulu = 1982-08-20 01:42 UTC.
                            const risingJd = swe.julday(1982, 8, 20, 1.7);
                            const houses = swe.houses(risingJd, 21.307, -157.858, 'P');
                            const risingLongitude = Number(houses?.ascmc?.[0]);
                            const risingOk = Number.isFinite(risingLongitude) && Math.abs(risingLongitude - 275.5380) < 0.1;
                            const risingSign = Number.isFinite(risingLongitude) && Math.floor(risingLongitude / 30) === 9 ? 'Capricorn' : 'Unexpected sign';

                            const allOk = moonOk && risingOk;
                            result.textContent = `10 bodies OK — Moon ${moonLongitude.toFixed(4)}° ${moonSign}; Rising ${risingLongitude.toFixed(4)}° ${risingSign}${allOk ? ' — OK' : ' — CHECK'}`;
                            result.style.color = allOk ? '#18752c' : '#b32d2e';
                        } catch (error) {
                            result.textContent = `Failed: ${error?.message || error}`;
                            result.style.color = '#b32d2e';
                        } finally {
                            button.disabled = false;
                        }
                    });
                })();
                </script>
            <?php endif; ?>

            <h2><?php esc_html_e('Shortcodes', 'moonrise-moon-sign'); ?></h2>
            <p><code>[moon_sign_calculator]</code> <?php esc_html_e('(existing shortcode; remains fully supported)', 'moonrise-moon-sign'); ?></p>
            <p><code>[celestial_sign_calculator]</code> <?php esc_html_e('(optional alias for new pages)', 'moonrise-moon-sign'); ?></p>
        </div>
        <?php
    }
}
