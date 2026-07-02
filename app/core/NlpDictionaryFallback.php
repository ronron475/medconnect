<?php
/**
 * Dictionary-first fallback when Groq AI is unavailable.
 */

final class NlpDictionaryFallback
{
    /**
     * @param list<array<string, mixed>> $dictMatches
     * @param list<array<string, mixed>> $datasetMatches
     * @param list<string> $keywords
     * @return array<string, mixed>
     */
    public static function buildInterpretation(
        string $originalText,
        array $dictMatches,
        array $datasetMatches,
        array $keywords
    ): array {
        $englishTerms = [];
        $bodyParts = [];
        $symptoms = [];

        foreach ($dictMatches as $row) {
            $en = trim((string) ($row['english_term'] ?? ''));
            $local = trim((string) ($row['local_term'] ?? ''));
            if ($en === '') {
                continue;
            }
            if (mb_strlen($local) <= 12) {
                $englishTerms[] = $en;
            }
            self::classifyTerm($en, $local, $symptoms, $bodyParts);
        }

        foreach ($datasetMatches as $row) {
            $en = trim((string) ($row['english_term'] ?? ''));
            if ($en !== '' && !in_array($en, $englishTerms, true)) {
                $englishTerms[] = $en;
                self::classifyTerm($en, (string) ($row['local_term'] ?? ''), $symptoms, $bodyParts);
            }
        }

        foreach ($keywords as $kw) {
            $entry = MedicalDictionary::lookup($kw);
            if ($entry !== null) {
                $en = (string) $entry['english_term'];
                if (!in_array($en, $englishTerms, true)) {
                    $englishTerms[] = $en;
                    self::classifyTerm($en, $kw, $symptoms, $bodyParts);
                }
            }
        }

        $englishTerms = array_values(array_unique(array_filter($englishTerms)));
        $interpretation = self::composeSentence($originalText, $englishTerms, $symptoms, $bodyParts);

        $concepts = self::conceptsForTerms($englishTerms, $symptoms, $bodyParts);
        $confidence = 0;
        if ($concepts !== []) {
            $confidence = 95;
            foreach ($concepts as $concept) {
                if (($concept['confidence'] ?? 0) < 95) {
                    $confidence = 92;
                    break;
                }
            }
        }

        return [
            'status'                 => 'fallback',
            'provider'               => 'dictionary_fallback',
            'model'                  => null,
            'english_interpretation' => $interpretation,
            'confidence_score'       => $confidence,
            'concepts'               => $concepts,
            'notes'                  => 'Groq unavailable — built from medical dictionary and Hiligaynon dataset matches.',
            'detected_symptoms'      => array_values(array_unique($symptoms)),
            'detected_body_parts'    => array_values(array_unique($bodyParts)),
        ];
    }

    /** @param list<string> $symptoms @param list<string> $bodyParts */
    private static function classifyTerm(string $english, string $local, array &$symptoms, array &$bodyParts): void
    {
        $en = mb_strtolower($english);
        if (in_array($en, ['eye', 'ear', 'nose', 'throat', 'chest', 'head', 'stomach', 'skin', 'foot', 'hand', 'arm', 'leg'], true)) {
            $bodyParts[] = $english;
            return;
        }
        if (str_contains($en, 'eye') && !in_array('eye', $bodyParts, true)) {
            $bodyParts[] = 'eye';
        }
        $symptoms[] = $english;
    }

    /** @param list<string> $englishTerms @param list<string> $symptoms @param list<string> $bodyParts */
    private static function composeSentence(string $original, array $englishTerms, array $symptoms, array $bodyParts): string
    {
        $phraseEntry = MedicalDictionary::lookup(trim($original));
        if ($phraseEntry !== null) {
            $en = (string) $phraseEntry['english_term'];
            if (str_contains(mb_strtolower($en), 'pus') && str_contains(mb_strtolower($en), 'eye')) {
                return 'There is pus in my eye.';
            }
            if ($en !== '' && mb_strtolower($en) !== mb_strtolower($original)) {
                return self::naturalizePhrase($en);
            }
        }

        $hasPus = false;
        $hasEye = false;
        foreach ($englishTerms as $term) {
            $low = mb_strtolower($term);
            if (str_contains($low, 'pus') || $low === 'pus' || str_contains($low, 'discharge')) {
                $hasPus = true;
            }
            if ($low === 'eye' || str_contains($low, 'eye')) {
                $hasEye = true;
            }
        }

        if ($hasPus && $hasEye) {
            return 'There is pus in my eye.';
        }
        if ($englishTerms !== []) {
            return 'Patient reports: ' . implode(', ', $englishTerms) . '.';
        }

        return trim($original);
    }

    /** @return list<array<string, mixed>> */
    public static function conceptsForTerms(array $englishTerms, array $symptoms, array $bodyParts): array
    {
        $hasPus = false;
        $hasEye = false;
        foreach ($englishTerms as $term) {
            $low = mb_strtolower($term);
            if (str_contains($low, 'pus') || str_contains($low, 'discharge')) {
                $hasPus = true;
            }
            if ($low === 'eye' || str_contains($low, 'eye')) {
                $hasEye = true;
            }
        }
        foreach ($bodyParts as $part) {
            if (mb_strtolower($part) === 'eye') {
                $hasEye = true;
            }
        }

        $concepts = [];
        if ($hasPus && $hasEye) {
            foreach (['pus', 'eye discharge', 'purulent eye discharge'] as $symptomTerm) {
                $concepts[] = [
                    'term'       => $symptomTerm,
                    'type'       => 'symptom',
                    'body_part'  => 'eye',
                    'severity'   => null,
                    'duration'   => null,
                    'confidence' => 95,
                ];
            }
            $concepts[] = [
                'term'       => 'eye',
                'type'       => 'body_part',
                'body_part'  => 'eye',
                'severity'   => null,
                'duration'   => null,
                'confidence' => 95,
            ];
            return $concepts;
        }

        foreach ($englishTerms as $term) {
            $type = in_array(mb_strtolower($term), ['eye', 'ear', 'nose', 'throat', 'chest', 'head'], true)
                ? 'body_part' : 'symptom';
            if ($type === 'body_part') {
                continue;
            }
            $concepts[] = [
                'term'       => $term,
                'type'       => 'symptom',
                'body_part'  => $bodyParts[0] ?? null,
                'severity'   => null,
                'duration'   => null,
                'confidence' => 90,
            ];
        }
        foreach ($bodyParts as $part) {
            $concepts[] = [
                'term'       => $part,
                'type'       => 'body_part',
                'body_part'  => $part,
                'severity'   => null,
                'duration'   => null,
                'confidence' => 90,
            ];
        }

        return $concepts;
    }

    private static function naturalizePhrase(string $english): string
    {
        $map = [
            'pus in eye' => 'There is pus in my eye.',
            'eye discharge' => 'There is discharge from my eye.',
        ];
        $key = mb_strtolower(trim($english));
        return $map[$key] ?? ucfirst($english) . '.';
    }
}
