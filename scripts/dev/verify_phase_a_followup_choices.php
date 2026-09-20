<?php
declare(strict_types=1);

require __DIR__ . '/nlp_cli_bootstrap.php';

putenv('MEDCONNECT_SKIP_GEMINI_VALIDATION=1');
$_ENV['MEDCONNECT_SKIP_GEMINI_VALIDATION'] = '1';
putenv('MEDCONNECT_SKIP_GEMINI_FOLLOWUP=1');
$_ENV['MEDCONNECT_SKIP_GEMINI_FOLLOWUP'] = '1';
putenv('MEDCONNECT_SKIP_GEMINI_SELECT=1');
$_ENV['MEDCONNECT_SKIP_GEMINI_SELECT'] = '1';

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

// Pain chip "7"
$r = ClinicalInterviewEngine::assess('Gasakit tiil ko');
ok(qid($r) === 'PAIN_SEVERITY', 'pain question', qid($r));
$r = ClinicalInterviewEngine::assess('7', ctx($r));
ok((facts($r)['pain_score'] ?? null) === 7, 'pain chip value stores score');

// Yes/no polarity (Hiligaynon)
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
    $r = ClinicalInterviewEngine::assess($q === 'PAIN_SEVERITY' ? '5' : 'indi', ctx($r));
}
ok(qid($r) === 'NEURO_WEAKNESS', 'reached neuro', qid($r));
$r = ClinicalInterviewEngine::assess('indi', ctx($r));
ok((facts($r)['weakness'] ?? null) === false, 'yes/no chip indi → weakness false');

// Not sure
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
        $q === 'NEURO_WEAKNESS' => 'hindi',
        default => 'indi',
    };
    $r = ClinicalInterviewEngine::assess($ans, ctx($r));
}
ok(qid($r) === 'NEURO_SPEECH', 'reached speech', qid($r));
$r = ClinicalInterviewEngine::assess('hindi ako sure', ctx($r));
$status = (string) ((facts($r)['finding_status']['speech_difficulty'] ?? ''));
ok($status === 'uncertain', 'not sure chip → uncertain', $status);

// Timing chips
ok(ClinicalFeatureExtractors::extractDuration('kahapon')['label'] !== '', 'kahapon duration');
ok(ClinicalFeatureExtractors::extractDuration('today')['label'] !== '', 'today duration');
ok(ClinicalFeatureExtractors::extractDuration('2 days')['label'] !== '', '2 days duration');
ok(ClinicalFeatureExtractors::extractOnset('gulpi') === 'sudden', 'gulpi onset');
ok(ClinicalFeatureExtractors::extractOnset('unti-unti') === 'gradual', 'unti-unti onset');
ok(ClinicalFeatureExtractors::extractYesNo('oo') === true, 'oo yes');
ok(ClinicalFeatureExtractors::extractYesNo('indi') === false, 'indi no');
ok(ClinicalFeatureExtractors::looksPatientUncertain('indi ko sure'), 'indi ko sure uncertain');
ok(ClinicalFeatureExtractors::extractBodyLocations('ulo') !== [] || ClinicalFeatureExtractors::extractBodyLocations('head') !== [], 'location chip');

// Kind mapping parity (document expected control kinds)
$kinds = [
    'PAIN_SEVERITY' => 'pain',
    'ONSET' => 'onset',
    'DURATION' => 'duration',
    'NEURO_WEAKNESS' => 'yes_no',
    'ASSOCIATED_SYMPTOMS' => 'yes_no',
    'PAIN_LOCATION' => 'location',
    'ASSOCIATED_DETAIL' => 'free_text',
    'DIZZINESS_TYPE' => 'free_text',
    'COUGH_TYPE' => 'free_text',
    'URINARY_DETAIL' => 'free_text',
    'UNWELL_WHAT' => 'free_text',
    'FEVER_CONFIRM' => 'free_text',
];
foreach ($kinds as $id => $want) {
    // PHP-side mirror of JS resolveControlKind for documentation/check
    $base = str_contains($id, '__') ? explode('__', $id, 2)[0] : $id;
    $got = 'free_text';
    if (in_array($base, ['ASSOCIATED_DETAIL', 'DIZZINESS_TYPE', 'COUGH_TYPE', 'URINARY_DETAIL', 'UNWELL_WHAT', 'FEVER_CONFIRM'], true)
        || str_starts_with($base, 'URINARY')
    ) {
        $got = 'free_text';
    } elseif ($base === 'PAIN_SEVERITY' || (str_contains($base, 'SEVERITY') && $base !== 'BREATHING_SEVERITY')) {
        $got = 'pain';
    } elseif ($base === 'ONSET') {
        $got = 'onset';
    } elseif ($base === 'DURATION' || str_contains($base, 'DURATION')) {
        $got = 'duration';
    } elseif (str_contains($base, 'LOCATION') || str_contains($base, 'WHERE') || str_contains($base, 'SITE')
        || str_contains($base, 'LATERALITY') || in_array($base, ['PAIN_LOCATION', 'SPECIFIC_LOCATION', 'SKIN_SITE', 'NOSE_PAIN_WHERE', 'EYE_LATERALITY'], true)
    ) {
        $got = 'location';
    } elseif ($base === 'BREATHING_SEVERITY' || $base === 'ASSOCIATED_SYMPTOMS'
        || str_contains($base, 'NEURO') || str_contains($base, 'BLEEDING') || str_contains($base, 'BREATHING')
        || str_contains($base, 'CHEST') || str_contains($base, 'VISION') || str_contains($base, 'ABDOMINAL')
        || str_starts_with($base, 'FINDING_')
    ) {
        $got = 'yes_no';
    }
    ok($got === $want, "kind $id", "want=$want got=$got");
}

echo "\n" . ($fails === 0 ? 'ALL PASS' : "FAILED: $fails") . "\n";
exit($fails === 0 ? 0 : 1);
