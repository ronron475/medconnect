<?php
/**
 * Hero smart search — index payload for live suggestions.
 *
 * @var array<int, array<string, mixed>> $landing_search_announcements
 */

$hero_search_item = static function (
    string $title,
    string $category,
    array $action,
    string $icon,
    array $keywords = [],
    string $description = ''
): array {
    return [
        'title' => $title,
        'category' => $category,
        'action' => $action,
        'icon' => $icon,
        'keywords' => $keywords,
        'description' => $description,
    ];
};

$hero_search_index = [
    $hero_search_item('Home', 'Page', ['type' => 'scroll', 'id' => 'hero-section'], 'home', ['home', 'main', 'start', 'landing'], 'Return to the medConnect homepage'),
    $hero_search_item('Announcements', 'Page', ['type' => 'scroll', 'id' => 'announcements-section'], 'announcement', ['announcement', 'announcements', 'ann', 'news', 'advisory', 'updates'], 'Latest health advisories and program updates'),
    $hero_search_item('Services', 'Page', ['type' => 'scroll', 'id' => 'services-section'], 'service', ['service', 'services', 'platform', 'features'], 'Explore medConnect healthcare services'),
    $hero_search_item('How It Works', 'Page', ['type' => 'scroll', 'id' => 'how-it-works'], 'guide', ['how', 'works', 'process', 'steps', 'guide'], 'Learn the medConnect patient journey'),
    $hero_search_item('About Us', 'Page', ['type' => 'scroll', 'id' => 'about-section'], 'team', ['about', 'team', 'capstone', 'developers', 'mission', 'bcc', 'bsis'], 'Meet the medConnect development team'),
    $hero_search_item('Contact', 'Page', ['type' => 'scroll', 'id' => 'contact-section'], 'contact', ['contact', 'phone', 'email', 'address', 'reach', 'support'], 'Get in touch with medConnect'),
    $hero_search_item('All Announcements', 'Page', ['type' => 'url', 'href' => ASSET_BASE . '/announcements.php'], 'announcement', ['announcements', 'list', 'archive'], 'Browse the full announcements page'),

    $hero_search_item('AI Triage', 'Service', ['type' => 'scroll', 'id' => 'services-section'], 'triage', ['ai', 'triage', 'tri', 'symptom', 'assessment', 'priority', 'urgency'], 'Smart symptom assessment and patient prioritization'),
    $hero_search_item('Video Consultation', 'Service', ['type' => 'scroll', 'id' => 'services-section'], 'video', ['video', 'vid', 'consultation', 'consult', 'telemedicine', 'call'], 'Secure online medical video consultations'),
    $hero_search_item('Secure Medical Records', 'Service', ['type' => 'scroll', 'id' => 'services-section'], 'records', ['record', 'records', 'emr', 'medical', 'history', 'secure', 'digital'], 'Centralized electronic medical records'),
    $hero_search_item('Appointment Booking', 'Service', ['type' => 'book'], 'calendar', ['book', 'booking', 'appointment', 'schedule', 'consultation'], 'Book a non-emergency medical consultation'),
    $hero_search_item('Book Consultation', 'Service', ['type' => 'book'], 'calendar', ['book', 'consultation', 'appointment', 'schedule'], 'Start a consultation booking'),
    $hero_search_item('Health Summary', 'Service', ['type' => 'scroll', 'id' => 'services-section'], 'health', ['health', 'summary', 'profile', 'overview'], 'Patient health overview and records access'),
    $hero_search_item('Digital Prescription', 'Service', ['type' => 'scroll', 'id' => 'services-section'], 'prescription', ['prescription', 'pre', 'rx', 'medicine', 'medication'], 'Digital prescriptions from licensed providers'),
    $hero_search_item('Prescription Services', 'Service', ['type' => 'scroll', 'id' => 'services-section'], 'prescription', ['prescription', 'pre', 'rx', 'pharmacy'], 'Prescription management and follow-up'),
    $hero_search_item('Medical History', 'Service', ['type' => 'scroll', 'id' => 'services-section'], 'records', ['history', 'medical', 'records', 'past', 'visits'], 'View consultation and medical history'),
    $hero_search_item('Post-Consultation Monitoring', 'Service', ['type' => 'scroll', 'id' => 'services-section'], 'monitor', ['follow', 'follow-up', 'monitoring', 'progress', 'recovery'], 'Track progress after consultations'),

    $hero_search_item('Consultation', 'Service', ['type' => 'book'], 'calendar', ['consultation', 'consult', 'co', 'c', 'doctor', 'provider'], 'Book or learn about consultations'),
    $hero_search_item('Chat Support', 'Service', ['type' => 'scroll', 'id' => 'contact-section'], 'chat', ['chat', 'support', 'help', 'message'], 'Contact medConnect for assistance'),

    $hero_search_item('City Health Office', 'Location', ['type' => 'modal', 'id' => 'open-location-modal'], 'location', ['city', 'health', 'office', 'cho', 'bago', 'location', 'place'], 'City Health Office of Bago City'),

    $hero_search_item('Fever', 'Health Topic', ['type' => 'scroll', 'id' => 'services-section'], 'topic', ['fever', 'temperature', 'hot'], 'Learn how medConnect supports triage for fever symptoms'),
    $hero_search_item('Cough', 'Health Topic', ['type' => 'scroll', 'id' => 'services-section'], 'topic', ['cough', 'cold', 'respiratory'], 'Get guidance for cough-related concerns'),
    $hero_search_item('Headache', 'Health Topic', ['type' => 'scroll', 'id' => 'services-section'], 'topic', ['headache', 'head', 'pain', 'migraine'], 'Support for headache symptom assessment'),
    $hero_search_item('Flu', 'Health Topic', ['type' => 'scroll', 'id' => 'services-section'], 'topic', ['flu', 'influenza', 'virus'], 'Flu-related triage and consultation support'),
    $hero_search_item('Hypertension', 'Health Topic', ['type' => 'scroll', 'id' => 'services-section'], 'topic', ['hypertension', 'blood', 'pressure', 'bp'], 'Blood pressure monitoring and follow-up care'),
    $hero_search_item('Diabetes', 'Health Topic', ['type' => 'scroll', 'id' => 'services-section'], 'topic', ['diabetes', 'blood sugar', 'glucose'], 'Diabetes care coordination through medConnect'),
];

foreach ($landing_search_announcements ?? [] as $ann) {
    $title = (string) ($ann['title'] ?? 'Announcement');
    $short = (string) ($ann['short_description'] ?? $ann['subtitle'] ?? '');
    $keywords = array_filter([
        strtolower($title),
        'announcement',
        'ann',
        strtolower((string) ($ann['category_label'] ?? $ann['category'] ?? '')),
    ]);

    $hero_search_index[] = $hero_search_item(
        $title,
        'Announcement',
        ['type' => 'announcement', 'id' => (int) ($ann['id'] ?? 0)],
        'announcement',
        array_values(array_unique($keywords)),
        $short !== '' ? $short : 'Published health office announcement'
    );
}

echo json_encode(
    $hero_search_index,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
);
