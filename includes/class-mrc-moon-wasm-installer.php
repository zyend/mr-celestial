<?php

if (!defined('ABSPATH')) {
    exit;
}

final class MRC_Moon_Wasm_Installer {
    private const VERSION = 'v0.1.0';
    private const SOURCE_BASES = [
        'https://cdn.jsdelivr.net/gh/prolaxu/swisseph-wasm@v0.1.0/',
        'https://raw.githubusercontent.com/prolaxu/swisseph-wasm/refs/tags/v0.1.0/',
    ];

    /**
     * Runtime files are installed into uploads so plugin updates do not erase them.
     */
    private const FILES = [
        'src/swisseph.js'  => 40000,
        'wasm/swisseph.js' => 40000,
        'wasm/swisseph.wasm' => 350000,
        'wasm/swisseph.data' => 1500000,
    ];

    public static function activate(): void {
        $result = self::install();
        if (is_wp_error($result)) {
            update_option('mrc_moon_wasm_install_error', $result->get_error_message(), false);
            return;
        }

        delete_option('mrc_moon_wasm_install_error');
        update_option('mrc_moon_wasm_version', self::VERSION, false);
    }

    public static function install(): true|WP_Error {
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $paths = self::paths();
        if (is_wp_error($paths)) {
            return $paths;
        }

        foreach (array_keys(self::FILES) as $relative) {
            $destination = trailingslashit($paths['dir']) . $relative;
            $directory = dirname($destination);

            if (!wp_mkdir_p($directory)) {
                return new WP_Error(
                    'mrc_wasm_directory',
                    sprintf(__('Could not create the Swiss Ephemeris runtime directory: %s', 'moonrise-moon-sign'), $directory)
                );
            }

            $temp = self::download_runtime_file($relative);
            if (is_wp_error($temp)) {
                return $temp;
            }

            $validation = self::validate_file($temp, $relative);
            if (is_wp_error($validation)) {
                @unlink($temp);
                return $validation;
            }

            if (!@rename($temp, $destination)) {
                if (!@copy($temp, $destination)) {
                    @unlink($temp);
                    return new WP_Error(
                        'mrc_wasm_move',
                        sprintf(__('Could not install the Swiss Ephemeris runtime file: %s', 'moonrise-moon-sign'), $relative)
                    );
                }
                @unlink($temp);
            }

            @chmod($destination, 0644);
        }

        // Prevent directory indexes without blocking public runtime assets.
        foreach ([$paths['dir'], $paths['dir'] . '/src', $paths['dir'] . '/wasm'] as $directory) {
            $index = trailingslashit($directory) . 'index.php';
            if (!file_exists($index)) {
                @file_put_contents($index, "<?php\n// Silence is golden.\n");
            }
        }

        update_option('mrc_moon_wasm_version', self::VERSION, false);
        delete_option('mrc_moon_wasm_install_error');

        return true;
    }

    public static function is_installed(): bool {
        $paths = self::paths();
        if (is_wp_error($paths)) {
            return false;
        }

        foreach (self::FILES as $relative => $minimum_size) {
            $file = trailingslashit($paths['dir']) . $relative;
            if (!is_file($file) || !is_readable($file) || filesize($file) < $minimum_size) {
                return false;
            }
        }

        return true;
    }

    public static function get_wrapper_url(): string {
        $paths = self::paths();
        if (is_wp_error($paths)) {
            return '';
        }

        return trailingslashit($paths['url']) . 'src/swisseph.js';
    }

    public static function get_status(): array {
        $paths = self::paths();
        if (is_wp_error($paths)) {
            return [
                'runtime_dir' => '',
                'runtime_url' => '',
                'files'       => [],
                'installed'   => false,
                'error'       => $paths->get_error_message(),
            ];
        }

        $files = [];
        foreach (self::FILES as $relative => $minimum_size) {
            $file = trailingslashit($paths['dir']) . $relative;
            $exists = is_file($file) && is_readable($file);
            $size = $exists ? (int) filesize($file) : 0;
            $files[$relative] = [
                'exists' => $exists,
                'size'   => $size,
                'valid'  => $exists && $size >= $minimum_size,
            ];
        }

        return [
            'runtime_dir' => $paths['dir'],
            'runtime_url' => $paths['url'],
            'files'       => $files,
            'installed'   => self::is_installed(),
            'version'     => (string) get_option('mrc_moon_wasm_version', ''),
            'error'       => (string) get_option('mrc_moon_wasm_install_error', ''),
        ];
    }


    private static function download_runtime_file(string $relative): string|WP_Error {
        $last_error = '';

        foreach (self::SOURCE_BASES as $base) {
            $temp = download_url($base . $relative, 30);
            if (!is_wp_error($temp)) {
                return $temp;
            }
            $last_error = $temp->get_error_message();
        }

        return new WP_Error(
            'mrc_wasm_download',
            sprintf(
                __('Could not download %1$s: %2$s', 'moonrise-moon-sign'),
                $relative,
                $last_error !== '' ? $last_error : __('unknown download error', 'moonrise-moon-sign')
            )
        );
    }

    private static function paths(): array|WP_Error {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return new WP_Error('mrc_uploads', (string) $uploads['error']);
        }

        return [
            'dir' => trailingslashit($uploads['basedir']) . 'moonrise-moon-sign/wasm-runtime',
            'url' => trailingslashit($uploads['baseurl']) . 'moonrise-moon-sign/wasm-runtime',
        ];
    }

    private static function validate_file(string $file, string $relative): true|WP_Error {
        $minimum_size = self::FILES[$relative] ?? 1;
        $size = is_file($file) ? (int) filesize($file) : 0;

        if ($size < $minimum_size) {
            return new WP_Error(
                'mrc_wasm_invalid_size',
                sprintf(__('Downloaded Swiss Ephemeris file appears incomplete: %s', 'moonrise-moon-sign'), $relative)
            );
        }

        if (str_ends_with($relative, '.wasm')) {
            $handle = @fopen($file, 'rb');
            $magic = $handle ? fread($handle, 4) : false;
            if ($handle) {
                fclose($handle);
            }
            if ($magic !== "\x00asm") {
                return new WP_Error('mrc_wasm_invalid_binary', __('The downloaded WebAssembly module is invalid.', 'moonrise-moon-sign'));
            }
        }

        if ($relative === 'src/swisseph.js') {
            $sample = (string) @file_get_contents($file, false, null, 0, 8192);
            if (!str_contains($sample, 'class SwissEph') || !str_contains($sample, "../wasm/swisseph.js")) {
                return new WP_Error('mrc_wasm_invalid_wrapper', __('The downloaded Swiss Ephemeris JavaScript wrapper is invalid.', 'moonrise-moon-sign'));
            }
        }

        return true;
    }
}
