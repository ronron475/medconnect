<?php
/**
 * Expands Hiligaynon chat shorthand / informal spellings for emotion NLP.
 */
final class FaqChatbotEmotionShorthand
{
    /** @var list<array{0: string, 1: string}> longest match first */
    private const PHRASE_MAP = [
        ['sakit kag d nko kaginhawa', 'sakit kag indi ko kaginhawa'],
        ['d nako kaginhawa', 'indi ko kaginhawa'],
        ['d nko kaginhawa', 'indi ko kaginhawa'],
        ['budlay ginhwa', 'budlay ginhawa'],
        ['masakit dughan', 'chest pain masakit dughan'],
        ['dli nako', 'dili nako'],
        ['dli ko', 'dili ko'],
        ['d nako', 'indi ko'],
        ['d nko', 'indi ko'],
        ['d ko', 'indi ko'],
        ['wla ko', 'wala ko'],
        ['wla', 'wala'],
        ['dko', 'indi ko'],
        ['ginhwa', 'ginhawa'],
        ['ginhwaa', 'ginhawa'],
        ['kaginahawa', 'kaginhawa'],
        ['ndi ko', 'indi ko'],
        ['ndi', 'indi'],
        ['nd ko', 'indi ko'],
        ['dli', 'dili'],
        ['kn', 'karon'],
        ['bdlay', 'budlay'],
        ['nhdlok', 'nahadlok'],
        ['nblk', 'nabalaka'],
        ['kpy', 'kapoy'],
        ['slmt', 'salamat'],
        ['blg', 'bulig'],
        ['tbng', 'tabang'],
        ['knsulta', 'konsulta'],
        ['appt', 'appointment'],
        ['chk up', 'check up'],
        ['chck up', 'check up'],
        ['ugh', 'tired frustrated'],
        ['bruh', 'frustrated annoyed'],
        ['omg', 'surprised worried'],
        ['idk', 'i do not know confused'],
        ['btw', ''],
        ['pls', 'please'],
        ['plz', 'please'],
        ['thx', 'thanks'],
        ['ty', 'thank you'],
        ['tysm', 'thank you so much'],
    ];

    public static function expand(string $text): string
    {
        $work = mb_strtolower(trim($text), 'UTF-8');
        if ($work === '') {
            return $text;
        }

        foreach (self::PHRASE_MAP as [$from, $to]) {
            if ($to === '') {
                continue;
            }
            if (str_contains($work, $from)) {
                $work = str_replace($from, $to, $work);
            }
        }

        $work = preg_replace('/\s+/u', ' ', $work) ?? $work;
        return trim($work);
    }
}
