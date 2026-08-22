<?php

if (!defined('ABSPATH')) {
    exit;
}

final class MRC_Moon_Time {
    public function prepare(string $date, string $time, string $timezone): array|WP_Error {
        $date = trim($date);
        $time = trim($time);
        $timezone = trim($timezone);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new WP_Error('invalid_date', __('Please enter a valid birth date.', 'moonrise-moon-sign'));
        }

        if (!preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $time)) {
            return new WP_Error('invalid_time', __('Please enter a valid birth time.', 'moonrise-moon-sign'));
        }

        [$year, $month, $day] = array_map('intval', explode('-', $date));
        $current_year = (int) gmdate('Y');

        if ($year < 1800 || $year > $current_year || !checkdate($month, $day, $year)) {
            return new WP_Error('date_range', sprintf(__('Birth year must be between 1800 and %d.', 'moonrise-moon-sign'), $current_year));
        }

        try {
            $zone = new DateTimeZone($timezone);
        } catch (Exception) {
            return new WP_Error('invalid_timezone', __('The selected birth location has an invalid timezone. Please choose the location again.', 'moonrise-moon-sign'));
        }

        $input_time = strlen($time) === 5 ? $time . ':00' : $time;
        $local = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $input_time, $zone);
        $errors = DateTimeImmutable::getLastErrors();

        if (!$local || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return new WP_Error('invalid_local_datetime', __('Please enter a valid local birth date and time.', 'moonrise-moon-sign'));
        }

        // PHP normalizes nonexistent wall-clock times during a DST spring-forward gap.
        // Reject that normalization rather than silently calculating a different time.
        if ($local->format('Y-m-d H:i:s') !== $date . ' ' . $input_time) {
            return new WP_Error(
                'nonexistent_local_time',
                __('That local time did not exist because of a daylight-saving time change. Please verify the birth time.', 'moonrise-moon-sign')
            );
        }

        $utc = $local->setTimezone(new DateTimeZone('UTC'));

        return [
            'utc_iso'       => $utc->format('Y-m-d\TH:i:s\Z'),
            'utc_display'   => $utc->format('F j, Y H:i') . ' UTC',
            'local_display' => $local->format('F j, Y g:i A T'),
            'timezone'      => $timezone,
            'utc_offset'    => $local->format('P'),
        ];
    }
}
