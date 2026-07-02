<?php
/**
 * BHW panel navigation — single source of truth for labels, icons, and routes.
 */
function bhw_nav_dashboard(): array
{
    return [
        'label' => 'Dashboard',
        'icon' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'description' => 'View overall barangay health status',
        'file' => 'dashboard.php',
    ];
}

function bhw_nav_groups(): array
{
    return [
        [
            'id' => 'patients',
            'icon' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'label' => 'Patient Management',
            'description' => 'Register and update patient information',
            'children' => [
                ['file' => 'patients/register.php', 'label' => 'Register Patient', 'hint' => 'Add a new resident', 'icon' => 'user-plus'],
                ['file' => 'patients/list.php', 'label' => 'View Patient List', 'hint' => 'Search barangay patients', 'icon' => 'list'],
                ['file' => 'patients/update.php', 'label' => 'Update Patient Info', 'hint' => 'Edit contact & medical data', 'icon' => 'edit'],
            ],
        ],
        [
            'id' => 'triage',
            'icon' => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>',
            'label' => 'Triage & Booking',
            'description' => 'AI triage assessment and book consultation',
            'children' => [
                ['file' => 'triage/submit.php', 'label' => 'Triage & Book Consultation', 'hint' => 'AI symptom check & booking', 'icon' => 'clipboard'],
            ],
        ],
        [
            'id' => 'consultations',
            'icon' => '<path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>',
            'label' => 'Consultations',
            'description' => 'Schedule, status, and video assistance',
            'children' => [
                ['file' => 'consultations/index.php', 'label' => 'Consultation Center', 'hint' => 'Schedule, status & video help', 'icon' => 'video'],
            ],
        ],
        [
            'id' => 'records',
            'icon' => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
            'label' => 'Records',
            'description' => 'View and upload patient documents',
            'children' => [
                ['file' => 'records/index.php', 'label' => 'View Patient Records', 'hint' => 'Documents & prescriptions', 'icon' => 'folder'],
                ['file' => 'records/upload.php', 'label' => 'Upload Documents', 'hint' => 'PDF, JPG, or PNG files', 'icon' => 'upload'],
            ],
        ],
        [
            'id' => 'followup',
            'icon' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
            'label' => 'Follow-Up Monitoring',
            'description' => 'Track patient recovery and reminders',
            'children' => [
                ['file' => 'followup/track.php', 'label' => 'Track Patient Status', 'hint' => 'Monitor recovery progress', 'icon' => 'activity'],
                ['file' => 'followup/reminders.php', 'label' => 'Send Reminders', 'hint' => 'Notify patients to follow up', 'icon' => 'bell'],
            ],
        ],
        [
            'id' => 'referral',
            'icon' => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>',
            'label' => 'Referral',
            'description' => 'Refer patients to hospitals',
            'children' => [
                ['file' => 'referral/create.php', 'label' => 'Refer to Hospital', 'hint' => 'Send patient to a facility', 'icon' => 'share'],
                ['file' => 'referral/status.php', 'label' => 'View Referral Status', 'hint' => 'Track outgoing referrals', 'icon' => 'list'],
            ],
        ],
        [
            'id' => 'reports',
            'icon' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
            'label' => 'Reports',
            'description' => 'Healthcare statistics and exports for your barangay',
            'children' => [
                ['file' => 'reports/index.php', 'label' => 'Healthcare Reports', 'hint' => 'Charts & CSV/Excel export', 'icon' => 'chart'],
                ['file' => 'activity/index.php', 'label' => 'My Activity Log', 'hint' => 'Your actions & downloads', 'icon' => 'clock'],
            ],
        ],
        [
            'id' => 'settings',
            'icon' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
            'label' => 'Settings',
            'description' => 'Manage profile and logout',
            'children' => [
                ['file' => 'settings/profile.php', 'label' => 'Profile', 'hint' => 'Name, photo & contact', 'icon' => 'user'],
                ['type' => 'logout', 'label' => 'Logout'],
            ],
        ],
    ];
}

function bhw_nav_find_group_for_file(string $file): ?array
{
    foreach (bhw_nav_groups() as $group) {
        foreach ($group['children'] as $child) {
            if (($child['type'] ?? '') === 'logout') {
                continue;
            }
            if ($child['file'] === $file) {
                return $group;
            }
        }
    }
    return null;
}

/** Map legacy routes to current nav file for active-state highlighting. */
function bhw_nav_resolve_file(string $file): string
{
    $legacy = [
        'appointments/book.php'      => 'triage/submit.php',
        'appointments/schedule.php'  => 'consultations/index.php',
        'consultation/assist.php'    => 'consultations/index.php',
        'consultation/status.php'    => 'consultations/index.php',
        'triage/encode.php'          => 'triage/submit.php',
    ];
    return $legacy[$file] ?? $file;
}

function bhw_nav_all_modules(): array
{
    $modules = [bhw_nav_dashboard()];
    foreach (bhw_nav_groups() as $group) {
        $first = null;
        foreach ($group['children'] as $child) {
            if (($child['type'] ?? '') !== 'logout') {
                $first = $child['file'];
                break;
            }
        }
        $modules[] = [
            'label' => $group['label'],
            'icon' => $group['icon'],
            'description' => $group['description'],
            'file' => $first,
        ];
    }
    return $modules;
}

function bhw_nav_render_icon(string $paths): string
{
    return '<svg class="bhw-sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . $paths . '</svg>';
}

function bhw_nav_subitem_icon_paths(): array
{
    return [
        'folder' => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        'chart' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/>',
        'list' => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
        'edit' => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        'clipboard' => '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>',
        'video' => '<polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>',
        'activity' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'bell' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
        'share' => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/>',
        'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    ];
}

function bhw_nav_render_subitem_icon(string $key): string
{
    $icons = bhw_nav_subitem_icon_paths();
    $paths = $icons[$key] ?? '<circle cx="12" cy="12" r="2"/>';
    return '<svg class="bhw-sb-subitem-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
}
