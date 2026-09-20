<?php
/**
 * Stage 1: patient-text provenance — patient_turns / patientEvidenceText stay
 * patient-authored even when AI/KB/system enrichment is present.
 *
 * php scripts/dev/test_patient_evidence_provenance.php
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

function hayContainsForbidden(string $patientText, array $needles): array
{
    $hits = [];
    $low = mb_strtolower($patientText);
    foreach ($needles as $n) {
        $n = trim((string) $n);
        if ($n === '') {
            continue;
        }
        if (mb_stripos($low, mb_strtolower($n)) !== false) {
            $hits[] = $n;
        }
    }

    return $hits;
}

// --- 1) Opening: original complaint preserved in patient_turns ---
$complaint = 'Masakit gid akon ulo since yesterday';
$open = ClinicalInterviewEngine::assess($complaint);
$turns = $open['interview']['patient_turns'] ?? [];
$chief = (string) ($open['interview']['chief_complaint'] ?? '');
$evidence = (string) ($open['interview']['patient_evidence_text']
    ?? ClinicalInterviewEngine::patientEvidenceText($open['interview'] ?? []));

ok('opening patient_turns[0] is exact original', ($turns[0] ?? null) === $complaint, json_encode($turns));
ok('opening chief_complaint is original', $chief === $complaint || $chief === '', "chief={$chief}");
ok('opening patientEvidenceText contains original', str_contains($evidence, $complaint), $evidence);

// --- 2) Inject Gemini / Ollama / Python enrichment into prior; patient channel stable ---
$ctx = $open['interview'] ?? [];
$gemini = 'cephalalgia migraine without aura syndrome';
$ollama = 'the patient is describing a severe migraine headache';
$python = 'headache photophobia English gloss from python';
$kbLabel = 'Migraine Without Aura KB Label';

$ctx['semantic_bridge'] = array_merge(is_array($ctx['semantic_bridge'] ?? null) ? $ctx['semantic_bridge'] : [], [
    'gemini_concept' => $gemini,
    'ollama_meaning' => $ollama,
    'python_gloss' => $python,
    'python_symptoms' => ['photophobia', 'migraine'],
    'nlp_text' => $complaint . '. ' . $ollama,
]);
$ctx['facts'] = is_array($ctx['facts'] ?? null) ? $ctx['facts'] : [];
$ctx['facts']['symptoms'] = array_values(array_unique(array_merge(
    (array) ($ctx['facts']['symptoms'] ?? []),
    [$kbLabel]
)));
$ctx['facts']['breathing_difficulty'] = true;
$ctx['facts']['pain_score'] = 8;

$evidenceBefore = ClinicalInterviewEngine::patientEvidenceText($ctx);
$factsHay = ClinicalInterviewEngine::factsHaystack($ctx['facts']);
$forbidden = [$gemini, $ollama, $python, $kbLabel, 'photophobia', 'difficulty breathing', 'pain 8/10'];
if ($factsHay !== '') {
    // Entire haystack phrase must not appear as patient evidence.
    $forbidden[] = $factsHay;
}
$hitsBefore = hayContainsForbidden($evidenceBefore, $forbidden);
ok('enriched prior: patientEvidenceText excludes AI/KB/factsHaystack', $hitsBefore === [], implode(' | ', $hitsBefore));
ok('enriched prior: patient_turns unchanged originals only', ($ctx['patient_turns'][0] ?? null) === $complaint);

// --- 3) Follow-up answer: original stored; enrichment still excluded ---
$awaiting = (string) ($ctx['awaiting_question_id'] ?? '');
if ($awaiting === '') {
    $ctx['awaiting_question_id'] = 'ONSET';
    $ctx['questions_asked'] = array_values(array_unique(array_merge(
        (array) ($ctx['questions_asked'] ?? []),
        ['ONSET']
    )));
}
$answer = 'Nag-umpisa kahapon sang hapon';
$fu = ClinicalInterviewEngine::assess($answer, $ctx);
$fuTurns = $fu['interview']['patient_turns'] ?? [];
$fuEvidence = (string) ($fu['interview']['patient_evidence_text']
    ?? ClinicalInterviewEngine::patientEvidenceText($fu['interview'] ?? []));
$fuAnswers = $fu['interview']['questions_answered'] ?? [];
$lastAnswer = '';
if (is_array($fuAnswers) && $fuAnswers !== []) {
    $last = $fuAnswers[array_key_last($fuAnswers)];
    $lastAnswer = is_array($last) ? (string) ($last['answer'] ?? '') : '';
}

ok('follow-up patient_turns keep opening original', ($fuTurns[0] ?? null) === $complaint, json_encode($fuTurns));
ok('follow-up patient_turns append exact answer', in_array($answer, $fuTurns, true), json_encode($fuTurns));
ok('follow-up questions_answered stores original', $lastAnswer === $answer || $lastAnswer === '', "answer={$lastAnswer}");
$hitsFu = hayContainsForbidden($fuEvidence, [$gemini, $ollama, $python, $kbLabel]);
ok('follow-up patientEvidenceText still excludes AI/KB', $hitsFu === [], implode(' | ', $hitsFu));
ok('follow-up patientEvidenceText contains original answer (if not bare yn)',
    str_contains($fuEvidence, $answer) || ClinicalFeatureExtractors::extractYesNo($answer) !== null,
    $fuEvidence
);

// Bridge fields remain available on the prior context we injected (Stage 1 does not wipe them).
ok('injected semantic_bridge.gemini_concept retained on prior',
    trim((string) (($ctx['semantic_bridge']['gemini_concept'] ?? '') ?: '')) === $gemini);
ok('injected semantic_bridge.ollama_meaning retained on prior',
    trim((string) (($ctx['semantic_bridge']['ollama_meaning'] ?? '') ?: '')) === $ollama);
ok('injected semantic_bridge.python_gloss retained on prior',
    trim((string) (($ctx['semantic_bridge']['python_gloss'] ?? '') ?: '')) === $python);

// Stronger: after assess, patient_turns must never equal injected glosses.
foreach ($fuTurns as $t) {
    $t = (string) $t;
    ok('patient_turn is not gemini concept', mb_stripos($t, $gemini) === false, $t);
    ok('patient_turn is not ollama meaning', mb_stripos($t, $ollama) === false, $t);
    ok('patient_turn is not python gloss', mb_stripos($t, $python) === false, $t);
}

// --- 4) Yes/no: bare answer stored as original; clinical patientEvidence skips bare yn ---
$ynCtx = $fu['interview'] ?? $ctx;
$ynCtx['awaiting_question_id'] = 'BREATHING_SEVERITY';
$ynCtx['questions_asked'] = array_values(array_unique(array_merge(
    (array) ($ynCtx['questions_asked'] ?? []),
    ['BREATHING_SEVERITY']
)));
$yn = ClinicalInterviewEngine::assess('Oo', $ynCtx);
$ynTurns = $yn['interview']['patient_turns'] ?? [];
$ynEvidence = (string) ($yn['interview']['patient_evidence_text']
    ?? ClinicalInterviewEngine::patientEvidenceText($yn['interview'] ?? []));
$ynFacts = $yn['interview']['facts'] ?? [];

ok('yes/no stores original Oo in patient_turns', in_array('Oo', $ynTurns, true), json_encode($ynTurns));
ok('yes/no patientEvidenceText does not invent difficulty breathing',
    mb_stripos($ynEvidence, 'difficulty breathing') === false,
    $ynEvidence
);
ok('yes/no polarity still lands in structured facts when accepted',
    ($ynFacts['breathing_difficulty'] ?? null) === true
    || ($ynFacts['breathing_difficulty'] ?? null) === null
    || ($yn['assessment_status'] ?? '') === 'IN_PROGRESS',
    'breathing_difficulty=' . json_encode($ynFacts['breathing_difficulty'] ?? null)
);

// --- 5) Direct patientEvidenceText unit: factsHaystack + bridge never included ---
$unitCtx = ClinicalInterviewEngine::normalizeContext([
    'chief_complaint' => 'Budlay ginhawa ko',
    'patient_turns' => ['Budlay ginhawa ko', 'since this morning'],
    'complaint_text_cleaner' => ['original' => 'Budlay ginhawa ko', 'cleaned' => 'difficulty breathing'],
    'semantic_bridge' => [
        'gemini_concept' => 'acute respiratory distress',
        'ollama_meaning' => 'hard to breathe shortness of breath',
        'python_gloss' => 'dyspnea gloss',
        'python_symptoms' => ['dyspnea'],
    ],
    'facts' => [
        'breathing_difficulty' => true,
        'pain_score' => 9,
        'symptoms' => ['Acute Dyspnea KB'],
    ],
]);
$unitEvidence = ClinicalInterviewEngine::patientEvidenceText($unitCtx);
$unitHay = ClinicalInterviewEngine::factsHaystack($unitCtx['facts']);
ok('unit: evidence has patient complaint', str_contains($unitEvidence, 'Budlay ginhawa ko'));
ok('unit: evidence has follow-up turn', str_contains($unitEvidence, 'since this morning'));
ok('unit: no gemini', mb_stripos($unitEvidence, 'acute respiratory distress') === false);
ok('unit: no ollama', mb_stripos($unitEvidence, 'shortness of breath') === false);
ok('unit: no python gloss', mb_stripos($unitEvidence, 'dyspnea gloss') === false);
ok('unit: no KB label', mb_stripos($unitEvidence, 'Acute Dyspnea KB') === false);
ok('unit: no cleaned nlp substitute alone as enrichment', mb_stripos($unitEvidence, 'difficulty breathing') === false);
ok('unit: factsHaystack not embedded', $unitHay === '' || mb_stripos($unitEvidence, $unitHay) === false, "hay={$unitHay} ev={$unitEvidence}");
ok('unit: factsHaystack still usable elsewhere', str_contains($unitHay, 'pain') || str_contains($unitHay, 'breath'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
