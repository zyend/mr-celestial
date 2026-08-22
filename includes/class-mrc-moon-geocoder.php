<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Local city -> IANA timezone + latitude/longitude lookup.
 *
 * The source dataset is downloaded once and compiled into small first-letter
 * shards under wp-content/uploads. Front-end searches never call an external
 * geocoding API.
 */
final class MRC_Moon_Geocoder {
    private const DATA_VERSION = 'city-timezones-1.3.3-coordinates-v2';
    private const SOURCE_URLS = [
        'https://cdn.jsdelivr.net/npm/city-timezones@1.3.3/data/cityMap.json',
        'https://unpkg.com/city-timezones@1.3.3/data/cityMap.json',
    ];
    private const MIN_SOURCE_SIZE = 1500000;

    public static function activate(): void {
        if (self::is_installed()) {
            return;
        }

        $result = self::install();
        if (is_wp_error($result)) {
            update_option('mrc_moon_city_install_error', $result->get_error_message(), false);
            return;
        }

        delete_option('mrc_moon_city_install_error');
    }

    public static function install(): true|WP_Error {
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $paths = self::paths();
        if (is_wp_error($paths)) {
            return $paths;
        }

        if (!wp_mkdir_p($paths['dir'])) {
            return new WP_Error(
                'mrc_city_directory',
                sprintf(__('Could not create the local city database directory: %s', 'moonrise-moon-sign'), $paths['dir'])
            );
        }

        $temp = self::download_source();
        if (is_wp_error($temp)) {
            return $temp;
        }

        $size = is_file($temp) ? (int) filesize($temp) : 0;
        if ($size < self::MIN_SOURCE_SIZE) {
            @unlink($temp);
            return new WP_Error('mrc_city_source_small', __('The downloaded city database appears incomplete.', 'moonrise-moon-sign'));
        }

        $raw = @file_get_contents($temp);
        @unlink($temp);
        if ($raw === false) {
            return new WP_Error('mrc_city_read', __('Could not read the downloaded city database.', 'moonrise-moon-sign'));
        }

        $rows = json_decode($raw, true);
        unset($raw);
        if (!is_array($rows)) {
            return new WP_Error('mrc_city_json', __('The downloaded city database is not valid JSON.', 'moonrise-moon-sign'));
        }

        $valid_timezones = array_fill_keys(timezone_identifiers_list(), true);
        $shards = [];
        $count = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $city = trim((string) ($row['city'] ?? ''));
            $ascii = trim((string) ($row['city_ascii'] ?? $city));
            $timezone = trim((string) ($row['timezone'] ?? ''));
            $latitude = isset($row['lat']) && is_numeric($row['lat']) ? (float) $row['lat'] : null;
            $longitude = isset($row['lng']) && is_numeric($row['lng']) ? (float) $row['lng'] : null;

            if (
                $city === ''
                || $timezone === ''
                || !isset($valid_timezones[$timezone])
                || $latitude === null
                || $longitude === null
                || $latitude < -90
                || $latitude > 90
                || $longitude < -180
                || $longitude > 180
            ) {
                continue;
            }

            $normalized = self::normalize($ascii !== '' ? $ascii : $city);
            if ($normalized === '') {
                continue;
            }

            $shard = preg_match('/^[a-z0-9]/', $normalized) ? $normalized[0] : '_';
            $shards[$shard][] = [
                'c'   => $city,
                'a'   => $ascii,
                'p'   => trim((string) ($row['province'] ?? '')),
                's'   => trim((string) ($row['state_ansi'] ?? '')),
                'n'   => trim((string) ($row['country'] ?? '')),
                'i'   => strtoupper(trim((string) ($row['iso2'] ?? ''))),
                't'   => $timezone,
                'lat' => $latitude,
                'lng' => $longitude,
                'pop' => (int) round((float) ($row['pop'] ?? 0)),
            ];
            $count++;
        }
        unset($rows);

        if ($count < 5000) {
            return new WP_Error('mrc_city_count', __('The city database did not contain enough valid locations.', 'moonrise-moon-sign'));
        }

