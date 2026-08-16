<?php
/**
 * Philippine National ID (PhilSys) OCR field extraction and confidence scoring.
 * Single source of truth for ALL National ID uploads: registration auto-fill,
 * verify residency, PDF/JPG/PNG, PHP OCR.Space pipeline, and FastAPI /ocr/extract.
 */

final class PhilSysOcrParser
{
    /** Minimum average confidence for required fields to auto-fill */
    public const CONFIDENCE_THRESHOLD = 0.78;

    /** Per-field minimum for required identity fields */
    public const FIELD_MIN_CONFIDENCE = 0.82;

    /** @var array<string, string> */
    private static array $monthMap = [
        'january' => '01', 'february' => '02', 'march' => '03', 'april' => '04',
        'may' => '05', 'june' => '06', 'july' => '07', 'august' => '08',
        'september' => '09', 'october' => '10', 'november' => '11', 'december' => '12',
        'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04',
        'jun' => '06', 'jul' => '07', 'aug' => '08', 'sep' => '09',
        'oct' => '10', 'nov' => '11', 'dec' => '12',
    ];

    /**
     * Extract all registration fields from raw OCR text.
     *
     * @return array{
     *   fields: array<string, array{value: string, confidence: float, source: string}>,
     *   overall_confidence: float,
     *   low_confidence: bool,
     *   raw_text: string
     * }
     */
    public static function extractAll(string $rawText): array
    {
        $rawText = trim($rawText);
        $names   = self::extractNameFields($rawText);
        $dob     = self::extractDateOfBirth($rawText);
        $id      = self::extractNationalId($rawText);
        $address = self::extractAddress($rawText);

        $fields = [
            'first_name' => self::field($names['first'], $names['first_confidence'], $names['first_source']),
            'middle_name' => self::field($names['middle'], $names['middle_confidence'], $names['middle_source']),
            'last_name' => self::field($names['last'], $names['last_confidence'], $names['last_source']),
            'date_of_birth' => self::field($dob['value'], $dob['confidence'], $dob['source']),
            'national_id' => self::field($id['value'], $id['confidence'], $id['source']),
            'address' => self::field($address['value'], $address['confidence'], $address['source']),
        ];

        $required = ['first_name', 'last_name', 'date_of_birth', 'national_id'];
        $scores   = [];
        foreach ($required as $key) {
            if (($fields[$key]['value'] ?? '') !== '') {
                $scores[] = (float) ($fields[$key]['confidence'] ?? 0);
            }
        }

        $overall = !empty($scores) ? array_sum($scores) / count($scores) : 0.0;
        $low     = $overall < self::CONFIDENCE_THRESHOLD
            || $fields['first_name']['value'] === ''
            || $fields['last_name']['value'] === ''
            || $fields['date_of_birth']['value'] === ''
            || $fields['national_id']['value'] === '';

        $result = [
            'fields' => $fields,
            'overall_confidence' => round($overall, 3),
            'low_confidence' => $low,
            'raw_text' => $rawText,
        ];

        return self::finalizeExtraction($result);
    }

    /**
     * Merge multiple OCR passes (different engines / rotations) for best accuracy.
     *
     * @param list<string> $rawTexts
     * @return array{fields: array, overall_confidence: float, low_confidence: bool, raw_text: string}
     */
    public static function extractAllFromPasses(array $rawTexts): array
    {
        $rawTexts = array_values(array_filter(array_map(static fn ($t) => trim((string) $t), $rawTexts)));
        if ($rawTexts === []) {
            return self::extractAll('');
        }

        $extractions = [];
        foreach ($rawTexts as $text) {
            $extractions[] = self::extractAll($text);
        }

        if (count($extractions) === 1) {
            return $extractions[0];
        }

        $consensus = self::consensusFromExtractions($extractions);
        $mergedText = self::mergeRawOcrTexts($rawTexts);
        $fromMerged = self::extractAll($mergedText);

        return self::pickBetterExtraction($consensus, $fromMerged);
    }

