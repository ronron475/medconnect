<?php
/**
 * Provider date/time formatting based on session preferences.
 */

function provider_format_time(?string $time, ?string $timeFormat = null): string
{
    if ($time === null || trim($time) === '') {
        return '';
    }

    $format = $timeFormat ?? ($_SESSION['provider_time_format'] ?? '12h');
    $ts = strtotime($time);
    if ($ts === false) {
        return $time;
    }

    return $format === '24h'
        ? date('H:i', $ts)
        : date('g:i A', $ts);
}

function provider_format_date(?string $date, ?string $dateFormat = null): string
{
    if ($date === null || trim($date) === '') {
        return '';
    }

    $format = $dateFormat ?? ($_SESSION['provider_date_format'] ?? 'M j, Y');
    $ts = strtotime($date);
    if ($ts === false) {
        return $date;
    }

    return date($format, $ts);
}

function provider_format_datetime(?string $datetime, ?string $dateFormat = null, ?string $timeFormat = null): string
{
    if ($datetime === null || trim($datetime) === '') {
        return '';
    }

    $date = provider_format_date($datetime, $dateFormat);
    $time = provider_format_time($datetime, $timeFormat);

    return trim($date . ($time !== '' ? ', ' . $time : ''));
}
