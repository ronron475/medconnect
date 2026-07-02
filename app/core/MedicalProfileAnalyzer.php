<?php
/**
 * PHP fallback for registration step 3 medical text (allergies + medications).
 */

final class MedicalProfileAnalyzer
{
    private const ALLERGIES = [
        'penicillin', 'amoxicillin', 'augmentin', 'sulfa', 'sulfamethoxazole',
        'sulfonamide', 'aspirin', 'ibuprofen', 'naproxen', 'codeine', 'morphine',
        'latex', 'shellfish', 'seafood', 'shrimp', 'crab', 'fish', 'peanut',
        'peanuts', 'tree nut', 'almond', 'walnut', 'cashew', 'egg', 'eggs',
        'milk', 'dairy', 'lactose', 'soy', 'wheat', 'gluten', 'corn', 'banana',
        'kiwi', 'celery', 'mustard', 'sesame', 'pollen', 'dust mite', 'mold',
        'pet dander', 'iodine', 'contrast dye', 'nickel', 'coconut', 'bee sting',
    ];

    private const MEDICINES = [
        'paracetamol', 'biogesic', 'amoxicillin', 'ibuprofen', 'mefenamic',
        'cetirizine', 'loratadine', 'salbutamol', 'metformin', 'amlodipine',
        'losartan', 'omeprazole', 'aspirin', 'insulin', 'atorvastatin',
        'simvastatin', 'enalapril', 'captopril', 'hydrochlorothiazide',
        'prednisone', 'dexamethasone', 'azithromycin', 'ciprofloxacin',
        'doxycycline', 'vitamin c', 'multivitamin',
    ];

    private const SKIP = ['none', 'n/a', 'na', 'wala', 'none known', 'no known allergies', 'walang allergy'];

    public static function analyze(string $allergies, string $medications): array
    {
        $allergies   = trim($allergies);
        $medications = trim($medications);

        $pipeline = MedicalValidationWorkflow::run($allergies, $medications);
        $preprocessing = $pipeline['preprocessing'];
        $translation = $pipeline['translation'];
        $fuzzyMatching = $pipeline['fuzzy_matching'];
        $datasetValidation = $pipeline['dataset_validation'];
        $invalidDetection = $pipeline['invalid_entry_detection'];

        $allergyWork = $preprocessing['allergies']['keywords_text'] ?? $preprocessing['allergies']['cleaned'] ?? '';
        $conditionWork = $preprocessing['conditions']['keywords_text'] ?? $preprocessing['conditions']['cleaned'] ?? '';

        $englishAllergies   = (string) ($translation['allergies']['english_text'] ?? '');
        $englishMedications = (string) ($translation['conditions']['english_text'] ?? '');
        if ($englishAllergies === '') {
            $englishAllergies = self::translate($allergyWork ?: $allergies);
        }
        if ($englishMedications === '') {
            $englishMedications = self::translate($conditionWork ?: $medications);
        }

        $knownAllergies = self::matchTerms($englishAllergies, self::ALLERGIES);
        $knownMedicines = self::matchTerms($englishMedications, self::MEDICINES);
        $parsedAllergies   = self::parseFreeform($allergyWork ?: $allergies);
        $parsedMedications = self::parseFreeform($conditionWork ?: $medications);

        $summaryParts = [];
        if ($knownAllergies) {
            $summaryParts[] = 'Known allergies: ' . implode(', ', $knownAllergies) . '.';
        } elseif ($parsedAllergies) {
            $summaryParts[] = 'Parsed allergy entries: ' . implode(', ', array_slice($parsedAllergies, 0, 6)) . '.';
        } else {
            $summaryParts[] = 'No allergies detected.';
        }

        if ($knownMedicines) {
            $summaryParts[] = 'Known medicines: ' . implode(', ', $knownMedicines) . '.';
        } elseif ($parsedMedications) {
            $summaryParts[] = 'Parsed medication entries: ' . implode(', ', array_slice($parsedMedications, 0, 6)) . '.';
        } else {
            $summaryParts[] = 'No medications detected.';
        }

        return [
            'allergies_text'       => $allergies,
            'medications_text'     => $medications,
            'english_allergies'    => $englishAllergies,
            'english_medications'  => $englishMedications,
            'known_allergies'      => $knownAllergies,
            'known_medicines'      => $knownMedicines,
            'parsed_allergies'     => $parsedAllergies,
            'parsed_medications'   => $parsedMedications,
            'noun_phrases'         => [],
            'summary'              => implode(' ', $summaryParts),
            'engine'               => 'php-fallback-medical-profile',
            'preprocessing'        => $preprocessing,
            'translation'          => $translation,
            'fuzzy_matching'       => $fuzzyMatching,
            'dataset_validation'   => $datasetValidation,
            'invalid_entry_detection' => $invalidDetection,
            'term_results'         => $pipeline['term_results'] ?? [],
            'workflow'             => $pipeline['workflow'] ?? null,
            'translated_keywords'  => [
                'allergies'  => NlpPreprocessor::translateKeywords($preprocessing['allergies']['keywords']),
                'conditions' => NlpPreprocessor::translateKeywords($preprocessing['conditions']['keywords']),
            ],
        ];
    }

    private static function translate(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $map = array_merge(
            TranscriptAnalyzer::getHiligaynonDictionary(),
            MedicalDictionary::localToEnglish()
        );
        uksort($map, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $english = mb_strtolower($text);
        foreach ($map as $hil => $en) {
            $english = str_ireplace($hil, $en, $english);
        }
        return $english;
    }

    private static function matchTerms(string $text, array $terms): array
    {
        $found = [];
        foreach ($terms as $term) {
            if (preg_match('/(?<!\w)' . preg_quote($term, '/') . '(?!\w)/i', $text)) {
                $found[] = $term;
            }
        }
        return array_values(array_unique($found));
    }

    private static function parseFreeform(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }
        $normalized = preg_replace('/\s+(?:and|og|kag|ka|ug)\s+/i', ', ', $text);
        $parts      = preg_split('/[,;\n]+/', (string) $normalized);
        $items      = [];
        foreach ($parts as $part) {
            $cleaned = trim(preg_replace('/\s+/', ' ', $part), " .-");
            if ($cleaned === '') {
                continue;
            }
            if (in_array(mb_strtolower($cleaned), self::SKIP, true)) {
                continue;
            }
            $items[] = $cleaned;
        }
        return $items;
    }
}
