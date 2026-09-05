<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

function ok(bool $cond, string $label, string $detail = ''): void
{
    echo ($cond ? 'PASS  ' : 'FAIL  ') . $label . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "=== Skip follow-up when sufficient ===\n";

$vague = NlpStep3DemoTrial::assess('sakit');
ok(($vague['followup_question']['question_id'] ?? '') === 'PAIN_SEVERITY', 'vague still asks severity');
ok(($vague['assessment_status'] ?? '') === 'IN_PROGRESS', 'vague stays in progress');

$partial = NlpStep3DemoTrial::assess('Masakit akon ulo 7/10 halin gahapon.');
ok(($partial['complaint_summary']['pain_severity'] ?? '') === '7/10', 'partial severity');
ok(($partial['complaint_summary']['location'] ?? '') === 'head', 'partial location');
ok(!in_array(($partial['followup_question']['question_id'] ?? ''), ['PAIN_SEVERITY', 'PAIN_LOCATION', 'ONSET'], true), 'partial skips answered core', (string) ($partial['followup_question']['question_id'] ?? 'null'));
ok(($partial['assessment_status'] ?? '') === 'IN_PROGRESS', 'partial still needs associated/red-flag check', (string) ($partial['assessment_status'] ?? ''));
ok(empty($partial['followup_skipped']), 'partial does not skip all follow-up yet');

$rich = 'Masakit akon ulo 7/10 halin gahapon, daw pulsing kag mas nagagrabe kung maglihok. May hilo man ko pero wala ko nagsuka kag wala hilanat.';
$r = NlpStep3DemoTrial::assess($rich);
ok(($r['assessment_status'] ?? '') === 'COMPLETED', 'rich completes', (string) ($r['assessment_status'] ?? ''));
ok(!empty($r['followup_skipped']), 'rich skips remaining bank questions');
ok(($r['followup_question'] ?? null) === null, 'rich has no next question');
ok(in_array(($r['triage_final'] ?? ''), ['EMERGENCY', 'URGENT', 'NON-URGENT'], true), 'rich final triage', (string) ($r['triage_final'] ?? ''));
ok(($r['complaint_summary']['pain_severity'] ?? '') === '7/10', 'rich severity');
ok(($r['complaint_summary']['location'] ?? '') === 'head', 'rich location');
ok(str_contains((string) ($r['complaint_summary']['duration'] ?? ''), 'yesterday'), 'rich duration', (string) ($r['complaint_summary']['duration'] ?? ''));
ok(($r['complaint_summary']['character'] ?? '') === 'pulsating', 'rich character', (string) ($r['complaint_summary']['character'] ?? ''));
ok(str_contains((string) ($r['complaint_summary']['associated_symptoms'] ?? ''), 'dizziness'), 'rich dizziness', (string) ($r['complaint_summary']['associated_symptoms'] ?? ''));
ok(str_contains((string) ($r['complaint_summary']['pertinent_negatives'] ?? ''), 'vomit')
    || str_contains((string) ($r['complaint_summary']['pertinent_negatives'] ?? ''), 'fever')
    || str_contains((string) ($r['complaint_summary']['associated_symptoms'] ?? ''), 'denied'), 'rich denials noted', (string) (($r['complaint_summary']['pertinent_negatives'] ?? '') . '|' . ($r['complaint_summary']['associated_symptoms'] ?? '')));
ok(!str_contains((string) ($r['followup_question']['text'] ?? ''), 'Pila ang pain'), 'does not re-ask pain score');

$mid = NlpStep3DemoTrial::assess('Masakit akon ulo 7/10 halin gahapon, pulsing, may hilo, wala nagsuka, wala hilanat.');
ok(($mid['assessment_status'] ?? '') === 'COMPLETED', 'mid rich completes', (string) ($mid['assessment_status'] ?? ''));
ok(!empty($mid['followup_skipped']), 'mid rich skips follow-up');
