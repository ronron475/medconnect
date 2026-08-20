<?php
/**
 * Step 1: Detect Hiligaynon, Tagalog, English, or mixed patient language.
 */

final class HiligaynonLanguageDetector
{
    /** @var list<string> */
    private const HILIGAYNON_MARKERS = [
        'sakit', 'masakit', 'kirot', 'hapdi', 'ako', 'akon', 'ko', 'gid', 'man', 'subong',
        'kag', 'ang', 'nga', 'sa', 'sang', 'sing', 'daw', 'may', 'ara', 'halin', 'pirmi',
        'hubag', 'gahubag', 'gahabok', 'ubo', 'sipon', 'sip-on', 'tiyan', 'dughan', 'ulo',
        'mata', 'lawas', 'budlay', 'ginhawa', 'kalibanga', 'hilanat', 'lingin', 'nahilo',
        'unto', 'unud', 'dalunggan', 'tutunlan', 'ngipon', 'tiil', 'pilas', 'nanah',
        'kapoy', 'kusog', 'suka', 'pito', 'indi', 'wala', 'budlay', 'gin', 'nag', 'ga',
        'pareho', 'amuni', 'basin', 'dok', 'doktor', 'complain', 'grabe', 'malala',
    ];

    /** @var list<string> */
    private const TAGALOG_MARKERS = [
        'po', 'naman', 'talaga', 'kasi', 'lang', 'din', 'rin', 'yung', 'yun', 'yan',
        'ito', 'mga', 'ng', 'siya', 'niya', 'kanya',         'tayo', 'natin', 'kami', 'namin',
        'sila', 'nila', 'hindi', 'wala', 'meron', 'mayroon', 'parang', 'siguro', 'ba',
        'ho', 'opo', 'sakit', 'masakit', 'ubo', 'sipon', 'lagnat', 'hilo', 'nahihilo',
        'tiyan', 'dibdib', 'ulo', 'mata', 'kamay', 'paa', 'pagod', 'hingal', 'suka',
        'at', 'ang', 'ako', 'ko',
    ];

    /** @var list<string> */
    private const ENGLISH_MARKERS = [
        'the', 'and', 'with', 'have', 'has', 'had', 'pain', 'fever', 'cough', 'headache',
        'dizziness', 'breathing', 'chest', 'stomach', 'doctor', 'feel', 'feeling', 'my',
        'body', 'ache', 'hurts', 'hurt', 'symptom', 'symptoms', 'medical', 'help',
    ];

    /**
     * @return array{primary:string, tags:list<string>, is_local:bool, dominant?:string}
     */
    public static function detect(string $text): array
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return ['primary' => 'unknown', 'tags' => [], 'is_local' => false];
        }

        $hil = self::countMarkers($normalized, self::HILIGAYNON_MARKERS);
        $tag = self::countMarkers($normalized, self::TAGALOG_MARKERS);
        $eng = self::countMarkers($normalized, self::ENGLISH_MARKERS);

        $tags = [];
        if ($hil > 0) {
            $tags[] = 'hiligaynon';
        }
        if ($tag > 0) {
            $tags[] = 'tagalog';
        }
        if ($eng > 0) {
            $tags[] = 'english';
        }

        $compact = trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', '', $normalized));
        if ($compact === 'sakit') {
            return ['primary' => 'hiligaynon', 'tags' => ['hiligaynon'], 'is_local' => true, 'dominant' => 'hiligaynon'];
        }
        if ($compact === 'masakit') {
            return ['primary' => 'tagalog', 'tags' => ['tagalog'], 'is_local' => true, 'dominant' => 'tagalog'];
        }
        if (in_array($compact, ['it hurts', 'it hurt', 'hurts', 'hurt', 'pain', 'something hurts'], true)) {
            return ['primary' => 'english', 'tags' => ['english'], 'is_local' => false, 'dominant' => 'english'];
        }

        $dominant = 'hiligaynon';
        $hasKag = (bool) preg_match('/\bkag\b/u', $normalized);
        $hasAt = (bool) preg_match('/\bat\b/u', $normalized);
        if ($hasAt && !$hasKag && $tag > 0) {
            $dominant = 'tagalog';
        } elseif ($hil >= $tag && $hil >= $eng && $hil > 0) {
            $dominant = 'hiligaynon';
        } elseif ($tag >= $eng && $tag > 0) {
            $dominant = 'tagalog';
        } elseif ($eng > 0) {
            $dominant = 'english';
        }

        if (count($tags) >= 2) {
            return ['primary' => 'mixed', 'tags' => $tags, 'is_local' => true, 'dominant' => $dominant];
        }
        if ($hil > 0) {
            return ['primary' => 'hiligaynon', 'tags' => $tags, 'is_local' => true, 'dominant' => 'hiligaynon'];
        }
        if ($tag > 0) {
            return ['primary' => 'tagalog', 'tags' => $tags, 'is_local' => true, 'dominant' => 'tagalog'];
        }
        if ($eng > 0 || MedicalDictionary::isLikelyEnglish($normalized)) {
            return ['primary' => 'english', 'tags' => $tags ?: ['english'], 'is_local' => false, 'dominant' => 'english'];
        }

        return ['primary' => 'hiligaynon', 'tags' => ['hiligaynon'], 'is_local' => true, 'dominant' => 'hiligaynon'];
    }

    public static function primaryLanguage(string $text): string
    {
        return self::detect($text)['primary'];
    }

    public static function isLocalLanguage(string $text): bool
    {
        return self::detect($text)['is_local'];
    }

    /** @param list<string> $markers */
    private static function countMarkers(string $text, array $markers): int
    {
        $count = 0;
        foreach ($markers as $marker) {
            if (preg_match('/\b' . preg_quote($marker, '/') . '\b/u', $text)) {
                $count++;
            }
        }

        return $count;
    }
}
