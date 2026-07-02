<?php
/**
 * Provider UI translations (English / Filipino).
 */

function provider_i18n_strings(): array
{
    return [
        'en' => [
            'settings' => 'Settings',
            'profile_information' => 'Profile Information',
            'security_password' => 'Security & Password',
            'notification_preferences' => 'Notification Preferences',
            'system_preferences' => 'System Preferences',
            'theme_preference' => 'Theme Preference',
            'language' => 'Language',
            'time_format' => 'Time Format',
            'date_format' => 'Date Format',
            'auto_logout' => 'Auto Logout Duration',
            'save_preferences' => 'Save Preferences',
            'theme_system' => 'System Default',
            'theme_light' => 'Light Mode',
            'theme_dark' => 'Dark Mode',
            'lang_en' => 'English',
            'lang_fil' => 'Filipino',
            'time_12h' => '12-hour (e.g. 2:45 PM)',
            'time_24h' => '24-hour (e.g. 14:45)',
            'logout_15' => '15 Minutes',
            'logout_30' => '30 Minutes',
            'logout_60' => '1 Hour',
            'logout_120' => '2 Hours',
        ],
        'fil' => [
            'settings' => 'Mga Setting',
            'profile_information' => 'Impormasyon ng Profile',
            'security_password' => 'Seguridad at Password',
            'notification_preferences' => 'Mga Kagustuhan sa Notification',
            'system_preferences' => 'Mga Kagustuhan sa Sistema',
            'theme_preference' => 'Tema',
            'language' => 'Wika',
            'time_format' => 'Format ng Oras',
            'date_format' => 'Format ng Petsa',
            'auto_logout' => 'Tagal bago Mag-logout',
            'save_preferences' => 'I-save ang mga Kagustuhan',
            'theme_system' => 'Default ng Sistema',
            'theme_light' => 'Light Mode',
            'theme_dark' => 'Dark Mode',
            'lang_en' => 'Ingles',
            'lang_fil' => 'Filipino',
            'time_12h' => '12-oras (hal. 2:45 PM)',
            'time_24h' => '24-oras (hal. 14:45)',
            'logout_15' => '15 Minuto',
            'logout_30' => '30 Minuto',
            'logout_60' => '1 Oras',
            'logout_120' => '2 Oras',
        ],
    ];
}

function provider_i18n(string $key, ?string $lang = null): string
{
    $lang = $lang ?? ($_SESSION['provider_language'] ?? 'en');
    $strings = provider_i18n_strings();
    return $strings[$lang][$key] ?? $strings['en'][$key] ?? $key;
}
