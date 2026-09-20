<?php
declare(strict_types=1);

require __DIR__ . '/nlp_cli_bootstrap.php';

putenv('MEDCONNECT_SKIP_GEMINI_VALIDATION=1');
$_ENV['MEDCONNECT_SKIP_GEMINI_VALIDATION'] = '1';
putenv('MEDCONNECT_SKIP_GEMINI_FOLLOWUP=1');
$_ENV['MEDCONNECT_SKIP_GEMINI_FOLLOWUP'] = '1';
putenv('MEDCONNECT_SKIP_GEMINI_SELECT=1');
$_ENV['MEDCONNECT_SKIP_GEMINI_SELECT'] = '1';
putenv('MEDCONNECT_SKIP_GEMINI_BODY_LOCATION=1');
$_ENV['MEDCONNECT_SKIP_GEMINI_BODY_LOCATION'] = '1';

$fails = 0;
function ok(bool $c, string $l, string $d = ''): void
{
    global $fails;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $l . ($d !== '' ? " — {$d}" : '') . "\n";
    if (!$c) {
        $fails++;
    }
}

function ctx(array $r): array
{
    return is_array($r['interview'] ?? null) ? $r['interview'] : [];
}

function qid(array $r): string
{
    return strtoupper((string) ($r['followup_question']['question_id'] ?? ctx($r)['awaiting_question_id'] ?? ''));
}

function facts(array $r): array
{
    $c = ctx($r);
    return is_array($c['facts'] ?? null) ? $c['facts'] : [];
}

function lang(array $r): string
{
    return strtolower((string) (ctx($r)['question_language'] ?? $r['question_language'] ?? ''));
}

// --- Language unit ---
foreach ([
    ['Masakit ang ulo ko', 'tagalog'],
    ['diko kaginhawa', 'hiligaynon'],
    ['indi ko kaginhawa', 'hiligaynon'],
    ['I have a headache', 'english'],
    ['Gasakit tiil ko', 'hiligaynon'],
] as [$t, $want]) {
    $d = HiligaynonLanguageDetector::detect($t);
    $ql = ClinicalInterviewEngine::questionLanguageFromDetection($d, $t);
    ok($ql === $want, "lang:$t", "got=$ql primary={$d['primary']} dom=" . ($d['dominant'] ?? ''));
}

// --- English pain ---
$r = ClinicalInterviewEngine::assess('I have severe foot pain');
ok(lang($r) === 'english', 'EN language');
ok(qid($r) === 'PAIN_SEVERITY', 'EN pain severity first', qid($r));
$r = ClinicalInterviewEngine::assess('7', ctx($r));
ok((facts($r)['pain_score'] ?? null) === 7, 'EN pain score stored');
ok(qid($r) !== 'PAIN_SEVERITY', 'EN no repeated severity', qid($r));

// --- Hiligaynon breathing ---
$r = ClinicalInterviewEngine::assess('diko kaginhawa');
ok(lang($r) === 'hiligaynon', 'HIL diko lang', lang($r));
ok(qid($r) === 'BREATHING_SEVERITY', 'HIL diko breathing', qid($r));
$r = ClinicalInterviewEngine::assess('indi ko kaginhawa');
ok(lang($r) === 'hiligaynon', 'HIL indi lang', lang($r));
ok(qid($r) === 'BREATHING_SEVERITY', 'HIL indi breathing', qid($r));

// --- Tagalog headache ---
$r = ClinicalInterviewEngine::assess('Masakit ang ulo ko');
ok(lang($r) === 'tagalog', 'TL language', lang($r));
ok(qid($r) === 'PAIN_SEVERITY', 'TL severity first', qid($r));

// --- Fever / cough: no PAIN_SEVERITY ---
$r = ClinicalInterviewEngine::assess('I have fever');
ok(qid($r) !== 'PAIN_SEVERITY', 'fever no pain severity', qid($r));
$r = ClinicalInterviewEngine::assess('I have a cough');
ok(qid($r) !== 'PAIN_SEVERITY', 'cough no pain severity', qid($r));

