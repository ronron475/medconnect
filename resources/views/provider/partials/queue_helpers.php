<?php
/**
 * Schedule-based access rules for provider consultation sessions.
 */

function queue_normalize_date(?string $value): string
{
    if ($value === null || trim((string) $value) === '') {
        return '';
    }

    $value = trim((string) $value);
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $matches)) {
        return $matches[1];
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d', $timestamp) : '';
}

function queue_normalize_status(string $status): string
{
    $status = strtolower(trim($status));
    $status = str_replace(' ', '_', $status);

    return match ($status) {
        'waiting' => 'pending',
        default => $status,
    };
}

function queue_scheduled_label(?string $consult_date, ?string $consult_time = null): string
{
    $consult_date = queue_normalize_date($consult_date);
    if ($consult_date === '') {
        return 'No scheduled date';
    }

    $label = date('l, F j, Y', strtotime($consult_date));
    if ($consult_time) {
        $label .= ' at ' . date('g:i A', strtotime((string) $consult_time));
    }

    return $label;
}

/**
 * @return int|null Unix timestamp for scheduled start
 */
function queue_scheduled_timestamp(?string $consult_date, ?string $consult_time = null): ?int
{
    $date = queue_normalize_date($consult_date);
    if ($date === '') {
        return null;
    }

    $time = trim((string) ($consult_time ?? ''));
    if ($time === '') {
        $time = '00:00:00';
    } elseif (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
        $time .= ':00';
    }

    $timestamp = strtotime($date . ' ' . $time);
    return $timestamp ?: null;
}

/**
 * @return int|null Unix timestamp for scheduled end
 */
function queue_scheduled_end_timestamp(?string $consult_date, ?string $consult_time = null, ?string $slot_end = null): ?int
{
    $date = queue_normalize_date($consult_date);
    if ($date === '') {
        return null;
    }

    $end = trim((string) ($slot_end ?? ''));
    if ($end !== '') {
        if (preg_match('/^\d{1,2}:\d{2}$/', $end)) {
            $end .= ':00';
        }
        $timestamp = strtotime($date . ' ' . $end);
        return $timestamp ?: null;
    }

    $start = queue_scheduled_timestamp($consult_date, $consult_time);
    return $start ? $start + (30 * 60) : null;
}

/**
 * @return array{status:string,slot_date:string,consult_date:string,effective_date:string,consult_time:string,scheduled_label:string,scheduled_start:int|null,opens_at_label:string}
 */
function queue_session_context(array $item): array
{
    $status         = queue_normalize_status((string) ($item['status'] ?? 'pending'));
    $slot_date      = queue_normalize_date($item['slot_date'] ?? null);
    $consult_date   = queue_normalize_date($item['consult_date'] ?? null);
    $effective_date = $slot_date !== '' ? $slot_date : $consult_date;
    $consult_time   = (string) ($item['slot_start'] ?? $item['consult_time'] ?? '');
    $scheduled      = queue_scheduled_label(
        $effective_date !== '' ? $effective_date : $consult_date,
        $consult_time
    );
    $scheduled_start = queue_scheduled_timestamp(
        $effective_date !== '' ? $effective_date : $consult_date,
        $consult_time
    );

    return [
        'status'          => $status,
        'slot_date'       => $slot_date,
        'consult_date'    => $consult_date,
        'effective_date'  => $effective_date,
        'consult_time'    => $consult_time,
        'scheduled_label' => $scheduled,
        'scheduled_start' => $scheduled_start,
        'opens_at_label'  => $scheduled_start ? date('g:i A', $scheduled_start) : '',
    ];
}

function queue_is_before_scheduled_start(array $ctx): bool
{
    if (empty($ctx['scheduled_start'])) {
        return false;
    }

    return time() < (int) $ctx['scheduled_start'];
}

function queue_before_start_reason(array $ctx): string
{
    $opens = $ctx['opens_at_label'] !== '' ? $ctx['opens_at_label'] : 'the scheduled time';

    return 'This session opens at ' . $opens . '. Please wait until the scheduled time.';
}

/**
 * Whether a provider may open the consultation session.
 *
 * Sessions open on the scheduled calendar day once the scheduled start time
 * is reached. Active in-consultation sessions can always be resumed.
 *
 * @return array{allowed:bool, reason:string, scheduled_label:string}
 */
