<?php
/**
 * Unified medical concept vocabulary — single source of truth for CDS NLP.
 *
 * Canonical concepts are defined in symptom_knowledge_base.json and extended via
 * canonical_symptom_aliases.csv. All datasets should map to these concepts.
 */

final class MedicalConceptRegistry
{
    /** @var array<string, array<string, mixed>>|null concept_id → record */
    private static ?array $byId = null;

    /** @var array<string, string>|null normalized alias → concept_id */
    private static ?array $aliasToId = null;

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $byCanonicalName = null;

    public static function clearCache(): void
    {
        self::$byId = null;
        self::$aliasToId = null;
        self::$byCanonicalName = null;
    }

    /** @return array<string, array<string, mixed>> */
    public static function concepts(): array
    {
        self::load();

        return self::$byId ?? [];
    }

    /**
     * Resolve any English/local phrase to a canonical concept.
     *
     * @return array{
     *   concept_id:string,
     *   canonical_name:string,
     *   medical_category:string,
     *   severity_weight:int,
     *   emergency_weight:int,
     *   urgent_weight:int,
     *   matched_alias:string
     * }|null
     */
    public static function resolve(string $term): ?array
    {
        $key = self::normKey($term);
        if ($key === '') {
            return null;
        }

        self::load();

        $conceptId = self::$aliasToId[$key] ?? null;
        if ($conceptId === null) {
            return null;
        }

        $concept = self::$byId[$conceptId] ?? null;
        if ($concept === null) {
            return null;
        }

        return [
            'concept_id'       => $conceptId,
            'canonical_name'   => (string) ($concept['canonical_name'] ?? ''),
            'medical_category' => (string) ($concept['medical_category'] ?? ''),
            'severity_weight'  => (int) ($concept['severity_weight'] ?? 0),
            'emergency_weight' => (int) ($concept['emergency_weight'] ?? 0),
            'urgent_weight'    => (int) ($concept['urgent_weight'] ?? 0),
            'matched_alias'    => $term,
        ];
    }

    /**
     * Return standardized English medical concept name, or original if unknown.
     */
    public static function canonicalize(string $term): string
    {
        $resolved = self::resolve($term);

        return $resolved !== null ? $resolved['canonical_name'] : trim($term);
    }

    /**
     * @return list<string>
     */
    public static function aliasesFor(string $conceptId): array
    {
        self::load();
        $concept = self::$byId[strtolower($conceptId)] ?? null;
        if ($concept === null) {
            return [];
        }

        return is_array($concept['aliases'] ?? null) ? $concept['aliases'] : [];
    }

