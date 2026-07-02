<?php
/**
 * Philippine National ID (PhilSys) OCR field extraction and confidence scoring.
 * Label-aware parsing — does not rely on fixed text positions.
 */

final class PhilSysOcrParser
{
    public const CONFIDENCE_THRESHOLD = 0.62;

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

        return [
            'fields' => $fields,
            'overall_confidence' => round($overall, 3),
            'low_confidence' => $low,
            'raw_text' => $rawText,
        ];
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

        $labelMap = [
            'last' => ['LAST NAME', 'SURNAME', 'FAMILY NAME', 'APELYIDO'],
            'first' => ['GIVEN NAMES', 'GIVEN NAME', 'FIRST NAME', 'PANGALAN', 'GIVEN NAMES / FIRST NAME'],
            'middle' => ['MIDDLE NAME', 'MIDDLE INITIAL', 'GITNANG PANGALAN'],
        ];

        $lines = preg_split('/\r?\n/', $rawText) ?: [];
        $total = count($lines);

        for ($i = 0; $i < $total; $i++) {
            $lineUp = strtoupper(trim($lines[$i]));

            foreach ($labelMap as $field => $labels) {
                if ($result[$field] !== '') {
                    continue;
                }

                foreach ($labels as $label) {
                    if (strpos($lineUp, $label) === false) {
                        continue;
                    }

                    $extracted = self::valueAfterLabel($lines, $i, $label, $labelMap);
                    if ($extracted !== '') {
                        $result[$field] = self::formatPersonName($extracted);
                        $confKey = $field . '_confidence';
                        $srcKey  = $field . '_source';
                        $result[$confKey] = 0.92;
                        $result[$srcKey]  = 'label';
                    }
                    break;
                }
            }
        }

        // Fallback: three consecutive uppercase name lines after header noise
        if ($result['last'] === '' && $result['first'] === '') {
            $nameLines = [];
            foreach ($lines as $line) {
                $clean = self::formatPersonName($line);
                if ($clean !== '' && self::looksLikeNameToken($clean)) {
                    $nameLines[] = $clean;
                }
            }
            $nameLines = array_values(array_unique($nameLines));
            if (count($nameLines) >= 2) {
                $result['last']  = $nameLines[0];
                $result['first'] = $nameLines[1];
                $result['last_confidence']  = 0.55;
                $result['first_confidence'] = 0.55;
                $result['last_source']  = 'sequence';
                $result['first_source'] = 'sequence';
                if (count($nameLines) >= 3) {
                    $result['middle'] = $nameLines[2];
                    $result['middle_confidence'] = 0.5;
                    $result['middle_source'] = 'sequence';
                }
            }
        }

        return $result;
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
                for ($j = $i + 1; $j <= $i + 2 && $j < count($lines); $j++) {
                    $next = trim($lines[$j]);
                    if ($next === '' || self::isAddressLabel(strtoupper($next))) {
                        continue;
                    }
                    $parts[] = self::formatAddressLine($next);
                    break;
                }
                break;
            }
        }

        if (!empty($parts)) {
            $address = self::formatAddressLine($parts[0]);
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
     */
    private static function valueAfterLabel(array $lines, int $lineIndex, string $label, array $labelMap): string
    {
        $line   = $lines[$lineIndex];
        $lineUp = strtoupper(trim($line));
        $labelUp = strtoupper($label);

        $pos = strpos($lineUp, $labelUp);
        if ($pos !== false) {
            $after = trim(substr($lineUp, $pos + strlen($labelUp)));
            $after = ltrim($after, ':- ');
            if ($after !== '' && !self::isLabelLine($after, $labelMap)) {
                return $after;
            }
        }

        $total = count($lines);
        for ($j = $lineIndex + 1; $j <= $lineIndex + 3 && $j < $total; $j++) {
            $next = trim($lines[$j]);
            if ($next === '') {
                continue;
            }
            $nextUp = strtoupper($next);
            if (self::isLabelLine($nextUp, $labelMap)) {
                continue;
            }
            return $nextUp;
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
