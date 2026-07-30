<?php
/**
 * End-to-end NLP preprocessing: detect → translate → normalize → typo → synonyms.
 */
final class FaqChatbotNlpPipeline
{
    /**
     * @return array{
     *   original: string,
     *   normalized: string,
     *   english_text: string,
     *   expanded_english: string,
     *   detected_lang: string,
     *   reply_lang: string,
     *   is_hiligaynon: bool,
     *   pipeline_steps: list<string>
     * }
     */
    public static function process(PDO $pdo, string $text, string $langHint = 'en'): array
    {
        $original = trim($text);
        $dict = new FaqChatbotDictionaryRepository($pdo);
        $dict->ensureSeeded();

        $typo = new FaqChatbotTypoCorrector($dict);
        $translator = new FaqChatbotTranslator($dict, $typo);
        $synonyms = new FaqChatbotSynonymEngine($pdo);

        $tr = $translator->translate($original, $langHint);
        $english = $tr['english'];
        $expanded = $synonyms->expandToString($english, 'en');
        $normalized = FaqChatbotTextNormalizer::normalize($english);

        $steps = $tr['steps'];
        if ($expanded !== $english) {
            $steps[] = 'synonyms';
        }

        return [
            'original'         => $original,
            'normalized'       => $normalized,
            'english_text'     => $english,
            'expanded_english' => $expanded,
            'detected_lang'    => $tr['detected_lang'],
            'reply_lang'       => $tr['reply_lang'],
            'is_hiligaynon'    => $tr['detected_lang'] === 'hil',
            'pipeline_steps'   => $steps,
        ];
    }
}