function queue_session_access(array $item): array
{
    $ctx = queue_session_context($item);

    if ($ctx['status'] === 'in_consultation') {
        return [
            'allowed'         => true,
            'reason'          => '',
            'scheduled_label' => $ctx['scheduled_label'],
        ];
    }

    if (in_array($ctx['status'], ['completed', 'cancelled'], true)) {
        return [
            'allowed'         => false,
            'reason'          => 'This consultation is already ' . ucwords(str_replace('_', ' ', $ctx['status'])) . '.',
            'scheduled_label' => $ctx['scheduled_label'],
        ];
    }

    if (in_array($ctx['status'], ['scheduled', 'pending'], true)) {
        $today = date('Y-m-d');

        if ($ctx['effective_date'] === '') {
            return [
                'allowed'         => true,
                'reason'          => '',
                'scheduled_label' => $ctx['scheduled_label'],
            ];
        }

        if ($ctx['effective_date'] > $today) {
            return [
                'allowed'         => false,
                'reason'          => 'This session is scheduled for ' . $ctx['scheduled_label'] . '. You can open it on the scheduled date.',
                'scheduled_label' => $ctx['scheduled_label'],
            ];
        }

        if ($ctx['effective_date'] < $today) {
            return [
                'allowed'         => false,
                'reason'          => 'This session was scheduled for ' . $ctx['scheduled_label'] . '. The scheduled date has already passed.',
                'scheduled_label' => $ctx['scheduled_label'],
            ];
        }

        if (queue_is_before_scheduled_start($ctx)) {
            return [
                'allowed'         => false,
                'reason'          => queue_before_start_reason($ctx),
                'scheduled_label' => $ctx['scheduled_label'],
            ];
        }

        return [
            'allowed'         => true,
            'reason'          => '',
            'scheduled_label' => $ctx['scheduled_label'],
        ];
    }

    return [
        'allowed'         => true,
        'reason'          => '',
        'scheduled_label' => $ctx['scheduled_label'],
    ];
}

/**
 * Patient-side join button state for dashboards and consultation lists.
 *
 * @return array{allowed:bool, mode:string, reason:string, scheduled_label:string}
 */
function consultation_patient_join_access(array $item): array
{
    $ctx        = queue_session_context($item);
    $room_token = trim((string) ($item['room_token'] ?? ''));

    // Best practice: patient may join ONLY after provider started the live room.
    if ($ctx['status'] === 'in_consultation' && $room_token !== '') {
        return [
            'allowed'         => true,
            'mode'            => 'join',
            'reason'          => '',
            'scheduled_label' => $ctx['scheduled_label'],
        ];
    }

    if (queue_is_before_scheduled_start($ctx)) {
        return [
            'allowed'         => false,
            'mode'            => 'scheduled_wait',
            'reason'          => queue_before_start_reason($ctx),
            'scheduled_label' => $ctx['scheduled_label'],
        ];
    }

    $provider_access = queue_session_access($item);

    // Provider started consultation but room token not ready yet.
    if ($ctx['status'] === 'in_consultation' && $room_token === '') {
        return [
            'allowed'         => false,
            'mode'            => 'waiting',
            'reason'          => 'Your provider is preparing the video room.',
            'scheduled_label' => $ctx['scheduled_label'],
        ];
    }

    // Scheduled/pending on the appointment day → wait for provider to press Start.
    if ($provider_access['allowed'] && in_array($ctx['status'], ['scheduled', 'pending'], true)) {
        return [
            'allowed'         => false,
            'mode'            => 'waiting',
            'reason'          => 'Waiting for your provider to start the video call.',
            'scheduled_label' => $ctx['scheduled_label'],
        ];
    }

    return [
        'allowed'         => false,
        'mode'            => 'unavailable',
        'reason'          => $provider_access['reason'] ?: 'This consultation is not available yet.',
        'scheduled_label' => $ctx['scheduled_label'],
    ];
}

/**
 * Whether a user may enter the live video room.
 *
 * @return array{allowed:bool, reason:string, scheduled_label:string}
 */
function consultation_video_room_access(array $item): array
{
    $ctx = queue_session_context($item);

    if ($ctx['status'] === 'in_consultation') {
        return [
            'allowed'         => true,
            'reason'          => '',
            'scheduled_label' => $ctx['scheduled_label'],
        ];
    }

    if (queue_is_before_scheduled_start($ctx)) {
        return [
            'allowed'         => false,
            'reason'          => queue_before_start_reason($ctx),
            'scheduled_label' => $ctx['scheduled_label'],
        ];
    }

    return queue_session_access($item);
}
