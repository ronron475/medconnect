<?php
/**
 * Rule-based emergency + crisis detection (PHP only).
 * Stops normal FAQ flow when triggered. Never provides harmful advice.
 */
final class FaqChatbotEmergencyDetector
{
    /** @var list<array{0: string, 1: string}> type, pattern */
    private const RULES = [
        ['medical', '/\b(chest\s+pain|heart\s+attack|masakit\s+ang\s+dibdib|sakit\s+ang\s+dibdib|sakit\s+(ang\s+)?dughan|masakit\s+(akon\s+|ang\s+)?dughan|gapalanakit\s+(ang\s+)?dughan)\b/ui'],
        ['medical', '/\b(stroke|stroke\s+symptoms|face\s+droop|arm\s+weakness|slurred\s+speech|biglang\s+pagkaparalisa)\b/ui'],
        ['medical', '/\b(can\'?t\s+breathe|cannot\s+breathe|difficulty\s+breathing|trouble\s+breathing|not\s+breathing|shortness\s+of\s+breath|hirap\s+huminga|di\s+makahinga|indi\s+makahinga|indi\s+makaginhawa|indi\s+ko\s+kaginhawa|indi\s+ko\s+ginhawa|budlay\s+mag\s*ginhawa|budlay\s+ginhawa|lisod\s+ginhawa|lisud\s+ginhawa)\b/ui'],
        ['medical', '/\b(unconscious|passed\s+out|fainting|fainted|walang\s+malay|nawalan\s+ng\s+malay|wala\s+malay|wala\s+ko\s+malay|nawad\s*an\s+malay|nahimatay)\b/ui'],
        ['medical', '/\b(severe\s+bleeding|heavy\s+bleeding|malubhang\s+dugo|grabeng\s+dugo|pagdurugo|grabeng\s+pagdugo|dugo\s+gid|nagsuka\s+kag\s+dugo|vomiting\s+blood)\b/ui'],
        ['medical', '/\b(seizure|seizures|convulsion|nag\s+seizure|choking|overdose|poisoning|lason|nalason)\b/ui'],
        ['medical', '/\b(blue\s+lips|facial\s+droop|face\s+drooping|difficulty\s+speaking|slurred\s+speech|sudden\s+weakness)\b/ui'],
        ['medical', '/\b(grabe\s+chest\s+pain|severe\s+chest\s+pain)\b/ui'],
        ['medical', '/\b(anaphyla|severe\s+allerg|allergic\s+shock|dila\s+nagabukol|throat\s+swelling)\b/ui'],
        ['crisis', '/\b(suicide|suicidal|kill\s+myself|end\s+my\s+life|want\s+to\s+die|going\s+to\s+die|gonna\s+die|i\'?m\s+going\s+to\s+die|im\s+going\s+to\s+die|no\s+reason\s+to\s+live)\b/ui'],
        ['crisis', '/\b(ayaw\s+ko\s+mabuhay|gusto\s+kong\s+mamatay|gusto\s+ko\s+mamatay|magpakamatay|indi\s+ko\s+gusto\s+mabuhi|wala\s+(na\s+)?paglaum\s+sa\s+kinabuhi)\b/ui'],
        ['crisis', '/\b(self[\s-]?harm|cut\s+myself|hurt\s+myself|saktan\s+ko\s+ang\s+kaugalingon)\b/ui'],
    ];

    /**
     * @return array{is_emergency: bool, type: ?string, flow: ?string, reason: string}
     */
    public static function detect(string $text): array
    {
        $norm = FaqEmotionEngine::normalizeText($text);
        $rawNorm = trim(preg_replace('/\s+/u', ' ', mb_strtolower($text, 'UTF-8')) ?? '');
        $haystacks = array_values(array_unique(array_filter([$norm, $rawNorm], static fn (string $h): bool => $h !== '')));
        if ($haystacks === []) {
            return ['is_emergency' => false, 'type' => null, 'flow' => null, 'reason' => ''];
        }

        foreach ($haystacks as $hay) {
            foreach (self::RULES as [$type, $pattern]) {
                if (preg_match($pattern, $hay)) {
                    $flow = $type === 'crisis' ? 'crisis' : 'emergency';
                    return [
                        'is_emergency' => true,
                        'type'       => $type,
                        'flow'       => $flow,
                        'reason'     => $type === 'crisis' ? 'crisis_language' : 'medical_emergency_language',
                    ];
                }
            }
        }

        // Lexicon emergency only when the emergency phrase is actually present.
        // Fuzzy "wala kwarta" ≈ "wala malay" must never become an emergency.
        $lex = FaqChatbotConversationalIntents::match($text);
        if ($lex !== null && !empty($lex['emergency'])) {
            $phrase = FaqChatbotConversationalIntents::normalize((string) ($lex['phrase'] ?? ''));
            if ($phrase !== '' && !str_starts_with($phrase, 'multi:')) {
                foreach ($haystacks as $hay) {
                    if (mb_strpos($hay, $phrase) !== false) {
                        return [
                            'is_emergency' => true,
                            'type'         => 'medical',
                            'flow'         => 'emergency',
                            'reason'       => 'conversational_emergency_lexicon',
                        ];
                    }
                }
            }
        }

        return ['is_emergency' => false, 'type' => null, 'flow' => null, 'reason' => ''];
    }
}
