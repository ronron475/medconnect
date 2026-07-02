<?php
/**
 * Loads data/nlp/hiligaynon_symptom_lexicon.json — admin-expandable symptom lexicon.
 */
final class SymptomLexicon
{
    private static ?array $lexicon = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $variantIndex = null;

    /** @var list<string>|null */
    private static ?array $variantsByLength = null;

    public static function path(): string
    {
        return BASE_PATH . '/data/nlp/hiligaynon_symptom_lexicon.json';
    }

    public static function load(): array
    {
        if (self::$lexicon !== null) {
            return self::$lexicon;
        }

        self::$lexicon = [
            'version' => '0',
            'fuzzy_threshold' => 85,
            'symptoms' => [],
        ];

        $path = self::path();
        if (!is_readable($path)) {
            return self::$lexicon;
        }

        $raw = file_get_contents($path);
        $data = json_decode($raw ?: '', true);
        if (!is_array($data)) {
            return self::$lexicon;
        }

        $data['fuzzy_threshold'] = (int) ($data['fuzzy_threshold'] ?? 85);
        $data['symptoms'] = is_array($data['symptoms'] ?? null) ? $data['symptoms'] : [];
        self::$lexicon = $data;

        return self::$lexicon;
    }

    public static function fuzzyThreshold(): int
    {
        $lex = self::load();
        $raw = (int) ($lex['fuzzy_threshold'] ?? 85);
        $lo = (int) ($lex['fuzzy_threshold_min'] ?? 80);
        $hi = (int) ($lex['fuzzy_threshold_max'] ?? 90);

        return max($lo, min($hi, $raw));
    }

    /** @return array<string, array<string, mixed>> */
    public static function variantIndex(): array
    {
        if (self::$variantIndex !== null) {
            return self::$variantIndex;
        }

        self::$variantIndex = [];
        foreach (self::load()['symptoms'] as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $meta = [
                'symptom_key' => (string) $key,
                'english' => trim((string) ($entry['english'] ?? '')),
                'medical_term' => trim((string) ($entry['medical_term'] ?? $key)),
                'category' => trim((string) ($entry['category'] ?? 'general')),
            ];
            $variants = $entry['hiligaynon'] ?? [];
            $alt = $entry['alternate_spellings'] ?? [];
            if (is_array($alt)) {
                $variants = array_merge(is_array($variants) ? $variants : [], $alt);
            }
            foreach ($variants as $variant) {
                $norm = self::normalizeVariant((string) $variant);
                if ($norm === '' || isset(self::$variantIndex[$norm])) {
                    continue;
                }
                self::$variantIndex[$norm] = $meta + ['canonical_variant' => $norm];
            }
        }

        foreach (HiligaynonNlpDataset::termIndex() as $norm => $csvMeta) {
            if (isset(self::$variantIndex[$norm])) {
                continue;
            }
            self::$variantIndex[$norm] = [
                'symptom_key' => (string) ($csvMeta['medical_term'] ?? ''),
                'english' => (string) ($csvMeta['english'] ?? ''),
                'medical_term' => (string) ($csvMeta['medical_term'] ?? ''),
                'category' => (string) ($csvMeta['category'] ?? 'general'),
                'canonical_variant' => (string) ($csvMeta['canonical_variant'] ?? $norm),
                'severity' => (string) ($csvMeta['severity'] ?? ''),
                'body_system' => (string) ($csvMeta['body_system'] ?? ''),
            ];
        }

        foreach (HiligaynonPainRecognition::complaintIndex() as $norm => $painMeta) {
            if (isset(self::$variantIndex[$norm])) {
                continue;
            }
            self::$variantIndex[$norm] = [
                'symptom_key' => (string) ($painMeta['medical_term'] ?? ''),
                'english' => (string) ($painMeta['english'] ?? ''),
                'medical_term' => (string) ($painMeta['medical_term'] ?? ''),
                'category' => (string) ($painMeta['pain_category'] ?? 'pain'),
                'canonical_variant' => (string) ($painMeta['canonical_complaint'] ?? $norm),
                'body_part' => (string) ($painMeta['body_part'] ?? ''),
                'severity_level' => (string) ($painMeta['severity_level'] ?? ''),
            ];
        }

        foreach (HiligaynonMedicalKnowledgeBase::statementIndex() as $norm => $kbMeta) {
            if (isset(self::$variantIndex[$norm])) {
                continue;
            }
            self::$variantIndex[$norm] = [
                'symptom_key' => (string) ($kbMeta['medical_term'] ?? ''),
                'english' => (string) ($kbMeta['english'] ?? ''),
                'medical_term' => (string) ($kbMeta['medical_term'] ?? ''),
                'category' => (string) ($kbMeta['body_system'] ?? 'general'),
                'canonical_variant' => (string) ($kbMeta['canonical_statement'] ?? $norm),
                'urgency_level' => (string) ($kbMeta['urgency_level'] ?? ''),
                'body_system' => (string) ($kbMeta['body_system'] ?? ''),
                'icd_category' => (string) ($kbMeta['icd_category'] ?? ''),
            ];
        }

        foreach (HiligaynonPatientComplaints::complaintIndex() as $norm => $complaintMeta) {
            if (isset(self::$variantIndex[$norm])) {
                continue;
            }
            self::$variantIndex[$norm] = [
                'symptom_key' => (string) ($complaintMeta['medical_term'] ?? ''),
                'english' => (string) ($complaintMeta['english'] ?? ''),
                'medical_term' => (string) ($complaintMeta['medical_term'] ?? ''),
                'category' => (string) ($complaintMeta['body_system'] ?? 'general'),
                'canonical_variant' => (string) ($complaintMeta['canonical_complaint'] ?? $norm),
                'urgency_level' => (string) ($complaintMeta['urgency_level'] ?? ''),
                'body_system' => (string) ($complaintMeta['body_system'] ?? ''),
            ];
        }

        return self::$variantIndex;
    }

    /** @return list<string> */
    public static function variantsByLength(): array
    {
        if (self::$variantsByLength !== null) {
            return self::$variantsByLength;
        }

        $variants = array_keys(self::variantIndex());
        usort($variants, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        self::$variantsByLength = $variants;

        return self::$variantsByLength;
    }

    public static function normalizeVariant(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9\s\-]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /** @return array<string, mixed> */
    public static function stats(): array
    {
        $lex = self::load();
        $symptoms = $lex['symptoms'] ?? [];
        $categories = [];
        foreach ($symptoms as $entry) {
            if (is_array($entry)) {
                $categories[] = (string) ($entry['category'] ?? 'general');
            }
        }

        return [
            'version' => $lex['version'] ?? '0',
            'path' => self::path(),
            'symptom_count' => count($symptoms),
            'variant_count' => count(self::variantIndex()),
            'fuzzy_threshold' => self::fuzzyThreshold(),
            'categories' => array_values(array_unique($categories)),
            'nlp_dataset' => HiligaynonNlpDataset::stats(),
            'patient_complaints' => HiligaynonPatientComplaints::stats(),
            'pain_recognition' => HiligaynonPainRecognition::stats(),
            'medical_knowledge_base' => HiligaynonMedicalKnowledgeBase::stats(),
        ];
    }
}
