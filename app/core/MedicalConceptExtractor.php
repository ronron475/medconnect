<?php
/**
 * Extract medical concepts from English translations for dataset lookup.
 * Dataset matching must only use English — never raw Hiligaynon.
 */

final class MedicalConceptExtractor
{
    /**
     * @return list<array{
     *   english:string,
     *   medical_keyword:string,
     *   category:string,
     *   body_part:string
     * }>
     */
    public static function extractFromTranslation(array $translation): array
    {
        $english = trim((string) ($translation['english'] ?? ''));
        if ($english === '') {
            return [];
        }

        $keyword = trim((string) ($translation['medical_keyword'] ?? $english));
        $category = (string) ($translation['category'] ?? 'symptom');
        $bodyPart = (string) ($translation['body_part'] ?? '');

        $concepts = [[
            'english'         => $english,
            'medical_keyword' => $keyword !== '' ? $keyword : $english,
            'category'        => self::normalizeCategory($category),
            'body_part'       => $bodyPart,
        ]];

        foreach (self::splitPhrases($english) as $phrase) {
            $phrase = trim($phrase);
            if ($phrase === '' || mb_strtolower($phrase) === mb_strtolower($english)) {
                continue;
            }
            $canonical = BodyPartPainSymptoms::canonicalEnglish($phrase);
            $concepts[] = [
                'english'         => $canonical,
                'medical_keyword' => $canonical,
                'category'        => 'symptom',
                'body_part'       => '',
            ];
        }

        $seen = [];
        $unique = [];
        foreach ($concepts as $concept) {
            $key = mb_strtolower($concept['english']);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $concept;
        }

        return $unique;
    }

    /**
     * Enriched medical concepts with symptom keyword, classification, body part.
     *
     * @param array{english:string, medical_keyword?:string, category?:string, body_part?:string, source?:string} $translation
     * @return list<array<string, mixed>>
     */
    public static function enrichFromTranslation(array $translation): array
    {
        $base = self::extractFromTranslation($translation);
        $enriched = [];

        foreach ($base as $concept) {
            $english = $concept['english'];
            $keyword = $concept['medical_keyword'];
            $bodyPart = $concept['body_part'] !== ''
                ? $concept['body_part']
                : self::inferBodyPart($english, $keyword);

            $enriched[] = [
                'english'          => $english,
                'medical_keyword'  => $keyword,
                'symptom'          => self::symptomLabel($english, $keyword),
                'body_part'        => $bodyPart,
                'category'         => self::normalizeCategory($concept['category']),
                'classification'   => self::classifySingle($english, $keyword),
            ];
        }

        return $enriched;
    }

    /**
     * @param list<array<string, mixed>> $concepts
     * @param array<string, mixed> $phraseTranslation
     * @return array{category:string, classifications:list<string>}
     */
    public static function classify(array $concepts, array $phraseTranslation): array
    {
        $classifications = [];
        foreach ($concepts as $concept) {
            $classifications[] = (string) ($concept['classification'] ?? 'symptom');
        }
        $classifications = array_values(array_unique(array_filter($classifications)));

        $category = $classifications[0] ?? 'symptom';
        if (in_array('emergency', $classifications, true)) {
            $category = 'emergency';
        } elseif (in_array('pain', $classifications, true)) {
            $category = 'pain';
        } elseif (in_array('infection', $classifications, true)) {
            $category = 'infection';
        }

        return [
            'category'        => $category,
            'classifications' => $classifications,
        ];
    }

    private static function classifySingle(string $english, string $keyword): string
    {
        $hay = mb_strtolower($english . ' ' . $keyword);
        if (preg_match('/\b(cannot breathe|emergency|severe bleeding|heart attack|collapse|unconscious)\b/u', $hay)) {
            return 'emergency';
        }
        if (preg_match('/\b(infected|pus|abscess|sepsis)\b/u', $hay)) {
            return 'infection';
        }
        if (preg_match('/\b(pain|ache|headache|sakit)\b/u', $hay)) {
            return 'pain';
        }
        if (preg_match('/\b(wound|injury|cut|laceration|pilas)\b/u', $hay)) {
            return 'injury';
        }
        if (preg_match('/\b(anxiety|depression|stress|kulba)\b/u', $hay)) {
            return 'mental_health';
        }

        return 'symptom';
    }

    private static function symptomLabel(string $english, string $keyword): string
    {
        $map = [
            'headache'              => 'headache',
            'dizziness'             => 'dizziness',
            'eye pain'              => 'eye pain',
            'swollen gums'          => 'gum swelling',
            'infected wound'        => 'infected wound',
            'difficulty breathing'  => 'dyspnea',
            'shortness of breath'   => 'dyspnea',
            'chest pain'            => 'chest pain',
            'fatigue'               => 'fatigue',
            'weakness'              => 'weakness',
        ];
        $key = mb_strtolower($english);

        return $map[$key] ?? $keyword;
    }

    private static function inferBodyPart(string $english, string $keyword): string
    {
        $hay = mb_strtolower($english . ' ' . $keyword);
        $parts = [
            'gums' => ['gum', 'gums', 'unto', 'ngipon'],
            'head' => ['head', 'ulo', 'headache'],
            'eyes' => ['eye', 'mata'],
            'chest' => ['chest', 'dughan', 'dibdib'],
            'abdomen' => ['stomach', 'abdominal', 'tiyan'],
            'foot' => ['foot', 'tiil', 'talampakan'],
            'body' => ['body', 'lawas'],
            'respiratory' => ['breath', 'ginhawa', 'ubo', 'cough'],
        ];
        foreach ($parts as $part => $needles) {
            foreach ($needles as $needle) {
                if (mb_strpos($hay, $needle) !== false) {
                    return $part;
                }
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    public static function splitPhrases(string $english): array
    {
        $parts = preg_split('/\s*(?:,|;| and | kag | og | ug )\s*/iu', $english) ?: [];

        return array_values(array_filter(array_map('trim', $parts)));
    }

    public static function normalizeCategory(string $category): string
    {
        $key = mb_strtolower(trim($category));
        $map = [
            'pain'           => 'symptom',
            'ocular pain'    => 'symptom',
            'neurological'   => 'symptom',
            'cardiovascular' => 'symptom',
            'respiratory'    => 'symptom',
            'gastrointestinal' => 'symptom',
            'dermatological' => 'symptom',
            'integumentary'  => 'symptom',
            'general'        => 'symptom',
            'skin'           => 'symptom',
        ];

        return $map[$key] ?? ($key !== '' ? $key : 'symptom');
    }
}
