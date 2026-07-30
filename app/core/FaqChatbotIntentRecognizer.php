<?php
/**
 * Rule-based intent recognition for the FAQ chatbot (PHP only).
 */
final class FaqChatbotIntentRecognizer
{
    public const GREETING = 'greeting';
    public const APPOINTMENT = 'appointment';
    public const CONSULTATION = 'consultation';
    public const MEDICINE = 'medicine';
    public const DOCTOR = 'doctor';
    public const SYMPTOMS = 'symptoms';
    public const LOGIN = 'login';
    public const REGISTRATION = 'registration';
    public const SCHEDULE = 'schedule';
    public const EMERGENCY = 'emergency';
    public const FEEDBACK = 'feedback';
    public const GOODBYE = 'goodbye';
    public const HOSPITAL = 'hospital';
    public const RECORDS = 'medical_record';
    public const HEALTH_ADVICE = 'health_advice';
    public const FOLLOW_UP = 'follow_up';
    public const FAQ = 'faq';
    public const PRESCRIPTION = 'prescription';
    public const GENERAL = 'general';

    /**
     * @return array{intent: string, confidence: float, flow_key: ?string}
     */
    public static function recognize(string $text): array
    {
        $norm = FaqEmotionEngine::normalizeText($text);
        if ($norm === '') {
            return ['intent' => self::GENERAL, 'confidence' => 0.0, 'flow_key' => null];
        }

        $emergency = FaqChatbotEmergencyDetector::detect($text);
        if ($emergency['is_emergency']) {
            return [
                'intent'     => self::EMERGENCY,
                'confidence' => 0.99,
                'flow_key'   => $emergency['flow'],
            ];
        }

        $rules = [
            [self::GOODBYE, 0.9, '/\b(bye|goodbye|see\s+you|paalam|kita\s+ta|hangtod)\b/ui', 'welcome'],
            [self::GREETING, 0.92, '/^(hi|hello|hey|good\s+(morning|afternoon|evening)|kumusta|musta|maayong)\b/ui', 'welcome'],
            [self::REGISTRATION, 0.88, '/\b(register|sign\s*up|create\s+account|rehistro|magrehistro|bagong\s+account)\b/ui', 'register'],
            [self::LOGIN, 0.88, '/\b(login|log\s*in|sign\s*in|sulod|password|forgot|reset|nakalimtan)\b/ui', 'signin'],
            [self::APPOINTMENT, 0.86, '/\b(appointment|book|mag-book|maka-book|schedule\s+visit)\b/ui', 'appointment'],
            [self::SCHEDULE, 0.84, '/\b(schedule|oras|office\s+hours|bukas|sarado|hours)\b/ui', 'hours'],
            [self::CONSULTATION, 0.86, '/\b(video|consultation|konsultasyon|telemedicine|online\s+consult)\b/ui', 'video'],
            [self::PRESCRIPTION, 0.9, '/\b(prescription|reseta|digital\s+prescription)\b/ui', 'prescriptions'],
            [self::MEDICINE, 0.9, '/\b(gamot|medicine|medication|bulong|tambal)\b/ui', 'prescriptions'],
            [self::DOCTOR, 0.82, '/\b(doctor|physician|provider|doktor|nars|nurse)\b/ui', 'services'],
            [self::HOSPITAL, 0.85, '/\b(hospital|ospital|emergency\s+room|er)\b/ui', 'contact'],
            [self::RECORDS, 0.86, '/\b(medical\s+record|health\s+summary|medical\s+history|emr)\b/ui', 'records'],
            [self::SYMPTOMS, 0.82, '/\b(symptom|masakit|sakit|gasakit|fever|lagnat|hilanat|ubo|sipon|headache|dizzy|nalipong|nahilo|chest\s+pain|dughan)\b/ui', 'pain_sick'],
            [self::HEALTH_ADVICE, 0.78, '/\b(should\s+i|what\s+should|health\s+advice|treatment|gamot\s+sa)\b/ui', 'policy'],
            [self::FOLLOW_UP, 0.8, '/\b(follow\s*up|followup|sunod\s+nga|balik\s+consult)\b/ui', 'appointment'],
            [self::FAQ, 0.75, '/\b(what\s+is\s+medconnect|about\s+medconnect|faq|help\s+topics)\b/ui', 'services'],
            [self::FEEDBACK, 0.75, '/\b(feedback|suggestion|complaint|reklamo|salamat|thank)\b/ui', 'gratitude'],
        ];

        foreach ($rules as [$intent, $conf, $pattern, $flow]) {
            if (preg_match($pattern, $norm)) {
                return ['intent' => $intent, 'confidence' => $conf, 'flow_key' => $flow];
            }
        }

        return ['intent' => self::GENERAL, 'confidence' => 0.35, 'flow_key' => null];
    }
}
