<?php
/**
 * Probe multi-complaint interview isolation (PHP-only).
 * php scripts/dev/probe_multi_complaint_interview.php
 */
declare(strict_types=1);

putenv('MEDCONNECT_SKIP_GEMINI_VALIDATION=1');
$_ENV['MEDCONNECT_SKIP_GEMINI_VALIDATION'] = '1';
putenv('MEDCONNECT_SKIP_GEMINI_FOLLOWUP=1');
$_ENV['MEDCONNECT_SKIP_GEMINI_FOLLOWUP'] = '1';

require __DIR__ . '/nlp_cli_bootstrap.php';

$fails = 0;
function ok(bool $c, string $l, string $d = ''): void
{
    global $fails;
    echo ($c ? 'PASS  ' : 'FAIL  ') . $l . ($d !== '' ? " — {$d}" : '') . "\n";
    if (!$c) {
        $fails++;
    }
}

$r = ClinicalInterviewEngine::assess('I have a headache and I have a toothache.');
$tracks = $r['interview']['complaints'] ?? [];
$n = is_array($tracks) ? count($tracks) : 0;
$families = [];
foreach ((array) $tracks as $t) {
    if (is_array($t)) {
        $families[] = implode('+', (array) ($t['family_keys'] ?? []));
    }
}
ok($n >= 2, 'multi tracks created', 'n=' . $n . ' families=' . implode('|', $families));
$qid = (string) ($r['followup_question']['question_id'] ?? '');
$active = (string) ($r['interview']['active_complaint_id'] ?? '');
ok($qid !== '', 'asks one follow-up', 'qid=' . $qid . ' active=' . $active);
ok(($r['assessment_status'] ?? '') === 'IN_PROGRESS', 'stays in progress');

// Single complaint still works.
$s = ClinicalInterviewEngine::assess('My stomach hurts');
$sn = count((array) ($s['interview']['complaints'] ?? []));
ok($sn <= 1 || (($s['followup_question']['question_id'] ?? '') === 'ABDOMINAL_ASSOCIATED'), 'single abdomen path', 'tracks=' . $sn . ' q=' . ($s['followup_question']['question_id'] ?? ''));
ok(($s['followup_question']['question_id'] ?? '') === 'ABDOMINAL_ASSOCIATED'
    || str_contains(strtolower((string) ($s['followup_question']['text'] ?? '')), 'abdom'), 'abdomen-relevant question');

// Hiligaynon multi
$h = ClinicalInterviewEngine::assess('Masakit ang ulo ko kag masakit ang ngipon ko');
$hn = count((array) ($h['interview']['complaints'] ?? []));
ok($hn >= 2, 'hiligaynon multi tracks', 'n=' . $hn);

// Answer turn: keep interviewing; may switch active complaint.
$ctx = $r['interview'] ?? [];
$r2 = ClinicalInterviewEngine::assess('No', is_array($ctx) ? $ctx : []);
ok(($r2['assessment_status'] ?? '') === 'IN_PROGRESS' || ($r2['assessment_status'] ?? '') === 'COMPLETED', 'answer turn continues', 'status=' . ($r2['assessment_status'] ?? ''));
ok(($r2['followup_question']['question_id'] ?? '') !== '' || ($r2['assessment_status'] ?? '') === 'COMPLETED', 'next question or complete', 'q2=' . ($r2['followup_question']['question_id'] ?? '') . ' active=' . ($r2['interview']['active_complaint_id'] ?? ''));

// Complete-case emergency authority (not MAX of tracks).
$e = ClinicalInterviewEngine::assess('I have difficulty breathing and I have a toothache.');
$eClass = (string) ($e['triage']['triage_display'] ?? '');
$eStatus = (string) ($e['assessment_status'] ?? '');
$eAuth = (string) ($e['triage']['final_authority'] ?? '');
$eMax = $e['triage']['max_urgency_aggregation'] ?? null;
$eProv = is_array($e['complaint_provisionals'] ?? null) ? $e['complaint_provisionals'] : [];
ok(
    $eStatus === 'COMPLETED' && str_contains(strtoupper($eClass), 'EMERGENCY'),
    'breathing+toothache → case EMERGENCY',
    'status=' . $eStatus . ' class=' . $eClass . ' tracks=' . count((array) ($e['interview']['complaints'] ?? []))
);
ok($eAuth === 'ClinicalTriageEngine', 'final authority is ClinicalTriageEngine', 'auth=' . $eAuth);
ok($eMax === false, 'MAX aggregation disabled', 'max=' . json_encode($eMax));
ok($eProv !== [] || count((array) ($e['interview']['complaints'] ?? [])) <= 1, 'provisionals attached when multi', 'n=' . count($eProv));

echo $fails === 0 ? "\nALL PASS\n" : "\n{$fails} FAILED\n";
exit($fails === 0 ? 0 : 1);
