<?php
/**
 * Step 2: Normalize Hiligaynon patient text — prefixes, spellings, chat shorthand.
 */

final class HiligaynonTextNormalizer
{
    /** @var array<string, string> */
    private const SPELLING_MAP = [
        'saket'      => 'sakit',
        'skit'       => 'sakit',
        'masaket'    => 'masakit',
        'masakti'    => 'masakit',
        'linngin'    => 'lingin',
        'lingin2'    => 'lingin',
        'linggin'    => 'lingin',
        'nahilu'     => 'nahilo',
        'nahihilo'   => 'nahilo',
        'sip-on'     => 'sipon',
        'sip on'     => 'sipon',
        'tyan'       => 'tiyan',
        'tian'       => 'tiyan',
        'duhan'      => 'dughan',
        'dulunggan'  => 'dalunggan',
        'gahabok'    => 'ga hubag',
        'gahubag'    => 'ga hubag',
        'gasakit'    => 'ga sakit',
        'gasuka'     => 'ga suka',
        'gakalibanga'=> 'ga kalibanga',
        'ginauubo'   => 'ga ubo',
        'nagaubo'    => 'ga ubo',
        'ginakapos'  => 'ga kapos ginhawa',
        'kalibangga' => 'kalibanga',
        'lagnat'     => 'hilanat',
        'lagnaat'    => 'lagnat',
        'pilas'      => 'pilas',
        'nanah'      => 'nanah',
    ];

    /**
     * Full normalization pipeline for patient consultation text.
     */
    public static function normalize(string $text): string
    {
        $text = HiligaynonSymptomMatcher::collapseRepeatedCharacters(mb_strtolower(trim($text)));
        if ($text === '') {
            return '';
        }

        $text = self::normalizeChatShorthand($text);
        $text = preg_replace('/[^a-z0-9\s\-]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        $text = self::normalizeHyphenatedVerbs($text);
        $text = self::applySpellingMap($text);
        $text = self::normalizeVerbPrefixes($text);
        $text = self::collapseFillers($text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Expand Hiligaynon/Filipino chat shorthand before entity extraction.
     */
    private static function normalizeChatShorthand(string $text): string
    {
        $patterns = [
            '/\bsakit\s+kag\s+d\s+nko\s+kaginhawa\b/u' => 'sakit kag indi ko kaginhawa',
            '/\bsakit\s+kag\s+d\s+nako\s+kaginhawa\b/u' => 'sakit kag indi ko kaginhawa',
            '/\bd\s+nko\s+kaginhawa\b/u'               => 'indi ko kaginhawa',
            '/\bd\s+nako\s+kaginhawa\b/u'              => 'indi ko kaginhawa',
            '/\bd\s+nko\b/u'                           => 'indi ko',
            '/\bd\s+nako\b/u'                          => 'indi ko',
            '/\bd\s+ko\b/u'                            => 'indi ko',
            '/\bdko\b/u'                               => 'indi ko',
            '/\bdli\s+ko\b/u'                          => 'dili ko',
            '/\bdli\s+nako\b/u'                        => 'dili nako',
            '/\bwla\s+ko\b/u'                          => 'wala ko',
            '/\bwla\b/u'                               => 'wala',
            '/\bkaginahawa\b/u'                        => 'kaginhawa',
            '/\bginhwa+\b/u'                           => 'ginhawa',
            '/\bbudlay\s+ginhwa\b/u'                   => 'budlay ginhawa',
            '/\bmasakit\s+dughan\b/u'                  => 'chest pain',
            '/\bmasakit\s+dibdib\b/u'                  => 'chest pain',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        return $text;
    }

    /**
     * Produce canonical phrase keys for fuzzy phrase lookup.
     *
     * @return list<string>
     */
    public static function phraseVariants(string $text): array
    {
        $base = self::normalize($text);
        $variants = [$base];

        $cleaned = NlpPreprocessor::removeFillers($base);
        if ($cleaned !== '' && $cleaned !== $base) {
            $variants[] = $cleaned;
        }

        $noGa = preg_replace('/\bga\s+/u', '', $base) ?? $base;
        if ($noGa !== $base) {
            $variants[] = trim($noGa);
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private static function normalizeHyphenatedVerbs(string $text): string
    {
        $patterns = [
            '/\bga-sakit\b/u'            => 'ga sakit',
            '/\bga-suka\b/u'              => 'ga suka',
            '/\bga-hubag\b/u'             => 'ga hubag',
            '/\bga-lingin\b/u'            => 'ga lingin',
            '/\bga-kalibanga\b/u'         => 'ga kalibanga',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        return $text;
    }

    private static function applySpellingMap(string $text): string
    {
        foreach (self::SPELLING_MAP as $from => $to) {
            $pattern = '/\b' . preg_quote($from, '/') . '\b/u';
            $text = preg_replace($pattern, $to, $text) ?? $text;
        }

        return $text;
    }

    private static function normalizeVerbPrefixes(string $text): string
    {
        $text = preg_replace('/\bgina\s+/u', 'ga ', $text) ?? $text;
        $text = preg_replace('/\bnaga\s+/u', 'ga ', $text) ?? $text;
        $text = preg_replace('/\bgin\s+/u', 'ga ', $text) ?? $text;

        return $text;
    }

    private static function collapseFillers(string $text): string
    {
        $text = preg_replace('/\b(?:gid|man|bah|no|po|talaga)\b/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
