<?php
/**
 * Rule-based emergency detection (PHP only). Stops normal FAQ flow when triggered.
 */
final class FaqChatbotEmergencyDetector
{
    /** @var list<array{0: string, 1: string}> type, pattern */
    private const RULES = [
        ['medical', '/\b(chest\s+pain|heart\s+attack|masakit\s+ang\s+dibdib|sakit\s+ang\s+dibdib)\b/ui'],
        ['medical', '/\b(stroke|stroke\s+symptoms|biglang\s+pagkaparalisa)\b/ui'],
        ['medical', '/\b(can\'?t\s+breathe|cannot\s+breathe|difficulty\s+breathing|trouble\s+breathing|not\s+breathing|hirap\s+huminga|di\s+makahinga|indi\s+makahinga|indi\s+makaginhawa)\b/ui'],
        ['medical', '/\b(unconscious|passed\s+out|walang\s+malay|nawalan\s+ng\s+malay|wala\s+malay)\b/ui'],
        ['medical', '/\b(severe\s+bleeding|heavy\s+bleeding|malubhang\s+dugo|grabeng\s+dugo|pagdurugo)\b/ui'],
        ['medical', '/\b(seizure|choking|overdose|poisoning|lason|nalason)\b/ui'],
        ['crisis', '/\b(suicide|suicidal|kill\s+myself|end\s+my\s+life|want\s+to\s+die|going\s+to\s+die|gonna\s+die|i\'?m\s+going\s+to\s+die|im\s+going\s+to\s+die|i\'?m\s+dying|im\s+dying|about\s+to\s+die)\b/ui'],
        ['crisis', '/\b(ayaw\s+ko\s+mabuhay|gusto\s+kong\s+mamatay|magpakamatay)\b/ui'],
        ['crisis', '/\b(self[\s-]?harm|cut\s+myself|hurt\s+myself)\b/ui'],
    ];

    /**
     * @return array{is_emergency: bool, type: ?string, flow: ?string, reason: string}
     */
    public static function detect(string $text): array
    {
        $norm = FaqEmotionEngine::normalizeText($text);
        if ($norm === '') {
            return ['is_emergency' => false, 'type' => null, 'flow' => null, 'reason' => ''];
        }

        foreach (self::RULES as [$type, $pattern]) {
            if (preg_match($pattern, $norm)) {
                $flow = $type === 'crisis' ? 'crisis' : 'emergency';
                return [
                    'is_emergency' => true,
                    'type'       => $type,
                    'flow'       => $flow,
                    'reason'     => $type === 'crisis' ? 'crisis_language' : 'medical_emergency_language',
                ];
            }
        }

        return ['is_emergency' => false, 'type' => null, 'flow' => null, 'reason' => ''];
    }
}
