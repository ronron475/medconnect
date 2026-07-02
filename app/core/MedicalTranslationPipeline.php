<?php
/**
 * Step 2 — Medical Translation pipeline with explicit stage ordering.
 *
 * Sequence:
 *   Patient Input → Medical Dictionary → Hiligaynon Dataset → Keyword Extraction
 *   → Groq Context Analysis → English Interpretation → (Fuzzy Matching → Validation)
 */

final class MedicalTranslationPipeline
{
  public const PIPELINE_SEQUENCE = [
    'patient_input',
    'medical_dictionary',
    'hiligaynon_dataset',
    'keyword_extraction',
    'groq_context_analysis',
    'english_interpretation',
  ];

  /** @var array<string, string> */
  public const PIPELINE_LABELS = [
    'patient_input'          => 'Patient Input',
    'medical_dictionary'     => 'Medical Dictionary',
    'hiligaynon_dataset'     => 'Hiligaynon Dataset',
    'keyword_extraction'     => 'Keyword Extraction',
    'groq_context_analysis'  => 'Groq Context Analysis',
    'english_interpretation' => 'English Interpretation',
    'fuzzy_matching'         => 'Fuzzy Matching',
    'validation'             => 'Validation',
  ];

  /**
   * @return array<string, mixed>
   */
  public static function run(array $preprocessing, string $conditionsOriginal = '', string $allergiesOriginal = ''): array
  {
    $prepConditions = is_array($preprocessing['conditions'] ?? null) ? $preprocessing['conditions'] : [];
    $prepAllergies = is_array($preprocessing['allergies'] ?? null) ? $preprocessing['allergies'] : [];

    $conditionsOriginal = $conditionsOriginal !== '' ? $conditionsOriginal : (string) ($prepConditions['original'] ?? '');
    $allergiesOriginal = $allergiesOriginal !== '' ? $allergiesOriginal : (string) ($prepAllergies['original'] ?? '');

    $conditions = self::runFieldTranslation($prepConditions, $conditionsOriginal, 'condition');
    $allergies = self::runFieldTranslation($prepAllergies, $allergiesOriginal, 'allergy');

    $combined = array_filter([
      (string) ($conditions['english_text'] ?? ''),
      (string) ($allergies['english_text'] ?? ''),
    ]);
    $combinedEnglish = implode(' | ', $combined);

    $totalM = (int) ($conditions['matched_count'] ?? 0) + (int) ($allergies['matched_count'] ?? 0);
    $totalU = (int) ($conditions['unmatched_count'] ?? 0) + (int) ($allergies['unmatched_count'] ?? 0);
    $total = (int) ($conditions['total_count'] ?? 0) + (int) ($allergies['total_count'] ?? 0);
    $overall = MedicalTranslator::overallStatusPublic($totalM, $totalU, $total);

    $aiConditions = is_array($conditions['ai_interpretation'] ?? null) ? $conditions['ai_interpretation'] : [];
    $aiAllergies = is_array($allergies['ai_interpretation'] ?? null) ? $allergies['ai_interpretation'] : [];
    $priority = static fn (array $block): int => match ($block['status'] ?? '') {
      'complete' => 3,
      'fallback' => 2,
      'disabled' => 1,
      default => 0,
    };
    $active = $priority($aiConditions) >= $priority($aiAllergies) ? $aiConditions : $aiAllergies;

    $scores = array_values(array_filter([
      (int) ($aiConditions['confidence_score'] ?? 0),
      (int) ($aiAllergies['confidence_score'] ?? 0),
    ], static fn (int $s): bool => $s > 0));
    $overallConfidence = $scores !== [] ? (int) round(array_sum($scores) / count($scores)) : 0;

    return [
      'allergies'              => $allergies,
      'conditions'             => $conditions,
      'combined_english'       => $combinedEnglish,
      'overall_status'         => $overall,
      'overall_status_label'   => MedicalTranslator::statusLabelPublic($overall, $totalM, $total),
      'ai_interpretation'      => [
        'status'                        => $active['status'] ?? 'unavailable',
        'provider'                      => $active['provider'] ?? null,
        'model'                         => $active['model'] ?? null,
        'overall_confidence'            => $overallConfidence,
        'english_interpretation'        => $combinedEnglish,
        'conditions'                    => $aiConditions,
        'allergies'                     => $aiAllergies,
        'detected_concepts'             => array_merge(
          is_array($aiConditions['concepts'] ?? null) ? $aiConditions['concepts'] : [],
          is_array($aiAllergies['concepts'] ?? null) ? $aiAllergies['concepts'] : []
        ),
        'policy'                        => 'Groq contextual analysis improves translation only. '
          . 'All concepts must pass fuzzy matching (Step 3) and dataset validation (Step 4).',
        'concepts_queued_for_validation' => (int) ($conditions['ai_concepts_added'] ?? 0)
          + (int) ($allergies['ai_concepts_added'] ?? 0),
        'primary_provider'              => 'groq',
      ],
      'pipeline' => [
        'version'  => '1.0',
        'sequence' => array_merge(self::PIPELINE_SEQUENCE, ['fuzzy_matching', 'validation']),
        'labels'   => self::PIPELINE_LABELS,
        'conditions' => ($conditions['pipeline']['stages'] ?? []),
        'allergies'  => ($allergies['pipeline']['stages'] ?? []),
      ],
    ];
  }