        foreach ($shards as $key => &$items) {
            usort($items, static function (array $a, array $b): int {
                return ($b['pop'] ?? 0) <=> ($a['pop'] ?? 0);
            });

            $json = wp_json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json) || @file_put_contents($paths['dir'] . '/' . $key . '.json', $json, LOCK_EX) === false) {
                return new WP_Error(
                    'mrc_city_write',
                    sprintf(__('Could not write local city database shard: %s', 'moonrise-moon-sign'), $key)
                );
            }
        }
        unset($items);

        $manifest = [
            'version'      => self::DATA_VERSION,
            'count'        => $count,
            'generated_at' => gmdate('c'),
            'shards'       => array_values(array_keys($shards)),
        ];

        if (@file_put_contents(
            $paths['manifest'],
            (string) wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        ) === false) {
            return new WP_Error('mrc_city_manifest', __('Could not write the local city database manifest.', 'moonrise-moon-sign'));
        }

        $index = $paths['dir'] . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        update_option('mrc_moon_city_version', self::DATA_VERSION, false);
        delete_option('mrc_moon_city_install_error');

        return true;
    }

    public static function is_installed(): bool {
        $status = self::get_status();
        return !empty($status['installed']);
    }

    public static function get_status(): array {
        $paths = self::paths();
        if (is_wp_error($paths)) {
            return [
                'installed' => false,
                'directory' => '',
                'version'   => '',
                'count'     => 0,
                'error'     => $paths->get_error_message(),
            ];
        }

        $manifest = self::read_manifest($paths['manifest']);
        $installed = is_array($manifest)
            && ($manifest['version'] ?? '') === self::DATA_VERSION
            && (int) ($manifest['count'] ?? 0) >= 5000
            && is_file($paths['dir'] . '/c.json')
            && is_readable($paths['dir'] . '/c.json');

        return [
            'installed' => $installed,
            'directory' => $paths['dir'],
            'version'   => is_array($manifest) ? (string) ($manifest['version'] ?? '') : '',
            'count'     => is_array($manifest) ? (int) ($manifest['count'] ?? 0) : 0,
            'error'     => (string) get_option('mrc_moon_city_install_error', ''),
        ];
    }

    public function search(string $query): array|WP_Error {
        $query = trim(wp_strip_all_tags($query));
        if (mb_strlen($query) < 2) {
            return [];
        }

        if (!self::is_installed()) {
            return new WP_Error(
                'city_database_missing',
                __('The local birth-location database is not installed yet.', 'moonrise-moon-sign')
            );
        }

        $normalized = self::normalize($query);
        if ($normalized === '') {
            return [];
        }

        $first_token = strtok($normalized, ' ') ?: $normalized;
        $shard = preg_match('/^[a-z0-9]/', $first_token) ? $first_token[0] : '_';

        $paths = self::paths();
        if (is_wp_error($paths)) {
            return $paths;
        }

        $file = $paths['dir'] . '/' . $shard . '.json';
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }

        $raw = @file_get_contents($file);
        $rows = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($rows)) {
            return new WP_Error('city_database_read', __('The local birth-location database could not be read.', 'moonrise-moon-sign'));
        }

        $tokens = array_values(array_filter(explode(' ', $normalized)));
        $matches = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $city = (string) ($row['c'] ?? '');
            $ascii = (string) ($row['a'] ?? $city);
            $province = (string) ($row['p'] ?? '');
            $state = (string) ($row['s'] ?? '');
            $country = (string) ($row['n'] ?? '');
            $iso2 = (string) ($row['i'] ?? '');
            $timezone = (string) ($row['t'] ?? '');
            $latitude = isset($row['lat']) ? (float) $row['lat'] : null;
            $longitude = isset($row['lng']) ? (float) $row['lng'] : null;

            if ($latitude === null || $longitude === null) {
                continue;
            }

            $city_norm = self::normalize($ascii !== '' ? $ascii : $city);
            $haystack = trim(implode(' ', array_filter([
                $city_norm,
                self::normalize($province),
                self::normalize($state),
                self::normalize($country),
                strtolower($iso2),
            ])));

            $all_tokens_match = true;
            foreach ($tokens as $token) {
                if (!str_contains($haystack, $token)) {
                    $all_tokens_match = false;
                    break;
                }
            }
            if (!$all_tokens_match) {
                continue;
            }

            $score = 0;
            if ($city_norm === $normalized) {
                $score += 10000;
            } elseif (str_starts_with($city_norm, $normalized)) {
                $score += 8000;
            } elseif (str_starts_with($city_norm, $first_token)) {
                $score += 6000;
            } elseif (str_contains($city_norm, $first_token)) {
                $score += 3000;
            }

            $score += min(2000, (int) floor(log10(max(1, (int) ($row['pop'] ?? 0))) * 250));

            $label_parts = array_values(array_unique(array_filter([$city, $province, $country])));
            $label = implode(', ', $label_parts);

            $matches[] = [
                'score'    => $score,
                'pop'      => (int) ($row['pop'] ?? 0),
                'id'       => md5($label . '|' . $timezone),
                'label'     => $label,
                'timezone'  => $timezone,
                'latitude'  => $latitude,
                'longitude' => $longitude,
            ];
        }

        usort($matches, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return $b['pop'] <=> $a['pop'];
        });

        $results = [];
        $seen = [];
        foreach ($matches as $match) {
            $dedupe = $match['label'] . '|' . $match['timezone'];
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            unset($match['score'], $match['pop']);
            $results[] = $match;
            if (count($results) >= 8) {
                break;
            }
        }

        return $results;
    }

    private static function download_source(): string|WP_Error {
        $last_error = '';

        foreach (self::SOURCE_URLS as $url) {
            $temp = download_url($url, 45);
            if (!is_wp_error($temp)) {
                return $temp;
            }
            $last_error = $temp->get_error_message();
        }

        return new WP_Error(
            'mrc_city_download',
            sprintf(
                __('Could not download the local city/timezone database: %s', 'moonrise-moon-sign'),
                $last_error !== '' ? $last_error : __('unknown download error', 'moonrise-moon-sign')
            )
        );
    }

    private static function paths(): array|WP_Error {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return new WP_Error('mrc_city_uploads', (string) $uploads['error']);
        }

        $dir = trailingslashit($uploads['basedir']) . 'moonrise-moon-sign/city-database';
        return [
            'dir'      => $dir,
            'manifest' => $dir . '/manifest.json',
        ];
    }

    private static function read_manifest(string $file): ?array {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if (!is_string($raw)) {
            return null;
        }

        $manifest = json_decode($raw, true);
        return is_array($manifest) ? $manifest : null;
    }

    private static function normalize(string $value): string {
        $value = remove_accents(wp_strip_all_tags($value));
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
