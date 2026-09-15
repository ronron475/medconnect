<?php
/**
 * Multi-turn accumulation probe — complaint + follow-ups must form one clinical case.
 */
require __DIR__ . '/nlp_cli_bootstrap.php';

function show(array $r, string $label): array
{
    $t = (string) ($r['triage']['triage_display'] ?? $r['triage_display'] ?? '?');
    $f = is_array($r['interview']['facts'] ?? null) ? $r['interview']['facts'] : [];
    $pain = $f['pain_score'] ?? '-';
    $onset = (string) ($f['onset'] ?? '-');
    $syms = implode(',', array_slice((array) ($f['symptoms'] ?? []), 0, 8));
    $assoc = implode(',', array_slice((array) ($f['associated_symptoms'] ?? []), 0, 5));
    $negs = implode(',', array_slice((array) ($f['negative_symptoms'] ?? []), 0, 5));
    $breath = array_key_exists('breathing_difficulty', $f)
        ? (($f['breathing_difficulty'] === true) ? 'true' : (($f['breathing_difficulty'] === false) ? 'false' : 'null'))
        : '-';
    $status = (string) ($r['assessment_status'] ?? $r['interview']['assessment_status'] ?? '?');
    $q = (string) ($r['next_question']['question_id'] ?? $r['interview']['awaiting_question_id'] ?? '');
    $uncertain = !empty($f['patient_uncertain']) ? '1' : '0';
    echo "{$label} | triage={$t} | status={$status} | pain={$pain} | onset={$onset} | breath={$breath} | uncertain={$uncertain} | syms=[{$syms}] | assoc=[{$assoc}] | neg=[{$negs}] | await={$q}\n";

    return is_array($r['interview'] ?? null) ? $r['interview'] : [];
}

echo "=== A: headache accumulation ===\n";
$a = ChiefComplaintNlpService::assessInterview('Masakit gid ulo ko.');
$ctx = show($a, 'A1 headache');
$b = ChiefComplaintNlpService::assessInterview('8/10', $ctx);
$ctx = show($b, 'A2 pain8');
$c = ChiefComplaintNlpService::assessInterview('Ga suka ko.', $ctx);
$ctx = show($c, 'A3 vomit');
$d = ChiefComplaintNlpService::assessInterview('Nag gulpi kag grabe ang pagsugod.', $ctx);
$ctx = show($d, 'A4 sudden');

echo "=== B: negation cough ===\n";
$n1 = ChiefComplaintNlpService::assessInterview('May ubo kag sipon ko.');
$ctxn = show($n1, 'N1 cough');
$n2 = ChiefComplaintNlpService::assessInterview('wala ko ubo', $ctxn);
show($n2, 'N2 wala ubo');

echo "=== C: negated red flag ===\n";
$r1 = ChiefComplaintNlpService::assessInterview('sakit ulo ko');
$ctxr = show($r1, 'R1');
$r2 = ChiefComplaintNlpService::assessInterview('wala ko difficulty breathing', $ctxr);
show($r2, 'R2 no dyspnea');

echo "=== D: uncertain ===\n";
$u1 = ChiefComplaintNlpService::assessInterview('sakit tiyan ko');
$ctxu = show($u1, 'U1');
$await = (string) ($ctxu['awaiting_question_id'] ?? '');
if ($await !== '') {
    $u2 = ChiefComplaintNlpService::assessInterview('Ambot', $ctxu);
    show($u2, 'U2 ambot');
} else {
    echo "U2 skipped — no awaiting question\n";
}

echo "=== E: isolation vs accumulated ===\n";
$alone = ClinicalTriageEngine::assess(
    'Nag gulpi kag grabe ang pagsugod.',
    'Nag gulpi kag grabe ang pagsugod.'
);
echo 'alone last-answer triage=' . (string) ($alone['triage_display'] ?? '?') . "\n";
$fullHay = 'Masakit gid ulo ko. 8/10. Ga suka ko. Nag gulpi kag grabe ang pagsugod. pain 8/10. sudden onset. vomiting. headache';
$full = ClinicalTriageEngine::assess($fullHay, $fullHay);
echo 'accumulated haystack triage=' . (string) ($full['triage_display'] ?? '?') . "\n";

echo "=== F: galupot diarrhea ===\n";
$g = ChiefComplaintNlpService::assessInterview('permi galupot akon tiyan');
show($g, 'F1 galupot');

echo "=== G: misspelled hiligaynon ===\n";
$m = ChiefComplaintNlpService::assessInterview('saket olo ko');
show($m, 'G1 saket olo');