  /**
   * @param array<string, mixed> $preprocessed
   * @return array<string, mixed>
   */
  private static function runFieldTranslation(array $preprocessed, string $originalText, string $expectedCategory): array
  {
    $context = self::buildFieldPipelineContext($originalText, $preprocessed);
    $stages = $context['stages'];

    $preprocessedForTranslate = $preprocessed;
    if (!empty($context['keywords'])) {
      $preprocessedForTranslate['keywords'] = $context['keywords'];
      $preprocessedForTranslate['keywords_text'] = implode(' ', $context['keywords']);
    }

    $translation = MedicalTranslator::translateField($preprocessedForTranslate, $expectedCategory);
    $translation = MedicalAiInterpreter::enrichFieldWithAi(
      $translation,
      $originalText,
      $expectedCategory,
      $context
    );

    $aiBlock = is_array($translation['ai_interpretation'] ?? null) ? $translation['ai_interpretation'] : [];
    $groqStatus = (string) ($aiBlock['status'] ?? 'unavailable');
    $englishText = trim((string) ($translation['english_text'] ?? ''));

    $stages['groq_context_analysis'] = [
      'status'           => $groqStatus,
      'label'            => self::PIPELINE_LABELS['groq_context_analysis'],
      'provider'         => $aiBlock['provider'] ?? null,
      'model'            => $aiBlock['model'] ?? null,
      'confidence_score' => $aiBlock['confidence_score'] ?? null,
      'notes'            => $aiBlock['notes'] ?? null,
      'context_sources'  => [
        'dictionary_matches' => count($context['dictionary_matches']),
        'dataset_matches'    => count($context['dataset_matches']),
        'keywords'           => count($context['keywords']),
      ],
    ];
    $stages['english_interpretation'] = [
      'status'                  => $englishText !== '' ? 'complete' : 'empty',
      'label'                   => self::PIPELINE_LABELS['english_interpretation'],
      'english_text'            => $englishText,
      'english_preview'         => (string) ($translation['english_preview'] ?? $englishText),
      'validation_queue_count'  => count($translation['validation_queue'] ?? []),
      'ai_concepts_added'       => (int) ($translation['ai_concepts_added'] ?? 0),
    ];

    $translation['pipeline'] = [
      'version'    => '1.0',
      'sequence'   => self::PIPELINE_SEQUENCE,
      'downstream' => ['fuzzy_matching', 'validation'],
      'stages'     => $stages,
    ];

    return $translation;
  }