    /**
     * @param list<string> $texts
     */
    public static function mergeRawOcrTexts(array $texts): string
    {
        $seen = [];
        $ordered = [];
        foreach ($texts as $raw) {
            foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $key = strtolower(preg_replace('/\s+/', ' ', $line));
                if (isset($seen[$key])) {
                    $seen[$key]++;
                    continue;
                }
                $seen[$key] = 1;
                $ordered[] = $line;
            }
        }
        return implode("\n", $ordered);
    }

    /**
     * @param list<array{fields: array, overall_confidence: float, low_confidence: bool, raw_text: string}> $extractions
     */
    public static function consensusFromExtractions(array $extractions): array
    {
        $fieldKeys = ['first_name', 'middle_name', 'last_name', 'date_of_birth', 'national_id', 'address'];
        $fields = [];

        foreach ($fieldKeys as $key) {
            $votes = [];
            foreach ($extractions as $ext) {
                $f = $ext['fields'][$key] ?? null;
                if (!is_array($f)) {
                    continue;
                }
                $val = trim((string) ($f['value'] ?? ''));
                if ($val === '') {
                    continue;
                }
                $norm = self::normalizeFieldValueForVote($key, $val);
                if ($norm === '') {
                    continue;
                }
                if (!isset($votes[$norm])) {
                    $votes[$norm] = ['value' => $val, 'count' => 0, 'conf' => 0.0, 'source' => (string) ($f['source'] ?? 'consensus')];
                }
                $votes[$norm]['count']++;
                $votes[$norm]['conf'] += (float) ($f['confidence'] ?? 0);
            }

            if ($votes === []) {
                $fields[$key] = self::field('', 0.0, 'none');
                continue;
            }

            uasort($votes, static function ($a, $b) {
                if ($a['count'] !== $b['count']) {
                    return $b['count'] <=> $a['count'];
                }
                return $b['conf'] <=> $a['conf'];
            });

            $winner = reset($votes);
            $avgConf = $winner['conf'] / max(1, $winner['count']);
            $boost = min(0.06, ($winner['count'] - 1) * 0.03);
            $display = self::formatConsensusFieldValue($key, $winner['value']);
            $fields[$key] = self::field(
                $display,
                min(0.99, $avgConf + $boost),
                'consensus'
            );
        }

        $raw = self::mergeRawOcrTexts(array_column($extractions, 'raw_text'));
        $draft = [
            'fields' => $fields,
            'overall_confidence' => 0.0,
            'low_confidence' => true,
            'raw_text' => $raw,
        ];

        return self::finalizeExtraction($draft);
    }

    /**
     * @param array{fields: array, overall_confidence: float, low_confidence: bool, raw_text: string} $a
     * @param array{fields: array, overall_confidence: float, low_confidence: bool, raw_text: string} $b
     */
    private static function pickBetterExtraction(array $a, array $b): array
    {
        return self::extractionQualityScore($a) >= self::extractionQualityScore($b) ? $a : $b;
    }

    /**
     * @param array{fields: array, overall_confidence: float, low_confidence: bool, raw_text: string} $extraction
     */
    private static function extractionQualityScore(array $extraction): float
    {
        $fields = $extraction['fields'] ?? [];
        $required = ['first_name', 'last_name', 'date_of_birth', 'national_id'];
        $score = 0.0;
        foreach ($required as $key) {
            $val = trim((string) ($fields[$key]['value'] ?? ''));
            if ($val === '') {
                continue;
            }
            $score += (float) ($fields[$key]['confidence'] ?? 0);
        }
        if (!empty($extraction['low_confidence'])) {
            $score *= 0.45;
        }
        return $score;
    }

    private static function formatConsensusFieldValue(string $key, string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (in_array($key, ['first_name', 'middle_name', 'last_name'], true)) {
            return self::formatPersonName($value);
        }
        if ($key === 'national_id') {
            $digits = preg_replace('/[^0-9]/', '', $value);
            return strlen($digits) === 16 ? self::formatNationalId($digits) : '';
        }
        if ($key === 'date_of_birth') {
            return self::parseDateString($value) ?? $value;
        }
        return $value;
    }

    private static function normalizeFieldValueForVote(string $key, string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (in_array($key, ['first_name', 'middle_name', 'last_name'], true)) {
            $name = self::formatPersonName($value);
            if ($name === '' || self::isReservedNameLabel($name) || !self::looksLikeNameToken($name)) {
                return '';
            }
            return strtolower($name);
        }
        if ($key === 'national_id') {
            $digits = preg_replace('/[^0-9]/', '', $value);
            return strlen($digits) === 16 ? $digits : '';
        }
        if ($key === 'date_of_birth') {
            return self::parseDateString($value) ?? '';
        }
        return strtolower(preg_replace('/\s+/', ' ', $value));
    }

    /**
     * Validate fields — empty invalid values rather than show wrong data.
     *
     * @param array{fields: array, overall_confidence: float, low_confidence: bool, raw_text: string} $result
     */
    public static function finalizeExtraction(array $result): array
    {
        $fields = $result['fields'];

        foreach (['first_name', 'middle_name', 'last_name'] as $nameKey) {
            $v = trim((string) ($fields[$nameKey]['value'] ?? ''));
            if ($v === '' || self::isReservedNameLabel($v)) {
                $fields[$nameKey] = self::field('', 0.0, 'none');
                continue;
            }
            $name = self::formatPersonName($v);
            if ($name === '' || !self::looksLikeNameToken($name)) {
                $fields[$nameKey] = self::field('', 0.0, 'none');
                continue;
            }
            $fields[$nameKey]['value'] = $name;
        }

        $fn = strtolower($fields['first_name']['value'] ?? '');
        $ln = strtolower($fields['last_name']['value'] ?? '');
        $mn = strtolower($fields['middle_name']['value'] ?? '');
        if ($fn !== '' && ($fn === $ln || $fn === $mn)) {
            $fields['first_name'] = self::field('', 0.0, 'none');
            $fn = '';
        }
        if ($mn !== '' && $mn === $ln) {
            $fields['middle_name'] = self::field('', 0.0, 'none');
        }

        $nid = preg_replace('/[^0-9]/', '', (string) ($fields['national_id']['value'] ?? ''));
        if (strlen($nid) !== 16) {
            $fields['national_id'] = self::field('', 0.0, 'none');
        } else {
            $fields['national_id']['value'] = self::formatNationalId($nid);
        }

        $dob = trim((string) ($fields['date_of_birth']['value'] ?? ''));
        $parsedDob = self::parseDateString($dob) ?? ($dob !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) ? $dob : null);
        if ($parsedDob === null) {
            $fields['date_of_birth'] = self::field('', 0.0, 'none');
        } else {
            $fields['date_of_birth']['value'] = $parsedDob;
        }

        $required = ['first_name', 'last_name', 'date_of_birth', 'national_id'];
        $scores = [];
        $lowField = false;
        foreach ($required as $key) {
            $val = trim((string) ($fields[$key]['value'] ?? ''));
            $conf = (float) ($fields[$key]['confidence'] ?? 0);
            if ($val === '') {
                $lowField = true;
                continue;
            }
            if ($conf < self::FIELD_MIN_CONFIDENCE) {
                $fields[$key] = self::field('', 0.0, 'invalidated');
                $lowField = true;
                continue;
            }
            $scores[] = $conf;
        }

        $overall = $scores !== [] ? array_sum($scores) / count($scores) : 0.0;
        $low = $lowField
            || $overall < self::CONFIDENCE_THRESHOLD
            || ($fields['first_name']['value'] ?? '') === ''
            || ($fields['last_name']['value'] ?? '') === ''
            || ($fields['date_of_birth']['value'] ?? '') === ''
            || ($fields['national_id']['value'] ?? '') === '';

        $result['fields'] = $fields;
        $result['overall_confidence'] = round($overall, 3);
        $result['low_confidence'] = $low;

        return $result;
    }

    /**
     * @return array{first: string, middle: string, last: string, first_confidence: float, middle_confidence: float, last_confidence: float, first_source: string, middle_source: string, last_source: string}
     */
    public static function extractNameFields(string $rawText): array
    {
        $result = [
            'first' => '', 'middle' => '', 'last' => '',
            'first_confidence' => 0.0, 'middle_confidence' => 0.0, 'last_confidence' => 0.0,
            'first_source' => 'none', 'middle_source' => 'none', 'last_source' => 'none',
        ];

        $labelMap = self::nameLabelMap();
        $lines = preg_split('/\r?\n/', $rawText) ?: [];

        // 1) PhilSys layout: value on the line below each label (most reliable on real cards)
        $block = self::extractPhilSysNameBlock($lines, $labelMap);
        foreach (['last', 'first', 'middle'] as $field) {
            if (($block[$field] ?? '') === '') {
                continue;
            }
            $result[$field] = $block[$field];
            $result[$field . '_confidence'] = 0.94;
            $result[$field . '_source'] = 'philsys_block';
        }

        // 2) Label-aware pass (inline or next-line), longest labels first
        $total = count($lines);
        for ($i = 0; $i < $total; $i++) {
            $lineUp = strtoupper(trim($lines[$i]));

            foreach ($labelMap as $field => $labels) {
                if ($result[$field] !== '') {
                    continue;
                }

                $sortedLabels = $labels;
                usort($sortedLabels, static fn ($a, $b) => strlen($b) - strlen($a));

                foreach ($sortedLabels as $label) {
                    if (!self::lineContainsNameLabel($lineUp, $label)) {
                        continue;
                    }

                    $stop = match ($field) {
                        'last' => ['GIVEN NAMES', 'GIVEN NAME', 'FIRST NAME', 'PANGALAN'],
                        'first' => ['MIDDLE NAME', 'MIDDLE INITIAL', 'GITNANG PANGALAN', 'DATE OF BIRTH'],
                        'middle' => ['DATE OF BIRTH', 'BIRTH DATE', 'SEX', 'ADDRESS', 'TIRAHAN'],
                        default => [],
                    };
                    $extracted = self::valueAfterLabel($lines, $i, $label, $labelMap, $stop);
                    if ($extracted !== '') {
                        $name = self::formatPersonName($extracted);
                        if ($name !== '' && self::looksLikeNameToken($name) && !self::isReservedNameLabel($name)) {
                            $result[$field] = $name;
                            $result[$field . '_confidence'] = 0.92;
                            $result[$field . '_source'] = 'label';
                        }
                    }
                    break;
                }
            }
        }

        // 3) Gap-fill from PhilSys block (if a field was missed in step 2 only)
        $blockGap = self::extractPhilSysNameBlock($lines, $labelMap);
        foreach (['last', 'first', 'middle'] as $field) {
            if ($result[$field] !== '' || ($blockGap[$field] ?? '') === '') {
                continue;
            }
            $result[$field] = $blockGap[$field];
            $result[$field . '_confidence'] = 0.88;
            $result[$field . '_source'] = 'philsys_block_gap';
        }

        // 4) Fallback: three uppercase name tokens (PhilID prints values in caps)
        if ($result['last'] === '' && $result['first'] === '') {
            $nameLines = self::extractUppercaseNameCandidates($lines, $labelMap);
            if (count($nameLines) >= 2) {
                $result['last']  = $nameLines[0];
                $result['first'] = $nameLines[1];
                $result['last_confidence']  = 0.55;
                $result['first_confidence'] = 0.55;
                $result['last_source']  = 'sequence';
                $result['first_source'] = 'sequence';
                if (count($nameLines) >= 3 && $result['middle'] === '') {
                    $result['middle'] = $nameLines[2];
                    $result['middle_confidence'] = 0.5;
                    $result['middle_source'] = 'sequence';
                }
            }
        }

        // 5) Middle often missing when last/first came from labels — use value order after LAST NAME
        if ($result['middle'] === '' && $result['last'] !== '' && $result['first'] !== '') {
            $seq = self::extractNameSequenceAfterLastNameLabel($lines, $labelMap);
            $candidate = trim($seq['middle']);
            if ($candidate !== ''
                && strcasecmp($candidate, $result['first']) !== 0
                && strcasecmp($candidate, $result['last']) !== 0
                && !self::isReservedNameLabel($candidate)
            ) {
                $result['middle'] = $candidate;
                $result['middle_confidence'] = 0.78;
                $result['middle_source'] = 'sequence_after_last';
            }
        }

        // 6) OCR sometimes merges given + middle on one line ("ANGEL BRILLO")
        if ($result['middle'] === '' && $result['first'] !== '') {
            $parts = preg_split('/\s+/', trim($result['first']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($parts) >= 2) {
                $firstOnly = self::formatPersonName($parts[0]);
                $rest = self::formatPersonName(implode(' ', array_slice($parts, 1)));
                if ($firstOnly !== '' && $rest !== '' && self::looksLikeNameToken($rest)) {
                    $result['first'] = $firstOnly;
                    $result['middle'] = $rest;
                    $result['middle_confidence'] = max((float) $result['middle_confidence'], 0.76);
                    $result['middle_source'] = 'first_name_split';
                    if ($result['first_confidence'] < 0.9) {
                        $result['first_confidence'] = 0.9;
                    }
                }
            }
        }

        // Drop label text mistaken for names (e.g. "Given Names")
        foreach (['last', 'first', 'middle'] as $field) {
            if ($result[$field] !== '' && self::isReservedNameLabel($result[$field])) {
                $result[$field] = '';
                $result[$field . '_confidence'] = 0.0;
                $result[$field . '_source'] = 'none';
            }
        }

        return $result;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function nameLabelMap(): array
    {
        return [
            'last' => ['LAST NAME', 'SURNAME', 'FAMILY NAME', 'APELYIDO'],
            'first' => ['GIVEN NAMES / FIRST NAME', 'GIVEN NAMES', 'GIVEN NAME', 'FIRST NAME', 'PANGALAN'],
            'middle' => ['MIDDLE NAME', 'MIDDLE INITIAL', 'GITNANG PANGALAN'],
        ];
    }

    /**
     * @param array<int, string> $lines
     * @param array<string, array<int, string>> $labelMap
     * @return array{last: string, first: string, middle: string}
     */
    private static function extractPhilSysNameBlock(array $lines, array $labelMap): array
    {
        $out = ['last' => '', 'first' => '', 'middle' => ''];
        foreach ($labelMap as $field => $labels) {
            $idx = self::findNameLabelLineIndex($lines, $labels);
            if ($idx === null) {
                continue;
            }
            $stop = match ($field) {
                'last' => ['GIVEN NAMES', 'GIVEN NAME', 'FIRST NAME', 'PANGALAN'],
                'first' => ['MIDDLE NAME', 'MIDDLE INITIAL', 'GITNANG PANGALAN', 'DATE OF BIRTH'],
                'middle' => ['DATE OF BIRTH', 'BIRTH DATE', 'SEX', 'ADDRESS', 'TIRAHAN'],
                default => [],
            };
            $val = self::nextNameValueAfter($lines, $idx + 1, $labelMap, $stop);
            if ($val !== '') {
                $out[$field] = $val;
            }
        }
        return $out;
    }

    /**
     * @param array<int, string> $lines
     * @param array<int, string> $labels
     */
    private static function findNameLabelLineIndex(array $lines, array $labels): ?int
    {
        usort($labels, static fn ($a, $b) => strlen($b) - strlen($a));
        foreach ($lines as $i => $line) {
            $lineUp = strtoupper(trim($line));
            foreach ($labels as $label) {
                if (self::lineContainsNameLabel($lineUp, $label)) {
                    return $i;
                }
            }
        }
        return null;
    }

    private static function lineContainsNameLabel(string $lineUp, string $label): bool
    {
        $label = strtoupper(trim($label));
        if ($lineUp === $label) {
            return true;
        }
        if (str_starts_with($lineUp, $label)) {
            return true;
        }
        return strpos($lineUp, $label) !== false;
    }

    /**
     * @param array<int, string> $lines
     * @param array<string, array<int, string>> $labelMap
     * @param list<string> $stopBeforeLabels
     */
    private static function nextNameValueAfter(array $lines, int $start, array $labelMap, array $stopBeforeLabels = []): string
    {
        $total = count($lines);
        for ($j = $start; $j < min($start + 8, $total); $j++) {
            $next = trim($lines[$j]);
            if ($next === '') {
                continue;
            }
            $nextUp = strtoupper($next);
            foreach ($stopBeforeLabels as $stop) {
                if (strpos($nextUp, strtoupper($stop)) !== false) {
                    return '';
                }
            }
            if (self::isLabelLine($nextUp, $labelMap) || self::isReservedNameLabel($next)) {
                continue;
            }
            $name = self::formatPersonName($next);
            if ($name !== '' && self::looksLikeNameToken($name)) {
                return $name;
            }
        }
        return '';
    }

    /**
     * @param array<int, string> $lines
     * @param array<string, array<int, string>> $labelMap
     * @return array{last: string, first: string, middle: string}
     */
    private static function extractNameSequenceAfterLastNameLabel(array $lines, array $labelMap): array
    {
        $out = ['last' => '', 'first' => '', 'middle' => ''];
        $idx = self::findNameLabelLineIndex($lines, $labelMap['last']);
        if ($idx === null) {
            return $out;
        }

        $values = [];
        $total = count($lines);
        for ($j = $idx + 1; $j < min($idx + 12, $total) && count($values) < 3; $j++) {
            $next = trim($lines[$j]);
            if ($next === '') {
                continue;
            }
            $nextUp = strtoupper($next);
            if (self::isLabelLine($nextUp, $labelMap) || self::isReservedNameLabel($next)) {
                continue;
            }
            if (preg_match('/\b(date of birth|birthdate|sex|address|tirahan|petsa)\b/i', $next)) {
                break;
            }
            $name = self::formatPersonName($next);
            if ($name === '' || !self::looksLikeNameToken($name)) {
                continue;
            }
            $values[] = $name;
        }

        if (count($values) >= 1) {
            $out['last'] = $values[0];
        }
        if (count($values) >= 2) {
            $out['first'] = $values[1];
        }
        if (count($values) >= 3) {
            $out['middle'] = $values[2];
        }
        return $out;
    }

    /**
     * @param array<int, string> $lines
     * @param array<string, array<int, string>> $labelMap
     * @return list<string>
     */
    private static function extractUppercaseNameCandidates(array $lines, array $labelMap): array
    {
        $noise = [
            'REPUBLIKA', 'PILIPINAS', 'PHILIPPINE', 'IDENTIFICATION', 'CARD', 'PHILSYS',
            'REPUBLIC', 'GOVERNMENT', 'DIGITAL', 'NUMBER', 'PERSONAL', 'NATIONAL',
        ];
        $candidates = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '' || preg_match('/\d/', $trim)) {
                continue;
            }
            $up = strtoupper(preg_replace('/[^A-Z]/', '', strtoupper($trim)));
            foreach ($noise as $n) {
                if (strpos($up, $n) !== false) {
                    continue 2;
                }
            }
            if (self::isReservedNameLabel($trim) || self::isLabelLine(strtoupper($trim), $labelMap)) {
                continue;
            }
            if (!preg_match('/^[A-Z][A-Z\s\-\']{0,38}$/', strtoupper(preg_replace('/[^A-Za-z\s\-\']/', '', $trim)))) {
                continue;
            }
            $name = self::formatPersonName($trim);
            if ($name !== '' && self::looksLikeNameToken($name)) {
                $candidates[] = $name;
            }
        }
        return array_values(array_unique($candidates));
    }

    private static function isReservedNameLabel(string $value): bool
    {
        $norm = strtoupper(preg_replace('/\s+/', ' ', trim($value)));
        $norm = str_replace(['/', '-'], ' ', $norm);
        $norm = preg_replace('/\s+/', ' ', $norm) ?? $norm;

        $reserved = [
            'GIVEN NAMES', 'GIVEN NAME', 'FIRST NAME', 'LAST NAME', 'MIDDLE NAME',
            'MIDDLE INITIAL', 'SURNAME', 'FAMILY NAME', 'APELYIDO', 'PANGALAN',
            'GITNANG PANGALAN', 'GIVEN NAMES FIRST NAME', 'NAME',
            'DATE OF BIRTH', 'BIRTH DATE', 'SEX', 'ADDRESS', 'TIRAHAN',
            'PHILIPPINE IDENTIFICATION CARD', 'DIGITAL ID NUMBER',
        ];
        foreach ($reserved as $phrase) {
            if ($norm === $phrase || $norm === str_replace(' ', '', $phrase)) {
                return true;
            }
        }
        if (preg_match('/^(GIVEN|LAST|MIDDLE|FIRST)\s+NAME(S)?$/', $norm)) {
            return true;
        }
        return false;
    }

    /**
     * @return array{value: string, confidence: float, source: string}
     */
    public static function extractDateOfBirth(string $rawText): array
    {
        $empty = ['value' => '', 'confidence' => 0.0, 'source' => 'none'];
        $labels = ['date of birth', 'birth date', 'birthdate', 'petsa ng kapanganakan'];
        $norm   = strtolower(preg_replace('/\s+/', ' ', $rawText));

        foreach ($labels as $label) {
            $pos = stripos($norm, $label);
            if ($pos === false) {
                continue;
            }
            $after = substr($norm, $pos + strlen($label), 80);
            $after = ltrim($after, ":- \t\r\n");
            $parsed = self::parseDateString($after);
            if ($parsed) {
                return ['value' => $parsed, 'confidence' => 0.9, 'source' => 'label_inline'];
            }

            $lines = preg_split('/\r?\n/', $rawText) ?: [];
            foreach ($lines as $li => $line) {
                if (stripos($line, $label) === false) {
                    continue;
                }
                for ($nxt = $li + 1; $nxt <= $li + 2 && $nxt < count($lines); $nxt++) {
                    $nl = trim($lines[$nxt]);
                    if ($nl === '') {
                        continue;
                    }
                    $parsed = self::parseDateString(strtolower($nl));
                    if ($parsed) {
                        return ['value' => $parsed, 'confidence' => 0.88, 'source' => 'label_nextline'];
                    }
                    break;
                }
            }
        }

        // Scan entire text for date patterns
        $patterns = [
            '/\b([A-Za-z]{3,9})\s+(\d{1,2}),?\s+(\d{4})\b/',
            '/\b(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})\b/',
            '/\b(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})\b/',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $rawText, $m)) {
                continue;
            }
            $candidate = self::parseDateString(strtolower(implode(' ', array_slice($m, 1))));
            if ($candidate) {
                return ['value' => $candidate, 'confidence' => 0.72, 'source' => 'pattern'];
            }
        }

        return $empty;
    }

    /**
     * @return array{value: string, confidence: float, source: string}
     */
    public static function extractNationalId(string $rawText): array
    {
        $empty = ['value' => '', 'confidence' => 0.0, 'source' => 'none'];
        $candidates = [];

        $sanitized = self::sanitizeOcrId($rawText);

        foreach ([$rawText, $sanitized] as $src) {
            if (preg_match_all('/(\d{4})[\s\-\.](\d{4})[\s\-\.](\d{4})[\s\-\.](\d{4})/', $src, $m, PREG_SET_ORDER)) {
                foreach ($m as $match) {
                    $digits = $match[1] . $match[2] . $match[3] . $match[4];
                    $candidates[$digits] = ['confidence' => 0.95, 'source' => 'grouped_4x4'];
                }
            }
            if (preg_match('/\d{16}/', $src, $m)) {
                $digits = $m[0];
                if (!isset($candidates[$digits])) {
                    $candidates[$digits] = ['confidence' => 0.85, 'source' => 'continuous_16'];
                }
            }
        }

        $idLabels = ['PCN', 'PhilSys', 'PHILSYS', 'National ID', 'NATIONAL ID', 'ID No', 'ID NO', 'Card Number'];
        $byLabel = self::extractFieldByLabel($rawText, $idLabels);
        if ($byLabel !== '') {
            $digits = preg_replace('/[^0-9]/', '', $byLabel);
            if (strlen($digits) === 16) {
                $candidates[$digits] = ['confidence' => 0.9, 'source' => 'label'];
            }
        }

        if (empty($candidates)) {
            $all = preg_replace('/[^0-9]/', '', $sanitized);
            for ($i = 0; $i <= strlen($all) - 16; $i++) {
                $c = substr($all, $i, 16);
                if (!isset($candidates[$c])) {
                    $candidates[$c] = ['confidence' => 0.65, 'source' => 'sliding_window'];
                }
            }
        }

        if (empty($candidates)) {
            return $empty;
        }

        uasort($candidates, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        $bestDigits = (string) array_key_first($candidates);
        $best       = $candidates[$bestDigits];

        return [
            'value' => self::formatNationalId($bestDigits),
            'confidence' => $best['confidence'],
            'source' => $best['source'],
        ];
    }

    /**
     * @return array{value: string, confidence: float, source: string}
     */
    public static function extractAddress(string $rawText): array
    {
        $empty = ['value' => '', 'confidence' => 0.0, 'source' => 'none'];
        $labels = [
            'ADDRESS', 'TIRAHAN', 'PUROK', 'BARANGAY', 'CITY/MUNICIPALITY',
            'CITY OF', 'MUNICIPALITY', 'PROVINCE',
        ];

        $lines = preg_split('/\r?\n/', $rawText) ?: [];
        $parts = [];

        foreach ($lines as $i => $line) {
            $lineUp = strtoupper(trim($line));
            if ($lineUp === 'ADDRESS' || $lineUp === 'TIRAHAN') {
                for ($j = $i + 1; $j <= $i + 4 && $j < count($lines); $j++) {
                    $next = trim($lines[$j]);
                    if ($next === '' || self::isAddressLabel(strtoupper($next))) {
                        continue;
                    }
                    $parts[] = self::formatAddressLine($next);
                    if (count($parts) >= 3) {
                        break;
                    }
                }
                break;
            }
        }

        if (!empty($parts)) {
            $address = self::formatAddressLine(implode(', ', $parts));
            return ['value' => $address, 'confidence' => 0.82, 'source' => 'label'];
        }

        // Heuristic: lines containing address keywords
        $addrLines = [];
        foreach ($lines as $line) {
            $ll = strtolower($line);
            if (preg_match('/\b(barangay|purok|city|negros|street|st\.|bago)\b/', $ll)) {
                $clean = self::formatAddressLine($line);
                if (strlen($clean) > 8) {
                    $addrLines[] = $clean;
                }
            }
        }

        if (!empty($addrLines)) {
            return [
                'value' => self::formatAddressLine(implode(', ', array_slice(array_unique($addrLines), 0, 3))),
                'confidence' => 0.68,
                'source' => 'keyword',
            ];
        }

        return $empty;
    }

    public static function formatPersonName(string $value): string
    {
        if (function_exists('iconv')) {
            $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        }
        $value = preg_replace('/[^A-Za-z\s\-\']/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', trim($value));
        if ($value === '') {
            return '';
        }

        $words = explode(' ', strtolower($value));
        $out   = [];
        foreach ($words as $w) {
            if ($w !== '') {
                $out[] = ucfirst($w);
            }
        }
        return implode(' ', $out);
    }

    public static function formatNationalId(string $digits): string
    {
        $digits = preg_replace('/[^0-9]/', '', $digits);
        if (strlen($digits) !== 16) {
            return $digits;
        }
        return substr($digits, 0, 4) . '-' . substr($digits, 4, 4) . '-'
            . substr($digits, 8, 4) . '-' . substr($digits, 12, 4);
    }

    public static function formatAddressLine(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value));
        $value = preg_replace('/[^\w\s,.\-#\/]/', '', $value);
        return trim($value);
    }

    public static function parseDateString(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $numeric = strtolower($raw);
        $monthMap = self::$monthMap;
        uksort($monthMap, fn($a, $b) => strlen($b) - strlen($a));
        foreach ($monthMap as $name => $num) {
            $numeric = preg_replace('/\b' . preg_quote($name, '/') . '\b/', (string) $num, $numeric);
        }

        preg_match_all('/\d+/', $numeric, $nums);
        $parts = array_map('intval', $nums[0] ?? []);
        if (count($parts) < 3) {
            return null;
        }

        $a = $parts[0];
        $b = $parts[1];
        $c = $parts[2];
        $orderings = [[$a, $b, $c], [$a, $c, $b], [$c, $a, $b], [$c, $b, $a], [$b, $a, $c], [$b, $c, $a]];

        foreach ($orderings as [$y, $mo, $d]) {
            if ($y < 100) {
                $y += ($y > 30) ? 1900 : 2000;
            }
            if ($y < 1900 || $y > 2100 || $mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
                continue;
            }
            $cand = sprintf('%04d-%02d-%02d', $y, $mo, $d);
            $p = DateTime::createFromFormat('Y-m-d', $cand);
            if ($p && $p->format('Y-m-d') === $cand) {
                return $cand;
            }
        }

        return null;
    }

    public static function extractFieldByLabel(string $rawText, array $labels): string
    {
        $lines = preg_split('/\r?\n/', $rawText) ?: [];
        foreach ($lines as $i => $line) {
            $ll = strtolower(trim($line));
            foreach ($labels as $label) {
                if (strpos($ll, strtolower($label)) === false) {
                    continue;
                }
                $val = self::valueAfterLabel($lines, $i, $label, []);
                if ($val !== '') {
                    return $val;
                }
            }
        }
        return '';
    }

    public static function sanitizeOcrId(string $raw): string
    {
        $result = '';
        $len = strlen($raw);
        for ($i = 0; $i < $len; $i++) {
            $c = $raw[$i];
            switch ($c) {
                case 'O': case 'o': case 'D': case 'Q': $result .= '0'; break;
                case 'I': case 'l': case 'i': case '!': $result .= '1'; break;
                case 'Z': case 'z': $result .= '2'; break;
                case 'S': case 's': $result .= '5'; break;
                case 'G': $result .= '6'; break;
                case 'B': case '&': $result .= '8'; break;
                case 'g': case 'q': $result .= '9'; break;
                default: $result .= $c; break;
            }
        }
        return $result;
    }

    /**
     * @param array<int, string> $lines
     * @param array<string, array<int, string>> $labelMap
     * @param list<string> $stopBeforeLabels
     */
    private static function valueAfterLabel(array $lines, int $lineIndex, string $label, array $labelMap, array $stopBeforeLabels = []): string
    {
        $line   = $lines[$lineIndex];
        $lineUp = strtoupper(trim($line));
        $labelUp = strtoupper($label);

        $pos = strpos($lineUp, $labelUp);
        if ($pos !== false) {
            $after = trim(substr($lineUp, $pos + strlen($labelUp)));
            $after = ltrim($after, ':- /');
            if ($after !== ''
                && !self::isReservedNameLabel($after)
                && !self::isLabelLine($after, $labelMap)
            ) {
                $name = self::formatPersonName($after);
                if ($name !== '' && self::looksLikeNameToken($name)) {
                    return $after;
                }
            }
        }

        $total = count($lines);
        for ($j = $lineIndex + 1; $j <= $lineIndex + 5 && $j < $total; $j++) {
            $next = trim($lines[$j]);
            if ($next === '') {
                continue;
            }
            $nextUp = strtoupper($next);
            foreach ($stopBeforeLabels as $stop) {
                if (strpos($nextUp, strtoupper($stop)) !== false) {
                    return '';
                }
            }
            if (self::isLabelLine($nextUp, $labelMap) || self::isReservedNameLabel($next)) {
                continue;
            }
            $name = self::formatPersonName($next);
            if ($name !== '' && self::looksLikeNameToken($name)) {
                return $nextUp;
            }
        }

        return '';
    }

    /**
     * @param array<string, array<int, string>> $labelMap
     */
    private static function isLabelLine(string $lineUp, array $labelMap): bool
    {
        $allLabels = [];
        foreach ($labelMap as $labels) {
            foreach ($labels as $l) {
                $allLabels[] = strtoupper($l);
            }
        }
        foreach ($allLabels as $l) {
            if (strpos($lineUp, $l) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function isAddressLabel(string $lineUp): bool
    {
        foreach (['LAST NAME', 'GIVEN', 'MIDDLE', 'DATE OF BIRTH', 'SEX', 'PCN', 'PHILSYS'] as $lbl) {
            if (strpos($lineUp, $lbl) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function looksLikeNameToken(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z][A-Za-z\s\-\']{1,}$/', $value)
            && strlen($value) >= 2
            && strlen($value) <= 40;
    }

    /**
     * @return array{value: string, confidence: float, source: string}
     */
    private static function field(string $value, float $confidence, string $source): array
    {
        return [
            'value' => $value,
            'confidence' => round(max(0.0, min(1.0, $confidence)), 3),
            'source' => $source,
        ];
    }
}
