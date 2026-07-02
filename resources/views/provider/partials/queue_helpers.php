<?php
/**
 * Schedule-based access rules for provider consultation sessions.
 */

function queue_scheduled_label(?string $consult_date, ?string $consult_time = null): string
{
    if (!$consult_date) {
        return 'No scheduled date';
    }

    $label = date('l, F j, Y', strtotime($consult_date));
    if ($consult_time) {
        $label .= ' at ' . date('g:i A', strtotime($consult_time));
    }

    return $label;
}

/**
 * Whether a provider may open the consultation session (date-only rule).
 *
 * @return array{allowed:bool, reason:string, scheduled_label:string}
 */
function queue_session_access(array $item): array
{
    $status       = (string) ($item['status'] ?? 'pending');
    $consult_date = (string) ($item['consult_date'] ?? '');
    $consult_time = (string) ($item['consult_time'] ?? '');
    $today        = date('Y-m-d');
    $scheduled    = queue_scheduled_label($consult_date, $consult_time);

    if ($status === 'in_consultation') {
        return [
            'allowed'         => true,
            'reason'          => '',
            'scheduled_label' => $scheduled,
        ];
    }

    if (in_array($status, ['completed', 'cancelled'], true)) {
        return [
            'allowed'         => false,
            'reason'          => 'This consultation is already ' . ucwords(str_replace('_', ' ', $status)) . '.',
            'scheduled_label' => $scheduled,
        ];
    }

    if ($consult_date === '' || $consult_date !== $today) {
        if ($consult_date !== '' && strtotime($consult_date) > strtotime($today)) {
            return [
                'allowed'         => false,
                'reason'          => 'This session is scheduled for ' . $scheduled . '. You can only open it on the scheduled date.',
                'scheduled_label' => $scheduled,
            ];
        }

        if ($consult_date !== '' && strtotime($consult_date) < strtotime($today)) {
            return [
                'allowed'         => false,
                'reason'          => 'This session was scheduled for ' . $scheduled . '. The scheduled date has already passed.',
                'scheduled_label' => $scheduled,
            ];
        }

        return [
            'allowed'         => false,
            'reason'          => 'This consultation does not have a valid scheduled date for today.',
            'scheduled_label' => $scheduled,
        ];
    }

    return [
        'allowed'         => true,
        'reason'          => '',
        'scheduled_label' => $scheduled,
    ];
}
