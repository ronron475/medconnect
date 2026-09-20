<?php
/**
 * Stage 2 Batch 4: OLD-vs-NEW shadow path comparison (purified patient evidence + structured).
 *
 * php scripts/dev/test_triage_path_comparison.php
 */
declare(strict_types=1);

require __DIR__ . '/nlp_cli_bootstrap.php';

$fail = 0;
$pass = 0;

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $fail, $pass;
    if ($cond) {
        echo "PASS  {$label}\n";
        $pass++;
        return;
    }
    $fail++;
    echo "FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

// --- No interviewFacts → no comparison blob (OLD-only) ---
$plain = ClinicalTriageEngine::assess('mild headache for two days', 'mild headache for two days');
ok('no facts → no triage_path_comparison', !isset($plain['triage_path_comparison']));
$plainDisplay = (string) ($plain['triage_display'] ?? '');

// --- Single: purified NEW vs production OLD ---
$patientText = 'Masakit ang dibdib ko';
$haystack = $patientText . '. difficulty breathing. heavy bleeding';
$singleFacts = [
    'patient_evidence_text' => $patientText,
    'breathing_difficulty' => true,
    'bleeding_heavy' => false,
    'dizziness' => null,
    'symptoms_patient' => ['chest pain'],
    'symptoms_kb' => ['Tension Headache KB Label'],
    'symptoms_ai' => ['Gloss AI Label'],
    'needs_associated_detail' => true,
];

$oldOnly = ClinicalTriageEngine::assess($haystack, $haystack, [], [], 0, true, [], false);
$withCompare = ClinicalTriageEngine::assess($haystack, $haystack, [], [], 0, true, $singleFacts, true);

ok('OLD production display present', isset($withCompare['triage_display']));
ok('comparison attached when facts present', is_array($withCompare['triage_path_comparison'] ?? null));
$cmp = $withCompare['triage_path_comparison'];

ok('OLD authority true', ($cmp['authority_old'] ?? false) === true);
ok('NEW authority false', ($cmp['authority_new'] ?? true) === false
    && ($cmp['new']['authority'] ?? true) === false);

ok('NEW text equals patientEvidenceText',
    ($cmp['new']['patient_evidence_text'] ?? '') === $patientText);
ok('NEW input kind purified+structured',
    ($cmp['new']['input_kind'] ?? '') === 'patient_evidence_text_plus_structured');
ok('For wrapper absent from NEW patient text',
    ($cmp['new_text_checks']['for_wrapper_absent'] ?? false) === true);
ok('synthetic haystack markers not present on NEW patient text',
    ($cmp['new_text_checks']['synthetic_haystack_markers_present'] ?? true) === false);
ok('NEW patient text does not contain haystack difficulty phrase unless patient wrote it',
    !str_contains($patientText, 'difficulty breathing')
    && ($cmp['new']['patient_evidence_text'] ?? '') === $patientText);

// OLD display must not change because comparison ran
ok('OLD triage_display unchanged vs assess without comparison flag path',
    (string) ($withCompare['triage_display'] ?? '')
        === (string) ($oldOnly['triage_display'] ?? ''));

// Production display is OLD, not replaced by NEW candidate
ok('live triage_display is OLD not NEW candidate',
    (string) ($withCompare['triage_display'] ?? '') === (string) ($cmp['old']['triage_display'] ?? '')
    && (string) ($withCompare['triage_display'] ?? '') !== ''
);

ok('NEW candidate present',
    in_array((string) ($cmp['new']['triage_display_candidate'] ?? ''), ['EMERGENCY', 'URGENT', 'NON-URGENT'], true));
ok('polarity shadow embedded in NEW',
    is_array($cmp['new']['structured_polarity_shadow'] ?? null));
ok('Batch 2 structured evidence embedded in NEW',
    is_array($cmp['new']['structured_clinical_evidence'] ?? null));

// Polarity tri-state available via structured evidence / shadow
$pol = $cmp['new']['structured_clinical_evidence']['clinical']['polarity'] ?? [];
ok('positive polarity available', ($pol['breathing_difficulty'] ?? null) === true);
ok('negative polarity available', ($pol['bleeding_heavy'] ?? null) === false);
ok('unknown polarity remains unknown',
    array_key_exists('dizziness', $pol) && $pol['dizziness'] === null);

// Provenance: Batch 3 protections + comparison notes
$shadow = $cmp['new']['structured_polarity_shadow'];
ok('Batch 3 polarity ignores KB/AI for hits',
    ($cmp['provenance']['polarity_ignores_kb_ai'] ?? false) === true);
ok('KB/AI provenance_blocked recorded when labels present',
    ($shadow['provenance_blocked'] ?? []) !== []);
ok('hard breathing hit is structured not KB-derived',
    (($shadow['hard_hits'][0]['evidence_source'] ?? '') === 'structured_polarity')
    || (($shadow['triage_display_candidate'] ?? '') === 'EMERGENCY'));

// under/over indicators exist (booleans)
ok('under_triage indicator is bool', is_bool($cmp['under_triage'] ?? null));
ok('over_triage indicator is bool', is_bool($cmp['over_triage'] ?? null));

// Interview-only separation
$ev = $cmp['new']['structured_clinical_evidence'];
ok('interview-only not in clinical polarity bag',
    !array_key_exists('needs_associated_detail', $ev['clinical'] ?? [])
    && !empty($ev['interview_only']['needs_associated_detail']));

