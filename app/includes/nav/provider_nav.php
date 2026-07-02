<?php
/**
 * Provider portal navigation — grouped sidebar (single source of truth).
 * Each item: [file, label, icon SVG paths].
 */
return [
    [
        'id'    => 'overview',
        'label' => 'Overview',
        'items' => [
            ['dashboard.php', 'Dashboard', '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>'],
        ],
    ],
    [
        'id'    => 'clinical',
        'label' => 'Clinical Workflow',
        'items' => [
            ['queue.php',  'Live Queue',           '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="23" y1="11" x2="17" y2="11"/>'],
            ['triage.php', 'Active Triage Review', '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>'],
        ],
    ],
    [
        'id'    => 'patient_care',
        'label' => 'Patient Care',
        'items' => [
            ['medical_records.php',     'Medical Records',      '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>'],
            ['prescriptions.php',       'e-Prescriptions',      '<path d="M10.5 20.5L3.5 13.5a4.95 4.95 0 1 1 7-7L17.5 13.5"/><path d="m14 6 4 4"/>'],
            ['referrals.php',           'Referrals',            '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>'],
            ['followup_management.php', 'Follow-Up Management', '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/>'],
        ],
    ],
    [
        'id'    => 'practice',
        'label' => 'Practice',
        'items' => [
            ['schedule.php', 'Schedule & Availability', '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>'],
            ['messages.php', 'Messages',                '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>'],
            ['settings.php', 'Settings',                '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>'],
        ],
    ],
    [
        'id'        => 'insights',
        'label'     => 'Insights',
        'secondary' => true,
        'items'     => [
            ['gis_dashboard.php', 'GIS Dashboard', '<path d="M1 6v16l7-4 8 4V2L8 6 1 2z"/><circle cx="12" cy="10" r="3"/>'],
        ],
    ],
];
