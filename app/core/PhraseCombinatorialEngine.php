<?php
/**
 * Rule-based combinatorial Hiligaynon medical phrase engine (PHP).
 * Matches millions of expressions dynamically from JSON rule tables.
 */

final class PhraseCombinatorialEngine
{
  private const ENGINE_DIR = '/data/nlp/phrase_engine';

  /** @var array<string, mixed>|null */
  private static ?array $symptomRoots = null;

  /** @var list<array<string, mixed>>|null */
  private static ?array $bodyParts = null;

  /** @var array<string, string>|null */
  private static ?array $misspellingMap = null;

  /** @var array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>, 2: array<string, array<string, mixed>>}|null */
  private static ?array $classificationIndex = null;

  public static function normalize(string $text): string
  {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9\s\-]/', ' ', $text) ?? $text;

    return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
  }

  public static function applyMisspellingCorrections(string $text): string
  {
    $working = self::normalize($text);
    if ($working === '') {
      return '';
    }
    $map = self::misspellingMap();
    uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
    foreach ($map as $wrong => $correct) {
      $working = preg_replace('/(?<!\w)' . preg_quote($wrong, '/') . '(?!\w)/u', $correct, $working) ?? $working;
    }

    return trim($working);
  }

  /** @return list<array<string, mixed>> */
  public static function matchPhrases(string $text): array
  {
    $corrected = self::applyMisspellingCorrections($text);
    $working = self::normalize($corrected);
    if ($working === '') {
      return [];
    }

    $symptomMatches = self::findSymptomMatches($working);
    $bodyMatches = self::findBodyMatches($working);
    $pairs = self::pairSymptomBody($symptomMatches, $bodyMatches);

    $results = [];
    $seen = [];

    foreach ($pairs as [$sMatch, $bMatch]) {
      $root = is_array($sMatch) ? ($sMatch['root'] ?? null) : null;
      $part = is_array($bMatch) ? ($bMatch['part'] ?? null) : null;
      $classification = self::classify(is_array($root) ? $root : null, is_array($part) ? $part : null, $working);
      $english = self::composeEnglish(
        is_array($root) ? $root : null,
        is_array($part) ? $part : null,
        $classification
      );
      $matchedSub = self::extractMatchedSubstring($working, $sMatch, $bMatch);
      $key = $matchedSub . '|' . $english;
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;

      $bodyEng = is_array($part) ? (string) ($part['eng'] ?? '') : '';
      $symEng = is_array($root) ? (string) ($root['english_symptom'] ?? '') : '';

      $results[] = [
        'hiligaynon_term'  => $matchedSub !== '' ? $matchedSub : $working,
        'english_term'     => $english,
        'medical_category' => $classification['medical_category'],
        'severity'         => ucfirst($classification['severity']),
        'triage_level'     => $classification['triage_level'],
        'body_part'        => $bodyEng,
        'symptom'          => $symEng,
        'condition'        => (is_array($root) && !empty($root['is_condition'])) || str_contains(strtolower($english), 'infection')
          ? $english : '',
        'source'           => 'phrase_combinatorial_engine',
        'matched_phrase'   => $matchedSub !== '' ? $matchedSub : $working,
        'span'             => self::combinedSpan($sMatch, $bMatch, strlen($working)),
        'confidence'       => 94,
        'engine'           => 'combinatorial_v1',
      ];
    }

    return $results;
  }

  /** @return array<string, int|bool> */
  public static function estimateCombinationCount(): array
  {
    $roots = self::symptomRoots();
    $parts = self::bodyParts();
    $templates = self::loadJson('templates.json');
    $misspellings = self::loadJson('misspelling_rules.json');

    $nSymptomTerms = 0;
    foreach ($roots as $root) {
      $nSymptomTerms += count($root['terms'] ?? []);
    }
    $nBody = count($parts);
    $nOrders = count($templates['word_orders'] ?? []) + count($templates['standalone_orders'] ?? []);
    $nPoss = max(count($templates['possessives'] ?? []), 1);
    $nIntens = max(count($templates['intensifiers'] ?? []), 1);
    $nMisspell = 0;
    foreach (($misspellings['known_variants'] ?? []) as $variants) {
      $nMisspell += is_array($variants) ? count($variants) : 0;
    }

    $symptomBody = $nSymptomTerms * $nBody * max($nOrders, 1) * $nPoss * $nIntens;
    $standalone = $nSymptomTerms * max(count($templates['standalone_orders'] ?? [1]), 1) * $nPoss;
    $withMisspell = ($symptomBody + $standalone) * max((int) ($nMisspell / max($nSymptomTerms, 1)), 1);

    return [
      'symptom_roots'                      => count($roots),
      'symptom_terms'                      => $nSymptomTerms,
      'body_parts'                         => $nBody,
      'templates'                          => $nOrders,
      'theoretical_symptom_body_phrases'   => $symptomBody,
      'theoretical_with_variants'          => $withMisspell,
      'target_met'                         => $withMisspell >= 1_000_000,
    ];
  }

  /** @return list<array<string, mixed>> */
  private static function symptomRoots(): array
  {
    if (self::$symptomRoots !== null) {
      return self::$symptomRoots;
    }
    $data = self::loadJson('symptom_roots.json');
    $roots = [];
    foreach ($data['roots'] ?? [] as $root) {
      $terms = $root['terms'] ?? [];
      usort($terms, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
      $root['terms_sorted'] = $terms;
      $roots[] = $root;
    }
    usort($roots, static function (array $a, array $b): int {
      $la = max(array_map('strlen', $a['terms_sorted'] ?? ['']));
      $lb = max(array_map('strlen', $b['terms_sorted'] ?? ['']));

      return $lb <=> $la;
    });
    self::$symptomRoots = $roots;

    return self::$symptomRoots;
  }

  /** @return list<array<string, mixed>> */
  private static function bodyParts(): array
  {
    if (self::$bodyParts !== null) {
      return self::$bodyParts;
    }
    $data = self::loadJson('body_parts.json');
    $parts = $data['parts'] ?? [];
    usort($parts, static fn (array $a, array $b): int => strlen((string) ($b['hil'] ?? '')) <=> strlen((string) ($a['hil'] ?? '')));
    self::$bodyParts = $parts;

    return self::$bodyParts;
  }

  /** @return array<string, string> */
  private static function misspellingMap(): array
  {
    if (self::$misspellingMap !== null) {
      return self::$misspellingMap;
    }
    $rules = self::loadJson('misspelling_rules.json');
    $map = [];
    foreach (($rules['known_variants'] ?? []) as $correct => $variants) {
      $c = self::normalize((string) $correct);
      if (!is_array($variants)) {
        continue;
      }
      foreach ($variants as $v) {
        $w = self::normalize((string) $v);
        if ($w !== '' && $w !== $c && !isset($map[$w])) {
          $map[$w] = $c;
        }
      }
    }
    self::$misspellingMap = $map;

    return self::$misspellingMap;
  }

  /** @return array<string, mixed> */
  private static function loadJson(string $name): array
  {
    $path = BASE_PATH . self::ENGINE_DIR . '/' . $name;
    if (!is_readable($path)) {
      return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
      return [];
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
  }

  /** @return list<array<string, mixed>> */
  private static function findSymptomMatches(string $text): array
  {
    $matches = [];
    $occupied = array_fill(0, max(strlen($text), 1), false);
    foreach (self::symptomRoots() as $root) {
      foreach ($root['terms_sorted'] ?? [] as $term) {
        $termN = self::normalize((string) $term);
        if ($termN === '') {
          continue;
        }
        if (!preg_match_all('/(?<!\w)' . preg_quote($termN, '/') . '(?!\w)/u', $text, $m, PREG_OFFSET_CAPTURE)) {
          continue;
        }
        foreach ($m[0] as [$_, $start]) {
          $end = $start + strlen($termN);
          $overlap = false;
          for ($i = $start; $i < $end; $i++) {
            if ($occupied[$i] ?? false) {
              $overlap = true;
              break;
            }
          }
          if ($overlap) {
            continue;
          }
          for ($i = $start; $i < $end; $i++) {
            $occupied[$i] = true;
          }
          $matches[] = ['root' => $root, 'term' => $termN, 'span' => [$start, $end]];
        }
      }
    }
    usort($matches, static fn (array $a, array $b): int => ($a['span'][0] ?? 0) <=> ($b['span'][0] ?? 0));

    return $matches;
  }

  /** @return list<array<string, mixed>> */
  private static function findBodyMatches(string $text): array
  {
    $matches = [];
    $occupied = array_fill(0, max(strlen($text), 1), false);
    foreach (self::bodyParts() as $part) {
      $hil = self::normalize((string) ($part['hil'] ?? ''));
      if ($hil === '') {
        continue;
      }
      if (!preg_match_all('/(?<!\w)' . preg_quote($hil, '/') . '(?!\w)/u', $text, $m, PREG_OFFSET_CAPTURE)) {
        continue;
      }
      foreach ($m[0] as [$_, $start]) {
        $end = $start + strlen($hil);
        $overlap = false;
        for ($i = $start; $i < $end; $i++) {
          if ($occupied[$i] ?? false) {
            $overlap = true;
            break;
          }
        }
        if ($overlap) {
          continue;
        }
        for ($i = $start; $i < $end; $i++) {
          $occupied[$i] = true;
        }
        $matches[] = ['part' => $part, 'term' => $hil, 'span' => [$start, $end]];
      }
    }
    usort($matches, static fn (array $a, array $b): int => ($a['span'][0] ?? 0) <=> ($b['span'][0] ?? 0));

    return $matches;
  }

  /**
   * @param list<array<string, mixed>> $symptomMatches
   * @param list<array<string, mixed>> $bodyMatches
   * @return list<array{0: array<string, mixed>|null, 1: array<string, mixed>|null}>
   */
  private static function pairSymptomBody(array $symptomMatches, array $bodyMatches): array
  {
    if ($symptomMatches === [] && $bodyMatches === []) {
      return [];
    }
    if ($symptomMatches === []) {
      return array_map(static fn (array $b): array => [null, $b], $bodyMatches);
    }
    if ($bodyMatches === []) {
      return array_map(static fn (array $s): array => [$s, null], $symptomMatches);
    }

    $pairs = [];
    $usedBodies = [];
    foreach ($symptomMatches as $s) {
      $bestBody = null;
      $bestIdx = -1;
      $bestDist = 999;
      foreach ($bodyMatches as $idx => $b) {
        if (isset($usedBodies[$idx])) {
          continue;
        }
        $dist = abs((int) (($s['span'][0] + $s['span'][1]) / 2) - (int) (($b['span'][0] + $b['span'][1]) / 2));
        if ($dist < $bestDist) {
          $bestDist = $dist;
          $bestBody = $b;
          $bestIdx = $idx;
        }
      }
      if ($bestBody !== null && $bestDist <= 40) {
        $usedBodies[$bestIdx] = true;
        $pairs[] = [$s, $bestBody];
      } else {
        $pairs[] = [$s, null];
      }
    }
    foreach ($bodyMatches as $idx => $b) {
      if (!isset($usedBodies[$idx])) {
        $pairs[] = [null, $b];
      }
    }

    return $pairs;
  }

  /**
   * @param array<string, mixed>|null $root
   * @param array<string, mixed>|null $part
   * @return array{triage_level: string, severity: string, medical_category: string, english_override: string}
   */
  private static function classify(?array $root, ?array $part, string $text): array
  {
    [$pairRules, $defaults, $patterns] = self::classificationIndex();

    foreach ($patterns as $pat => $rule) {
      if (str_contains($text, (string) $pat)) {
        return [
          'triage_level'     => (string) ($rule['triage'] ?? 'emergency'),
          'severity'         => (string) ($rule['severity'] ?? 'critical'),
          'medical_category' => (string) ($rule['category'] ?? 'general'),
          'english_override' => (string) ($rule['english'] ?? ''),
        ];
      }
    }

    $symptomId = (string) ($root['id'] ?? '');
    $bodyEng = (string) ($part['eng'] ?? '');

    if ($symptomId !== '' && $bodyEng !== '' && isset($pairRules[$symptomId . '|' . $bodyEng])) {
      $rule = $pairRules[$symptomId . '|' . $bodyEng];

      return [
        'triage_level'     => (string) ($rule['triage'] ?? 'urgent'),
        'severity'         => (string) ($rule['severity'] ?? 'moderate'),
        'medical_category' => (string) ($rule['category'] ?? 'general'),
        'english_override' => '',
      ];
    }

    if ($symptomId !== '' && isset($defaults[$symptomId])) {
      $d = $defaults[$symptomId];

      return [
        'triage_level'     => (string) ($d['triage'] ?? 'urgent'),
        'severity'         => (string) ($d['severity'] ?? 'moderate'),
        'medical_category' => (string) ($root['category'] ?? 'general'),
        'english_override' => '',
      ];
    }

    if ($root !== null) {
      return [
        'triage_level'     => (string) ($root['default_triage'] ?? 'routine'),
        'severity'         => (string) ($root['default_severity'] ?? 'moderate'),
        'medical_category' => (string) ($root['category'] ?? 'general'),
        'english_override' => '',
      ];
    }

    return [
      'triage_level'     => 'routine',
      'severity'         => 'mild',
      'medical_category' => (string) ($part['category'] ?? 'general'),
      'english_override' => '',
    ];
  }

  /**
   * @param array<string, mixed>|null $root
   * @param array<string, mixed>|null $part
   * @param array{triage_level: string, severity: string, medical_category: string, english_override: string} $classification
   */
  private static function composeEnglish(?array $root, ?array $part, array $classification): string
  {
    if ($classification['english_override'] !== '') {
      return $classification['english_override'];
    }

    if ($root !== null && $part !== null) {
      $engSym = (string) ($root['english_symptom'] ?? 'symptom');
      $engBody = (string) ($part['eng'] ?? '');
      if (($root['id'] ?? '') === 'pus_infection') {
        return $engBody . ' infection';
      }
      if (in_array($engSym, ['pain', 'swelling', 'itching', 'redness', 'bleeding'], true)) {
        return $engBody . ' ' . $engSym;
      }

      return $engBody . ' ' . (string) ($root['english_phrase'] ?? $engSym);
    }

    if ($root !== null) {
      return (string) (($root['english_phrase'] ?? '') ?: ($root['english_symptom'] ?? 'symptom'));
    }

    if ($part !== null) {
      return (string) ($part['eng'] ?? 'body part');
    }

    return 'symptom';
  }

  /** @param array<string, mixed>|null $sMatch @param array<string, mixed>|null $bMatch */
  private static function extractMatchedSubstring(string $text, ?array $sMatch, ?array $bMatch): string
  {
    $spans = [];
    if (is_array($sMatch)) {
      $spans[] = $sMatch['span'];
    }
    if (is_array($bMatch)) {
      $spans[] = $bMatch['span'];
    }
    if ($spans === []) {
      return $text;
    }
    $start = min(array_column($spans, 0));
    $end = max(array_column($spans, 1));

    return trim(substr($text, $start, $end - $start));
  }

  /** @param array<string, mixed>|null $sMatch @param array<string, mixed>|null $bMatch @return list<int> */
  private static function combinedSpan(?array $sMatch, ?array $bMatch, int $len): array
  {
    $starts = [];
    $ends = [];
    if (is_array($sMatch)) {
      $starts[] = $sMatch['span'][0];
      $ends[] = $sMatch['span'][1];
    }
    if (is_array($bMatch)) {
      $starts[] = $bMatch['span'][0];
      $ends[] = $bMatch['span'][1];
    }
    if ($starts === []) {
      return [0, $len];
    }

    return [min($starts), max($ends)];
  }

  /** @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>, 2: array<string, array<string, mixed>>} */
  private static function classificationIndex(): array
  {
    if (self::$classificationIndex !== null) {
      return self::$classificationIndex;
    }
    $data = self::loadJson('classification_rules.json');
    $pairRules = [];
    foreach ($data['rules'] ?? [] as $rule) {
      $key = ($rule['symptom_id'] ?? '') . '|' . ($rule['body_eng'] ?? '');
      $pairRules[$key] = $rule;
    }
    $defaults = is_array($data['defaults_by_symptom'] ?? null) ? $data['defaults_by_symptom'] : [];
    $patterns = [];
    foreach ($data['pattern_rules'] ?? [] as $rule) {
      if (!empty($rule['pattern'])) {
        $patterns[(string) $rule['pattern']] = $rule;
      }
    }
    self::$classificationIndex = [$pairRules, $defaults, $patterns];

    return self::$classificationIndex;
  }
}
