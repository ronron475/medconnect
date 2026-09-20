<?php
/**
 * Stage 2A: interviewFacts plumbing into ClinicalTriageEngine (no decision use yet).
 *
 * php scripts/dev/test_interview_facts_plumbing.php
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

// 1) Backward compatible: no interviewFacts
$plain = ClinicalTriageEngine::assess('mild headache for two days', 'mild headache for two days');
ok('assess without interviewFacts returns triage_display', isset($plain['triage_display']));
ok('assess without interviewFacts echoes empty interview_facts',
    array_key_exists('interview_facts', $plain) && $plain['interview_facts'] === []);

// 2) Accepts interviewFacts and echoes them intact (plumbing only)
$facts = [
    'pain_score' => 9,
    'breathing_difficulty' => true,
    'bleeding_heavy' => false,
    'onset' => 'sudden',
    'body_locations' => ['chest'],
    'symptoms' => ['Chest Pain'],
];
$with = ClinicalTriageEngine::assess(
    'mild headache for two days',
    'mild headache for two days',
    [],
    [],
    0,
    true,
    $facts
);
ok('assess with interviewFacts returns triage_display', isset($with['triage_display']));
ok('interview_facts arrive intact at assessment boundary',
    ($with['interview_facts'] ?? null) === $facts,
    json_encode($with['interview_facts'] ?? null)
);

// 3) Same complaint text ± facts must not change class in Stage 2A (facts unused for decisions)
ok('Stage 2A plumbing does not change triage_display',
    (string) ($plain['triage_display'] ?? '') === (string) ($with['triage_display'] ?? ''),
    ($plain['triage_display'] ?? '') . ' vs ' . ($with['triage_display'] ?? '')
);

// 4) Interview engine passes structured facts through to engine result path
$open = ClinicalInterviewEngine::assess('Masakit akon ulo');
$ctxFacts = is_array($open['interview']['facts'] ?? null) ? $open['interview']['facts'] : [];
$pack = $ctxFacts;
$pack['pain_score'] = 7;
$pack['fever_confirmed'] = true;
$direct = ClinicalTriageEngine::assess(
    'Masakit akon ulo',
    'Masakit akon ulo',
    [],
    [],
    0,
    true,
    $pack
);
ok('interview-shaped facts echo pain_score',
    (int) (($direct['interview_facts']['pain_score'] ?? 0)) === 7);
ok('interview-shaped facts echo fever_confirmed',
    ($direct['interview_facts']['fever_confirmed'] ?? null) === true);

// 5) Multi-shaped pack (tracks) arrives without requiring synthetic phrases
$multiPack = [
    'active_complaint_id' => 'c1',
    'active_facts' => ['breathing_difficulty' => true],
    'tracks' => [
        [
            'complaint_id' => 'c1',
            'text_span' => 'budlay ginhawa',
            'family_keys' => ['respiratory'],
            'facts' => ['breathing_difficulty' => true],
        ],
        [
            'complaint_id' => 'c2',
            'text_span' => 'sakit tiyan',
            'family_keys' => ['abdominal'],
            'facts' => ['abdominal_associated' => true],
        ],
    ],
    'patient_evidence_text' => 'budlay ginhawa. sakit tiyan',
];
$multi = ClinicalTriageEngine::assess('budlay ginhawa', 'budlay ginhawa', [], [], 0, true, $multiPack);
ok('multi track pack arrives intact',
    ($multi['interview_facts'] ?? null) === $multiPack);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