// --- Hiligaynon pain score ---
$r = ClinicalInterviewEngine::assess('Gasakit tiil ko');
ok(qid($r) === 'PAIN_SEVERITY', 'HIL pain severity', qid($r));
$r = ClinicalInterviewEngine::assess('7', ctx($r));
ok((facts($r)['pain_score'] ?? null) === 7, 'HIL pain score');
ok(qid($r) !== 'PAIN_SEVERITY', 'HIL no repeat severity', qid($r));

// --- Off-slot timing on ASSOCIATED ---
$r = ClinicalInterviewEngine::assess('Gasakit tiil ko');
$r = ClinicalInterviewEngine::assess('7', ctx($r));
// answer onset so we can reach associated, then reset timing for the real off-slot case
// Better path: drive to ASSOCIATED with empty timing by answering neuro/onset minimally
for ($i = 0; $i < 8; $i++) {
    $q = qid($r);
    if ($q === '' || ($r['assessment_status'] ?? '') === 'COMPLETED') {
        break;
    }
    if ($q === 'ASSOCIATED_SYMPTOMS') {
        // Clear timing to simulate unanswered onset stored empty — if already set, still volunteer kahapon
        $beforeOnset = (string) (facts($r)['onset'] ?? '');
        $beforeDur = (string) (facts($r)['duration_label'] ?? '');
        $r = ClinicalInterviewEngine::assess('kahapon', ctx($r));
        $f = facts($r);
        $keptSlot = qid($r) === 'ASSOCIATED_SYMPTOMS' && !empty($r['retry_current_question']);
        ok($keptSlot, 'off-slot preserves ASSOCIATED slot');
        $stored = trim((string) ($f['duration_label'] ?? '')) !== '' || trim((string) ($f['onset'] ?? '')) !== '';
        // If timing was empty before, kahapon must fill it; if already filled, must not wipe
        if ($beforeOnset === '' && $beforeDur === '') {
            ok($stored && (stripos((string) ($f['duration_label'] ?? $f['onset'] ?? ''), 'kahapon') !== false
                || stripos((string) ($f['onset'] ?? ''), 'Yesterday') !== false
                || stripos((string) ($f['duration_label'] ?? ''), 'Yesterday') !== false
                || stripos((string) ($f['duration_label'] ?? ''), 'kahapon') !== false), 'off-slot stores kahapon timing', json_encode([
                'onset' => $f['onset'] ?? '',
                'dur' => $f['duration_label'] ?? '',
            ]));
        } else {
            ok($stored, 'off-slot keeps prior timing', json_encode([
                'onset' => $f['onset'] ?? '',
                'dur' => $f['duration_label'] ?? '',
            ]));
        }
        break;
    }
    $ans = match (true) {
        $q === 'PAIN_SEVERITY' => '6',
        $q === 'ONSET' || $q === 'DURATION' => 'kanina',
        default => 'indi',
    };
    $r = ClinicalInterviewEngine::assess($ans, ctx($r));
}

// --- Pure off-slot: force ASSOCIATED with empty timing via direct context ---
$r = ClinicalInterviewEngine::assess('Gasakit tiil ko');
$r = ClinicalInterviewEngine::assess('8', ctx($r));
$c = ctx($r);
$c['facts']['onset'] = '';
$c['facts']['duration_label'] = '';
$c['awaiting_question_id'] = 'ASSOCIATED_SYMPTOMS';
$c['last_followup_question'] = [
    'question_id' => 'ASSOCIATED_SYMPTOMS',
    'text' => 'May iban pa bala nga sintomas?',
    'language' => 'hiligaynon',
];
$r = ClinicalInterviewEngine::assess('kahapon', $c);
$f = facts($r);
ok(!empty($r['retry_current_question']) && qid($r) === 'ASSOCIATED_SYMPTOMS', 'forced off-slot keeps ASSOCIATED');
ok(
    stripos((string) ($f['duration_label'] ?? ''), 'kahapon') !== false
    || stripos((string) ($f['onset'] ?? ''), 'kahapon') !== false
    || stripos((string) ($f['duration_label'] ?? ''), 'Yesterday') !== false
    || stripos((string) ($f['onset'] ?? ''), 'Yesterday') !== false,
    'forced off-slot stores kahapon',
    json_encode(['onset' => $f['onset'] ?? '', 'dur' => $f['duration_label'] ?? ''])
);

