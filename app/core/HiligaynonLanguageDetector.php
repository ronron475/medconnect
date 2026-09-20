<?php
/**
 * Step 1: Detect Hiligaynon, Tagalog, English, or mixed patient language.
 */

final class HiligaynonLanguageDetector
{
    /**
     * Shared / cross-language tokens — useful for “local” detection but must NOT
     * decide Hiligaynon vs Tagalog dominance alone (e.g. masakit/ang/ulo/ko).
     *
     * @var list<string>
     */
    private const SHARED_MARKERS = [
        'sakit', 'masakit', 'ulo', 'mata', 'tiyan', 'ko', 'ako', 'ang', 'sa', 'wala',
        'ubo', 'sipon', 'suka', 'may', 'doktor', 'doctor', 'grabe', 'malala',
    ];

    /** @var list<string> */
    private const HILIGAYNON_EXCLUSIVE = [
        'gid', 'kag', 'sang', 'nga', 'akon', 'indi', 'hilanat', 'dughan', 'tiil',
        'budlay', 'ginhawa', 'subong', 'ara', 'halin', 'pirmi', 'sing', 'daw',
        'kalibanga', 'lingin', 'nahilo', 'kapoy', 'amuni', 'basin', 'unto', 'unud',
        'dalunggan', 'tutunlan', 'ngipon', 'pilas', 'nanah', 'kusog', 'hubag',
        'gahubag', 'gahabok', 'sip-on',
    ];

    /** @var list<string> */
    private const TAGALOG_EXCLUSIVE = [
        'po', 'naman', 'talaga', 'kasi', 'lang', 'din', 'rin', 'yung', 'yun', 'yan',
        'ito', 'mga', 'ng', 'siya', 'niya', 'kanya', 'tayo', 'natin', 'kami', 'namin',
        'sila', 'nila', 'hindi', 'meron', 'mayroon', 'parang', 'siguro', 'ba', 'ho',
        'opo', 'lagnat', 'hilo', 'nahihilo', 'dibdib', 'kamay', 'paa', 'pagod',
        'hingal', 'at', 'aking', 'nyo', 'niyo',
    ];

    /** @var list<string> */
    private const ENGLISH_MARKERS = [
        'the', 'and', 'with', 'have', 'has', 'had', 'pain', 'fever', 'cough', 'headache',
        'dizziness', 'breathing', 'chest', 'stomach', 'doctor', 'feel', 'feeling', 'my',
        'body', 'ache', 'hurts', 'hurt', 'symptom', 'symptoms', 'medical', 'help',
        'head', 'weeks', 'week', 'days', 'day', 'severe', 'mild', 'since', 'ago', 'for',
        'cannot', "can't", 'foot', 'arm', 'leg',
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

        $hilEx = self::countMarkers($normalized, self::HILIGAYNON_EXCLUSIVE);
        $tagEx = self::countMarkers($normalized, self::TAGALOG_EXCLUSIVE);
        $shared = self::countMarkers($normalized, self::SHARED_MARKERS);
        $eng = self::countMarkers($normalized, self::ENGLISH_MARKERS);
        // Inclusive totals for tag membership (exclusive + shared).
        $hil = $hilEx + $shared;
        $tag = $tagEx + $shared;

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

        $dominant = self::resolveDominant($normalized, $hilEx, $tagEx, $shared, $eng);

        if (count($tags) >= 2) {
            return ['primary' => 'mixed', 'tags' => $tags, 'is_local' => true, 'dominant' => $dominant];
        }
        if ($hilEx > 0 || ($shared > 0 && $dominant === 'hiligaynon' && $eng === 0)) {
            if ($hil > 0 && $eng === 0) {
                return ['primary' => 'hiligaynon', 'tags' => $tags ?: ['hiligaynon'], 'is_local' => true, 'dominant' => 'hiligaynon'];
            }
        }
        if ($tagEx > 0 || ($shared > 0 && $dominant === 'tagalog' && $eng === 0)) {
            if ($tag > 0 && $eng === 0) {
                return ['primary' => 'tagalog', 'tags' => $tags ?: ['tagalog'], 'is_local' => true, 'dominant' => 'tagalog'];
            }
        }
        if ($hil > 0 && $dominant === 'hiligaynon') {
            return ['primary' => 'hiligaynon', 'tags' => $tags, 'is_local' => true, 'dominant' => 'hiligaynon'];
        }
        if ($tag > 0 && $dominant === 'tagalog') {
            return ['primary' => 'tagalog', 'tags' => $tags, 'is_local' => true, 'dominant' => 'tagalog'];
        }
        if ($eng > 0 || MedicalDictionary::isLikelyEnglish($normalized)) {
            return ['primary' => 'english', 'tags' => $tags ?: ['english'], 'is_local' => false, 'dominant' => 'english'];
        }

        return ['primary' => 'hiligaynon', 'tags' => ['hiligaynon'], 'is_local' => true, 'dominant' => 'hiligaynon'];
    }

    private static function resolveDominant(
        string $normalized,
        int $hilEx,
        int $tagEx,
        int $shared,
        int $eng
    ): string {
        // Exclusive particles decide first (universal — not sentence lists).
        if ($hilEx > $tagEx && $hilEx >= $eng) {
            return 'hiligaynon';
        }
        if ($tagEx > $hilEx && $tagEx >= $eng) {
            return 'tagalog';
        }
        if ($eng > $hilEx && $eng > $tagEx && $eng > 0) {
            return 'english';
        }

        $hasKag = (bool) preg_match('/\bkag\b/u', $normalized);
        $hasAt = (bool) preg_match('/\bat\b/u', $normalized);
        $hasAng = (bool) preg_match('/\bang\b/u', $normalized);
        $hasHilParticle = (bool) preg_match('/\b(sang|nga|gid|akon|indi|kag)\b/u', $normalized);
        $hasTagParticle = (bool) preg_match('/\b(ng|mga|po|naman|hindi|aking)\b/u', $normalized);

        if ($hasAt && !$hasKag && ($tagEx > 0 || $shared > 0)) {
            return 'tagalog';
        }
        // Shared-vocab Tagalog shape: "masakit ang ulo ko" (ang … ko, no Hiligaynon particles).
        if ($hasAng && !$hasHilParticle && ($shared > 0 || $tagEx > 0)) {
            return 'tagalog';
        }
        if ($hasHilParticle && !$hasTagParticle) {
            return 'hiligaynon';
        }
        if ($hilEx === $tagEx && $shared > 0 && $eng === 0) {
            return $hasAng ? 'tagalog' : 'hiligaynon';
        }
        if ($eng > 0) {
            return 'english';
        }

        return $hilEx >= $tagEx ? 'hiligaynon' : 'tagalog';
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