  /**
   * @param array<string, mixed> $preprocessed
   * @return array<string, mixed>
   */
  private static function buildFieldPipelineContext(string $originalText, array $preprocessed): array
  {
    $normalized = trim((string) ($preprocessed['normalized'] ?? ''));
    $scanText = $normalized !== '' ? $normalized : trim($originalText);
    $keywords = is_array($preprocessed['keywords'] ?? null) ? $preprocessed['keywords'] : [];

    $dictMatches = self::scanMedicalDictionary($scanText);
    $datasetMatches = self::scanHiligaynonDataset($scanText);

    foreach ($dictMatches as $row) {
      $local = (string) ($row['local_term'] ?? '');
      if ($local !== '' && !in_array($local, $keywords, true)) {
        $keywords[] = $local;
      }
    }

    $stages = [
      'patient_input' => [
        'status'     => trim($originalText) !== '' ? 'complete' : 'empty',
        'label'      => self::PIPELINE_LABELS['patient_input'],
        'text'       => $originalText,
        'normalized' => $normalized,
      ],
      'medical_dictionary' => [
        'status'      => $dictMatches !== [] ? 'complete' : ($scanText !== '' ? 'none' : 'empty'),
        'label'       => self::PIPELINE_LABELS['medical_dictionary'],
        'match_count' => count($dictMatches),
        'matches'     => $dictMatches,
      ],
      'hiligaynon_dataset' => [
        'status'      => $datasetMatches !== [] ? 'complete' : ($scanText !== '' ? 'none' : 'empty'),
        'label'       => self::PIPELINE_LABELS['hiligaynon_dataset'],
        'match_count' => count($datasetMatches),
        'matches'     => $datasetMatches,
      ],
      'keyword_extraction' => [
        'status'        => $keywords !== [] ? 'complete' : ($scanText !== '' ? 'none' : 'empty'),
        'label'         => self::PIPELINE_LABELS['keyword_extraction'],
        'keywords'      => $keywords,
        'keywords_text' => implode(' ', $keywords),
      ],
    ];

    return [
      'stages'             => $stages,
      'dictionary_matches' => $dictMatches,
      'dataset_matches'    => $datasetMatches,
      'keywords'           => $keywords,
      'scan_text'          => $scanText,
    ];
  }

  /** @return list<array<string, mixed>> */
  private static function scanMedicalDictionary(string $text): array
  {
    $normalized = mb_strtolower(trim($text));
    if ($normalized === '') {
      return [];
    }

    $terms = MedicalDictionary::termsByLength();
    $raw = self::scanLongestMatches($normalized, $terms);
    $out = [];
    foreach ($raw as $row) {
      $entry = MedicalDictionary::lookup($row['local_term']);
      if ($entry === null) {
        continue;
      }
      $out[] = [
        'local_term'    => $entry['local_term'],
        'english_term'  => $entry['english_term'],
        'category'      => $entry['category'],
        'dictionary_id' => $entry['dictionary_id'] ?? null,
        'source'        => 'medical_dictionary',
      ];
    }

    return $out;
  }

  /** @return list<array<string, mixed>> */
  private static function scanHiligaynonDataset(string $text): array
  {
    $normalized = mb_strtolower(trim($text));
    if ($normalized === '') {
      return [];
    }

    $terms = HiligaynonNlpDataset::termsByLength();
    $raw = self::scanLongestMatches($normalized, $terms);
    $out = [];
    foreach ($raw as $row) {
      $entry = HiligaynonNlpDataset::lookup($row['local_term']);
      if ($entry === null) {
        continue;
      }
      $out[] = [
        'local_term'   => $row['local_term'],
        'english_term' => (string) ($entry['english'] ?? $entry['medical_term'] ?? ''),
        'category'     => (string) ($entry['category'] ?? 'general'),
        'body_system'  => $entry['body_system'] ?? null,
        'dataset_id'   => $entry['id'] ?? null,
        'source'       => 'hiligaynon_nlp_dataset',
      ];
    }

    return $out;
  }

  /**
   * @param list<string> $terms
   * @return list<array{local_term:string}>
   */
  private static function scanLongestMatches(string $text, array $terms): array
  {
    if ($text === '' || $terms === []) {
      return [];
    }

    $len = strlen($text);
    $occupied = array_fill(0, $len, false);
    $candidates = [];

    foreach ($terms as $term) {
      $pattern = '/(?<!\w)' . preg_quote($term, '/') . '(?!\w)/iu';
      if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
          $start = $match[1];
          $end = $start + strlen($match[0]);
          $candidates[] = [$start, $end, $term, $end - $start];
        }
      }
    }

    usort($candidates, static function (array $a, array $b): int {
      if ($a[3] !== $b[3]) {
        return $b[3] <=> $a[3];
      }
      return $a[0] <=> $b[0];
    });

    $matched = [];
    foreach ($candidates as [$start, $end, $term]) {
      $overlap = false;
      for ($i = $start; $i < $end; $i++) {
        if (!empty($occupied[$i])) {
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
      $matched[] = ['local_term' => $term];
    }

    return $matched;
  }
}
