<?php
/**
 * Hiligaynon → English translation using MySQL dictionary, JSON seed, and phrase bridge.
 */
final class FaqChatbotTranslator
{
    public function __construct(
        private FaqChatbotDictionaryRepository $dict,
        private FaqChatbotTypoCorrector $typo,
    ) {
    }

    /**
     * @return array{english: string, detected_lang: string, reply_lang: string, steps: list<string>}
     */
    public function translate(string $text, string $hintLang = 'en'): array
    {
        $original = trim($text);
        $steps = [];
        $detected = $this->detectLanguage($original, $hintLang);
        $replyLang = $detected === 'hil' ? 'hil' : FaqEmotionEngine::normalizeLang($hintLang);

        if ($detected !== 'hil') {
            return [
                'english'       => FaqChatbotTextNormalizer::normalize($original),
                'detected_lang' => $detected,
                'reply_lang'    => $replyLang,
                'steps'         => ['no_hil_detect'],
            ];
        }

        $corrected = $this->typo->correctText($original);
        if ($corrected !== FaqChatbotTextNormalizer::normalize($original)) {
            $steps[] = 'typo_corrected';
        }

        $work = FaqChatbotTextNormalizer::normalize($corrected);
        foreach ($this->dict->phrases() as $row) {
            $src = (string) $row['source_text'];
            if ($src !== '' && str_contains($work, $src)) {
                $work = str_replace($src, (string) $row['target_text'], $work);
                $steps[] = 'phrase:' . $src;
            }
        }

        $tokens = FaqChatbotTextNormalizer::tokenize($work);
        $map = $this->dict->tokens();
        $out = [];
        foreach ($tokens as $tok) {
            if (isset($map[$tok])) {
                $mapped = trim($map[$tok]);
                if ($mapped !== '') {
                    foreach (explode(' ', $mapped) as $w) {
                        $out[] = $w;
                    }
                    continue;
                }
            }
            $out[] = $tok;
        }
        $english = trim(implode(' ', $out));

        if ($english === '' || $english === $work) {
            $bridge = FaqChatbotLanguageBridge::prepare($original, 'hil');
            $english = $bridge['english_gloss'] ?: $bridge['nlp_text'];
            $steps[] = 'bridge_fallback';
        } else {
            $steps[] = 'dictionary';
        }

        return [
            'english'       => $english,
            'detected_lang' => 'hil',
            'reply_lang'    => 'hil',
            'steps'         => $steps,
        ];
    }

    private function detectLanguage(string $text, string $hintLang): string
    {
        if (FaqChatbotLanguageBridge::looksHiligaynon($text)) {
            return 'hil';
        }
        if (class_exists('HiligaynonLanguageDetector', false)) {
            $lang = HiligaynonLanguageDetector::primaryLanguage($text);
            if ($lang === 'hil' || $lang === 'hiligaynon') {
                return 'hil';
            }
        }
        $h = FaqEmotionEngine::normalizeLang($hintLang);
        return $h === 'hil' ? 'hil' : 'en';
    }
}