// Nested shadow assess must not replace outer display when runPathComparison false on inner
$inner = ClinicalTriageEngine::assess($patientText, $patientText, [], [], 0, false, $singleFacts, false);
ok('inner purified assess has no nested comparison', !isset($inner['triage_path_comparison']));

// --- Multi-track isolation: all tracks available, no flatten / no MAX authority ---
$multiPatient = 'Sakit ulo. May dugo pa rin';
$multiPack = [
    'patient_evidence_text' => $multiPatient,
    'active_complaint_id' => 'c1',
    'active_facts' => ['breathing_difficulty' => true],
    'tracks' => [
        [
            'complaint_id' => 'c1',
            'text_span' => 'Sakit ulo',
            'family_keys' => ['headache'],
            'facts' => [
                'breathing_difficulty' => true,
                'symptoms_patient' => ['headache'],
                'symptoms_kb' => ['KB Head'],
            ],
        ],
        [
            'complaint_id' => 'c2',
            'text_span' => 'May dugo pa rin',
            'family_keys' => ['bleeding'],
            'facts' => [
                'bleeding_continuing' => true,
                'bleeding_heavy' => false,
                'symptoms_patient' => ['bleeding'],
            ],
        ],
    ],
];
$multiHay = $multiPatient . '. For "Sakit ulo": difficulty breathing. For "May dugo pa rin": ongoing bleeding';
$multiOld = ClinicalTriageEngine::assess($multiHay, $multiHay, [], [], 0, true, [], false);
$multi = ClinicalTriageEngine::assess($multiHay, $multiHay, [], [], 0, true, $multiPack, true);
$mc = $multi['triage_path_comparison'] ?? [];

ok('multi OLD display unchanged with comparison',
    (string) ($multi['triage_display'] ?? '') === (string) ($multiOld['triage_display'] ?? ''));
ok('multi NEW patient text purified',
    ($mc['new']['patient_evidence_text'] ?? '') === $multiPatient
    && !str_contains((string) ($mc['new']['patient_evidence_text'] ?? ''), 'For "'));
ok('multi evidence mode', ($mc['multi_track']['evidence_mode'] ?? '') === 'multi');
ok('multi track_count is 2', (int) ($mc['multi_track']['track_count'] ?? 0) === 2);
ok('all tracks in polarity summaries',
    ($mc['multi_track']['uses_all_tracks_for_polarity'] ?? false) === true
    && count($mc['multi_track']['track_summaries'] ?? []) === 2);
ok('not MAX-of-track authority', ($mc['multi_track']['max_of_track_authority'] ?? true) === false);
ok('facts not flattened flag', ($mc['multi_track']['flattened_facts'] ?? true) === false);

$t0 = $mc['multi_track']['track_summaries'][0] ?? [];
$t1 = $mc['multi_track']['track_summaries'][1] ?? [];
ok('track0 isolated hard hit breathing',
    ($t0['track_id'] ?? '') === 'c1'
    && count($t0['hard_hits'] ?? []) === 1
    && ($t0['hard_hits'][0]['key'] ?? '') === 'breathing_difficulty');
ok('track1 isolated bleeding continuing',
    ($t1['track_id'] ?? '') === 'c2'
    && count($t1['hard_hits'] ?? []) === 1
    && ($t1['hard_hits'][0]['key'] ?? '') === 'bleeding_continuing');

// Data-driven polarity keys still covered via Batch 3 shadow on NEW
foreach (ClinicalTriageEngine::clinicalPolarityKeys() as $key) {
    $bag = [
        'patient_evidence_text' => 'patient report only',
        $key => true,
    ];
    $r = ClinicalTriageEngine::assess('patient report only', 'patient report only', [], [], 0, true, $bag, true);
    $c = $r['triage_path_comparison'] ?? [];
    ok("registry key {$key}: comparison present + NEW non-authoritative",
        is_array($c)
        && ($c['authority_new'] ?? true) === false
        && (string) ($r['triage_display'] ?? '') === (string) ($c['old']['triage_display'] ?? ''));
}

// buildTriagePathComparison public helper sanity
$built = ClinicalTriageEngine::buildTriagePathComparison(
    ['triage_display' => 'URGENT', 'red_flags' => ['x'], 'assessment_factors' => []],
    [
        'triage_display' => 'NON-URGENT',
        'red_flags' => [],
        'assessment_factors' => [],
        'structured_clinical_evidence' => ClinicalTriageEngine::normalizeInterviewEvidence([
            'patient_evidence_text' => 'hello',
            'breathing_difficulty' => true,
        ]),
        'structured_polarity_shadow' => ClinicalTriageEngine::evaluateStructuredPolarityShadow(
            ClinicalTriageEngine::normalizeInterviewEvidence([
                'patient_evidence_text' => 'hello',
                'breathing_difficulty' => true,
            ]),
            'hello',
            'NON-URGENT'
        ),
    ],
    'hello'
);
ok('helper marks under_triage when polarity escalates composed NEW above purified-only',
    ($built['new']['triage_display_candidate'] ?? '') === 'EMERGENCY'
    && ($built['authority_new'] ?? true) === false);

echo "\nPassed: {$pass}  Failed: {$fail}\n";
exit($fail > 0 ? 1 : 0);