// --- Wrong-slot yes/no on ONSET ---
$r = ClinicalInterviewEngine::assess('Gasakit tiil ko');
$r = ClinicalInterviewEngine::assess('5', ctx($r));
$c = ctx($r);
$c['awaiting_question_id'] = 'ONSET';
$c['last_followup_question'] = [
    'question_id' => 'ONSET',
    'text' => 'San-o ini nagsugod?',
    'language' => 'hiligaynon',
];
$before = facts($r);
$r = ClinicalInterviewEngine::assess('wala man', $c);
ok(!empty($r['retry_current_question']) && qid($r) === 'ONSET', 'wala man keeps ONSET');
ok((facts($r)['onset'] ?? '') === ($before['onset'] ?? '') || (facts($r)['onset'] ?? '') === '', 'wala man does not invent onset', (string) (facts($r)['onset'] ?? ''));
$msg = (string) ($r['patient_message'] ?? '');
ok($msg !== '' && !str_starts_with(mb_strtolower($msg), 'san-o ini nagsugod?'), 'wala man clarify not identical blind', $msg);
$r = ClinicalInterviewEngine::assess('no', ctx($r));
ok(!empty($r['retry_current_question']) && qid($r) === 'ONSET', 'no keeps ONSET');

// --- Negative neuro must not add arm/leg ---
$r = ClinicalInterviewEngine::assess('Masakit ang ulo ko');
$r = ClinicalInterviewEngine::assess('6', ctx($r));
for ($i = 0; $i < 6; $i++) {
    if (qid($r) === 'NEURO_WEAKNESS') {
        break;
    }
    $q = qid($r);
    if ($q === '') {
        break;
    }
    $r = ClinicalInterviewEngine::assess($q === 'PAIN_SEVERITY' ? '6' : 'indi', ctx($r));
}
ok(qid($r) === 'NEURO_WEAKNESS', 'reached NEURO_WEAKNESS', qid($r));
$r = ClinicalInterviewEngine::assess('wala naman', ctx($r));
$locs = array_map('strtolower', (array) (facts($r)['body_locations'] ?? []));
ok(!in_array('arm', $locs, true) && !in_array('leg', $locs, true), 'wala naman no arm/leg', json_encode($locs));
ok((facts($r)['weakness'] ?? null) === false, 'wala naman sets weakness false');

// --- Uncertain neuro advances (no loop) ---
$r = ClinicalInterviewEngine::assess('Masakit ang ulo ko');
$r = ClinicalInterviewEngine::assess('5', ctx($r));
for ($i = 0; $i < 8; $i++) {
    if (qid($r) === 'NEURO_SPEECH') {
        break;
    }
    $q = qid($r);
    if ($q === '') {
        break;
    }
    $ans = match (true) {
        $q === 'PAIN_SEVERITY' => '5',
        $q === 'ONSET', $q === 'DURATION' => 'kahapon',
        $q === 'NEURO_WEAKNESS' => 'indi',
        default => 'indi',
    };
    $r = ClinicalInterviewEngine::assess($ans, ctx($r));
}
ok(qid($r) === 'NEURO_SPEECH', 'reached NEURO_SPEECH', qid($r));
$r = ClinicalInterviewEngine::assess('hindi ako sure', ctx($r));
$status = (string) ((facts($r)['finding_status']['speech_difficulty'] ?? ''));
ok($status === 'uncertain', 'uncertain sets finding_status', $status);
ok(qid($r) !== 'NEURO_SPEECH' || empty($r['retry_current_question']), 'uncertain does not loop NEURO_SPEECH', qid($r) . ' retry=' . (!empty($r['retry_current_question']) ? '1' : '0'));

echo "\n" . ($fails === 0 ? 'ALL PASS' : "FAILED: $fails") . "\n";
exit($fails === 0 ? 0 : 1);
