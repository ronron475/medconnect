<?php
/**
 * Maps detailed emotion tags to the 8 canonical healthcare-chatbot emotions.
 */
final class FaqChatbotStandardEmotion
{
    public const HAPPY = 'happy';
    public const SAD = 'sad';
    public const WORRIED = 'worried';
    public const ANGRY = 'angry';
    public const FRUSTRATED = 'frustrated';
    public const CONFUSED = 'confused';
    public const FEARFUL = 'fearful';
    public const NEUTRAL = 'neutral';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::HAPPY, self::SAD, self::WORRIED, self::ANGRY,
            self::FRUSTRATED, self::CONFUSED, self::FEARFUL, self::NEUTRAL,
        ];
    }

    public static function canonicalize(?string $emotion): string
    {
        if ($emotion === null || $emotion === '') {
            return self::NEUTRAL;
        }
        $e = strtolower(trim($emotion));
        return match ($e) {
            'happy', 'thankful', 'relieved', 'excited', 'surprised', 'affectionate' => self::HAPPY,
            'hopeful', 'proud', 'calm' => self::HAPPY,
            'sad', 'lonely', 'crying', 'disappointed', 'hopeless', 'tired', 'grief',
            'embarrassed', 'ashamed', 'guilty', 'jealous' => self::SAD,
            'worried', 'anxious', 'nervous', 'stressed', 'overwhelmed', 'uncertain', 'mixed' => self::WORRIED,
            'angry', 'irritated' => self::ANGRY,
            'frustrated', 'bored' => self::FRUSTRATED,
            'confused', 'curious' => self::CONFUSED,
            'afraid', 'panic', 'fear', 'fearful', 'scared' => self::FEARFUL,
            default => self::NEUTRAL,
        };
    }

    public static function label(string $canonical, string $lang = 'en'): string
    {
        $L = FaqEmotionEngine::normalizeLang($lang);
        $labels = [
            'en' => [
                self::HAPPY => 'Happy', self::SAD => 'Sad', self::WORRIED => 'Worried',
                self::ANGRY => 'Angry', self::FRUSTRATED => 'Frustrated',
                self::CONFUSED => 'Confused', self::FEARFUL => 'Fearful', self::NEUTRAL => 'Neutral',
            ],
            'fil' => [
                self::HAPPY => 'Masaya', self::SAD => 'Malungkot', self::WORRIED => 'Nag-aalala',
                self::ANGRY => 'Galit', self::FRUSTRATED => 'Frustrated',
                self::CONFUSED => 'Nalilito', self::FEARFUL => 'Natakot', self::NEUTRAL => 'Neutral',
            ],
            'hil' => [
                self::HAPPY => 'Masadya', self::SAD => 'Kasubo', self::WORRIED => 'Nabalaka',
                self::ANGRY => 'Akig', self::FRUSTRATED => 'Frustrated',
                self::CONFUSED => 'Nalibog', self::FEARFUL => 'Nahadlok', self::NEUTRAL => 'Neutral',
            ],
        ];
        $pack = $labels[$L] ?? $labels['en'];
        return $pack[$canonical] ?? $pack[self::NEUTRAL];
    }
}
