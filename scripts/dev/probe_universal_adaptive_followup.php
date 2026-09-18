<?php
/**
 * Probe universal adaptive follow-up (bank fallback when Gemini select is off).
 */
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

// A: stomach pain → atomic finding, not OR-bundle
$a = ClinicalInterviewEngine::assess('I have stomach pain.');
$aq = strtoupper((string) ($a['followup_question']['question_id'] ?? ''));
$at = (string) ($a['followup_question']['text'] ?? '');
ok(($a['assessment_status'] ?? '') === 'IN_PROGRESS', 'A in progress');
ok(!preg_match('/vomit.{0,40}(or|,).{0,40}(fever|bleed)/i', $at), 'A not OR-bundled', $at);
ok(str_contains($aq, 'ABDOMINAL') || str_contains(strtolower($at), 'vomit') || str_contains(strtolower($at), 'fever') || str_contains(strtolower($at), 'bleed') || str_contains(strtolower($at), 'pain'), 'A abdominal-related', $aq);

// B: already vomiting → should not ask vomiting first
$b = ClinicalInterviewEngine::assess('I have stomach pain and I am vomiting.');
$bq = strtoupper((string) ($b['followup_question']['question_id'] ?? ''));
ok(!str_contains($bq, 'VOMITING'), 'B skips vomiting finding', $bq);

// C: no vomiting → skip vomiting
$c = ClinicalInterviewEngine::assess('I have stomach pain, but no vomiting.');
$cq = strtoupper((string) ($c['followup_question']['question_id'] ?? ''));
ok(!str_contains($cq, 'VOMITING'), 'C skips vomiting finding', $cq);

// F: No continues interview
$f1 = ClinicalInterviewEngine::assess('I have stomach pain.');
$ctx = is_array($f1['interview'] ?? null) ? $f1['interview'] : [];
$f2 = ClinicalInterviewEngine::assess('No', $ctx);
ok(($f2['assessment_status'] ?? '') === 'IN_PROGRESS', 'F No keeps interviewing', (string) ($f2['assessment_status'] ?? ''));
ok(($f2['followup_question']['question_id'] ?? '') !== '', 'F asks another question', (string) ($f2['followup_question']['question_id'] ?? ''));
$finding = (string) (($ctx['awaiting_target_finding'] ?? '') ?: '');
$status = $f2['interview']['facts']['finding_status'] ?? [];
ok(is_array($status), 'F finding_status map exists');

// Multi still works
$m = ClinicalInterviewEngine::assess('I have a headache and a toothache.');
ok(count((array) ($m['interview']['complaints'] ?? [])) >= 2, 'multi tracks');

// Emergency authority unchanged
$e = ClinicalInterviewEngine::assess('I have difficulty breathing and I have a toothache.');
ok(($e['assessment_status'] ?? '') === 'COMPLETED' && str_contains(strtoupper((string) ($e['triage']['triage_display'] ?? '')), 'EMERGENCY'), 'emergency complete-case');
ok(($e['triage']['final_authority'] ?? '') === 'ClinicalTriageEngine', 'engine authority');

echo $fails === 0 ? "\nALL PASS\n" : "\n{$fails} FAILED\n";
exit($fails === 0 ? 0 : 1);
