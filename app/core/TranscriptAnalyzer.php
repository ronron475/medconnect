<?php
/**
 * PHP fallback when the Python AI service is unavailable.
 */

final class TranscriptAnalyzer
{
    private const DICTIONARY = [
        'sakit ulo' => 'headache',
        'labad ulo' => 'headache',
        'ginahilanat' => 'fever',
        'hilanat' => 'fever',
        'ubo' => 'cough',
        'sip-on' => 'runny nose',
        'sip on' => 'runny nose',
        'sakit dughan' => 'chest pain',
        'hapdi dughan' => 'chest pain',
        'ginabudlayan ginhawa' => 'difficulty breathing',
        'budlay ginhawa' => 'difficulty breathing',
        'kalipong' => 'dizziness',
        'nagalipong' => 'dizziness',
        'suka' => 'vomiting',
        'nagsuka' => 'vomiting',
        'kalibanga' => 'diarrhea',
        'sakit tiyan' => 'stomach pain',
        'panakit tiyan' => 'stomach pain',
        'sakit tutunlan' => 'sore throat',
        'hubag' => 'swelling',
        'katol' => 'itching',
        'rashes' => 'rash',
        'kakapoy' => 'fatigue',
        'ginakapoy' => 'fatigue',
    ];

    private const SYMPTOMS = [
        'fever', 'cough', 'headache', 'chest pain', 'difficulty breathing',
        'shortness of breath', 'dizziness', 'vomiting', 'diarrhea',
        'stomach pain', 'abdominal pain', 'sore throat', 'rash', 'swelling',
        'itching', 'fatigue', 'body pain', 'back pain', 'nausea',
    ];

    private const MEDICINES = [
        'paracetamol', 'biogesic', 'amoxicillin', 'ibuprofen', 'mefenamic',
        'cetirizine', 'loratadine', 'salbutamol', 'metformin', 'amlodipine',
        'losartan', 'omeprazole', 'aspirin',
    ];

    private const URGENT = [
        'chest pain', 'difficulty breathing', 'shortness of breath',
        'severe bleeding', 'unconscious', 'seizure',
    ];

    /** @return array<string, string> */
    public static function getHiligaynonDictionary(): array
    {
        return self::DICTIONARY;
    }

    public static function analyze(string $transcript): array
    {
        $recognition = HiligaynonSymptomMatcher::recognize($transcript);
        $english = mb_strtolower($transcript);
        foreach (self::DICTIONARY as $hil => $en) {
            $english = str_ireplace($hil, $en, $english);
        }

        $symptoms = self::matchTerms($english, self::SYMPTOMS);
        foreach ($recognition['english_symptoms'] ?? [] as $s) {
            if ($s && !in_array($s, $symptoms, true)) {
                $symptoms[] = $s;
            }
        }
        $medicines = self::matchTerms($english, self::MEDICINES);
        $urgent = self::matchTerms($english, self::URGENT);

        $summary = 'Possible symptoms: ' . ($symptoms ? implode(', ', $symptoms) : 'none detected') . '.';
        if ($medicines) {
            $summary .= ' Mentioned medicines: ' . implode(', ', $medicines) . '.';
        }
        if ($urgent) {
            $summary .= ' Urgent cues detected: ' . implode(', ', $urgent) . '.';
        }

        return [
            'hiligaynon_transcript' => $transcript,
            'english_transcript'    => $english,
            'symptoms'              => $symptoms,
            'symptom_detections'    => $recognition['detections'] ?? [],
            'symptom_recognition'   => [
                'normalized_text' => $recognition['normalized_text'] ?? '',
                'cleaned_text' => $recognition['cleaned_text'] ?? '',
                'fuzzy_threshold' => $recognition['fuzzy_threshold'] ?? SymptomLexicon::fuzzyThreshold(),
                'detection_count' => $recognition['detection_count'] ?? 0,
                'lexicon' => $recognition['lexicon'] ?? SymptomLexicon::stats(),
            ],
            'medicines'             => $medicines,
            'urgent_flags'          => $urgent,
            'summary'               => $summary,
            'engine'                => 'php-fallback-analyzer',
        ];
    }

    private static function matchTerms(string $text, array $terms): array
    {
        $found = [];
        foreach ($terms as $term) {
            if (str_contains($text, $term)) {
                $found[] = $term;
            }
        }
        return array_values(array_unique($found));
    }
}
