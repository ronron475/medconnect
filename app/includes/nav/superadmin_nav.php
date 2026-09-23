<?php

/**
 * Super Admin portal navigation — mirrors admin structure with enterprise sections.
 * Format: section label (null = no header) + items [file, label, icon, query?]
 */

return [

    ['section' => null, 'items' => [

        ['dashboard.php', 'Dashboard', '<path d="M3 3h7v7H3z"/><path d="M14 3h7v7h-7z"/><path d="M14 14h7v7h-7z"/><path d="M3 14h7v7H3z"/>'],

    ]],

    ['section' => 'User Verification', 'items' => [

        ['bhw_applications.php', 'BHW Applications', '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>', null, 'bhw_verification'],

        ['doctor_applications.php', 'Doctor Applications', '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11h-6"/><path d="M19 8v6"/>', 'tab=pending', 'doctor_verification'],

    ]],

    ['section' => 'User Management', 'items' => [

        ['user_management.php', 'Patient Accounts', '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>', 'role=patient', 'patient_management'],

        ['administrators.php', 'Administrator Management', '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'],

    ]],

    ['section' => 'System Management', 'items' => [

        ['audit_trail.php', 'Audit Logs', '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>'],

        ['backup.php', 'Backup & Recovery', '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>'],

    ]],

    ['section' => 'Reports', 'items' => [

        ['case_reports.php', 'Violation Reports', '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>'],

        ['reports.php', 'System Reports', '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>'],

        ['analytics.php', 'System Analytics', '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>'],

    ]],

    ['section' => null, 'items' => [

        ['profile.php', 'Settings', '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>'],

    ]],

];