    private static function load(): void
    {
        if (self::$byId !== null) {
            return;
        }

        self::$byId = [];
        self::$aliasToId = [];
        self::$byCanonicalName = [];

        foreach ((SymptomKnowledgeBase::load()['symptoms'] ?? []) as $symptom) {
            if (!is_array($symptom)) {
                continue;
            }
            $id = strtolower(trim((string) ($symptom['id'] ?? '')));
            $name = self::titleCase((string) ($symptom['symptom_name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }

            $aliases = [];
            foreach (['keywords', 'synonyms', 'hiligaynon_terms', 'filipino_terms'] as $field) {
                foreach (($symptom[$field] ?? []) as $t) {
                    $t = trim((string) $t);
                    if ($t !== '') {
                        $aliases[] = $t;
                    }
                }
            }
            $aliases[] = $name;
            $aliases[] = $id;
            $aliases[] = str_replace('_', ' ', $id);
            $aliases = array_values(array_unique($aliases));

            self::$byId[$id] = [
                'concept_id'       => $id,
                'canonical_name'   => $name,
                'medical_category' => (string) ($symptom['medical_category'] ?? ''),
                'severity_weight'  => (int) ($symptom['severity_weight'] ?? 0),
                'emergency_weight' => (int) ($symptom['emergency_weight'] ?? 0),
                'urgent_weight'    => (int) ($symptom['urgent_weight'] ?? 0),
                'danger_sign'      => (bool) ($symptom['danger_sign'] ?? false),
                'aliases'          => $aliases,
            ];
            self::$byCanonicalName[self::normKey($name)] = self::$byId[$id];

            foreach ($aliases as $alias) {
                $k = self::normKey($alias);
                if ($k !== '' && !isset(self::$aliasToId[$k])) {
                    self::$aliasToId[$k] = $id;
                }
            }
        }

        self::loadCanonicalAliases();
        self::loadRegistryCsv();
        self::loadStandardAliases();
    }

    private static function loadCanonicalAliases(): void
    {
        $path = BASE_PATH . '/data/nlp/canonical_symptom_aliases.csv';
        if (!is_readable($path)) {
            return;
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return;
        }
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine(
                array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
                array_map(static fn ($v) => trim((string) $v), $row)
            ) ?: [];
            $alias = (string) ($data['alias'] ?? '');
            $conceptId = strtolower((string) (($data['canonical_concept_id'] ?? '') ?: ($data['concept_id'] ?? '')));
            $status = strtolower((string) ($data['status'] ?? 'active'));
            if ($alias === '' || $conceptId === '' || ($status !== '' && $status !== 'active')) {
                continue;
            }
            if (!isset(self::$byId[$conceptId])) {
                continue;
            }
            $k = self::normKey($alias);
            if ($k !== '') {
                self::$aliasToId[$k] = $conceptId;
                self::$byId[$conceptId]['aliases'][] = $alias;
            }
        }
        fclose($handle);
    }

    private static function loadRegistryCsv(): void
    {
        $path = BASE_PATH . '/data/nlp/medical_concepts_registry.csv';
        if (!is_readable($path)) {
            return;
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return;
        }
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine(
                array_map(static fn ($h) => strtolower(trim((string) $h)), $header ?: []),
                array_map(static fn ($v) => trim((string) $v), $row)
            ) ?: [];
            $conceptId = strtolower((string) ($data['concept_id'] ?? ''));
            $alias = (string) ($data['alias'] ?? '');
            if ($conceptId === '' || $alias === '' || !isset(self::$byId[$conceptId])) {
                continue;
            }
            $k = self::normKey($alias);
            if ($k !== '' && !isset(self::$aliasToId[$k])) {
                self::$aliasToId[$k] = $conceptId;
            }
        }
        fclose($handle);
    }

    /** Built-in high-frequency alias normalization (breathing, chest pain, fever, etc.). */
    private static function loadStandardAliases(): void
    {
        $groups = [
            'difficulty_breathing' => [
                'difficulty breathing', 'shortness of breath', 'breathing problem', 'breathing difficulty',
                'respiratory difficulty', 'dyspnea', 'dyspnoea', 'sob', 'hard to breathe', 'hard breathing',
                'budlay ginhawa', 'budlay gid ginhawa', 'hirap huminga', 'hirap akong huminga',
                'cannot breathe', 'can\'t breathe', 'unable to breathe', 'trouble breathing',
            ],
            'chest_pain' => [
                'chest pain', 'pain in chest', 'pain chest', 'heavy chest', 'tight chest',
                'pressure in chest', 'chest pressure', 'masakit dughan', 'masakit dibdib',
                'masakit akon dughan', 'masakit ang dibdib ko', 'sakit dughan',
            ],
            'fever' => [
                'fever', 'high fever', 'mild fever', 'low grade fever', 'pyrexia', 'hyperthermia',
                'lagnat', 'hilanat', 'ginakalagnat', 'ginahilanat', 'nilalagnat', 'may lagnat',
            ],
            'headache' => [
                'headache', 'head pain', 'severe headache', 'mild headache', 'sakit ulo', 'masakit ulo',
            ],
            'vomiting' => [
                'vomiting', 'throwing up', 'nausea and vomiting', 'persistent vomiting', 'ginasuka', 'nagsusuka',
            ],
            'laceration' => [
                'laceration', 'cut', 'wound', 'hand wound', 'deep laceration', 'pilas', 'may pilas',
            ],
            'amputation' => [
                'amputation', 'finger amputation', 'hand amputation', 'severed limb', 'cut off',
                'putol ang kamot', 'putol ang kamay', 'naputol kamot', 'nautod',
            ],
        ];

        foreach ($groups as $conceptId => $aliases) {
            if (!isset(self::$byId[$conceptId])) {
                continue;
            }
            foreach ($aliases as $alias) {
                $k = self::normKey($alias);
                if ($k !== '') {
                    self::$aliasToId[$k] = $conceptId;
                }
            }
        }
    }

    private static function normKey(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return $s;
    }

    private static function titleCase(string $s): string
    {
        if ($s === strtolower($s)) {
            return ucwords($s);
        }

        return $s;
    }
}
