<?php
/**
 * Expands Hiligaynon chat shorthand / informal spellings for emotion NLP.
 */
final class FaqChatbotEmotionShorthand
{
    /** @var list<array{0: string, 1: string}> longest match first */
    private const PHRASE_MAP = [
        ['sakit kag d nko kaginhawa', 'sakit kag indi ko kaginhawa'],
        ['gakulbaan ko magpa check up', 'gakulbaan ko magpa-check up anxious'],
        ['gakulbaan ko magpa check', 'gakulbaan ko magpa-check anxious'],
        ['indi ko kasabat', 'indi ko masabtan confused'],
        ['wla signal', 'wala signal connectivity'],
        ['wla internet', 'wala internet connectivity'],
        ['gaulan kag wla signal', 'gaulan kag wala signal weather connectivity'],
        ['putol putol ang connection', 'putol-putol ang connection connectivity'],
        ['ga lag ang video', 'ga-lag ang video connectivity'],
        ['masaligan ni bala', 'masaligan ni bala trustworthy safe'],
        ['safe bala ni', 'safe bala ni secure safe'],
        ['tabangi ko bi', 'tabangi ko please help'],
        ['buligi ko bi', 'buligi ko please help'],
        ['ano ubrahon ko', 'ano himuon ko what should i do'],
        ['di ko na kaya', 'cannot take it anymore hopeless distressed'],
        ['wla na pulos', 'wala na pulos hopeless'],
        ['mabuhi pa ko', 'will i survive worried'],
        ['hays', 'sigh sad tired'],
        ['hay naku', 'oh no sigh sad'],
        ['gakulbaan', 'anxious nervous'],
        ['ginakapoy', 'tired exhausted kapoy'],
        ['gaulan', 'raining rain weather'],
        ['nadula signal', 'lost signal connectivity'],
        ['gadula signal', 'dropping signal connectivity'],
        ['hinay signal', 'weak signal connectivity'],
        ['pamasahe', 'fare transportation money'],
        ['masakyan', 'ride transportation'],
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
