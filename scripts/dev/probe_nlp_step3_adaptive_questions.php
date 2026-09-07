<?php
require __DIR__ . '/nlp_cli_bootstrap.php';

function ok(bool $c, string $l, string $d = ''): void
{
    echo ($c ? 'PASS  ' : 'FAIL  ') . $l . ($d !== '' ? " — {$d}" : '') . "\n";
    if (!$c) {
        $GLOBALS['fails'] = ($GLOBALS['fails'] ?? 0) + 1;
    }
}
$GLOBALS['fails'] = 0;

function assess(string $text): array
{
    return NlpStep3DemoTrial::assess($text, [], ['allow_gemini' => false]);
}

echo "=== Universal adaptive clinical questions ===\n";

$abd = assess('Masakit tiyan ko');
$abdQ = (string) ($abd['followup_question']['question_id'] ?? '');
$abdConcepts = (array) ($abd['active_concepts'] ?? $abd['completeness']['active_concepts'] ?? []);
ok(in_array('abdominal_pain', $abdConcepts, true)
    || ($abd['completeness']['family'] ?? '') === 'abdominal_pain',
    'abd concepts include abdominal', json_encode($abdConcepts) . ' fam=' . ($abd['completeness']['family'] ?? ''));
ok(in_array('specific_location', (array) ($abd['missing_fields'] ?? []), true)
    || ($abd['followup_question']['question_id'] ?? '') === 'PAIN_SEVERITY',
    'abd tracks specific_location or asks severity first', json_encode($abd['missing_fields'] ?? []));
ok(in_array(($abd['followup_question']['question_id'] ?? ''), ['PAIN_SEVERITY', 'SPECIFIC_LOCATION', 'ABDOMINAL_ASSOCIATED', 'ONSET'], true),
    'abd next triage-relevant', (string) ($abd['followup_question']['question_id'] ?? ''));
ok(str_contains(mb_strtolower((string) ($abd['followup_question']['text'] ?? '')), 'tiyan')
    || str_contains(mb_strtolower((string) ($abd['patient_message'] ?? '')), 'tiyan')
    || ($abd['followup_question']['question_id'] ?? '') === 'PAIN_SEVERITY',
    'abd question is abdomen-aware or severity', (string) ($abd['followup_question']['text'] ?? ''));

$abdSpec = assess('Masakit sa tuo nga idalom sang tiyan ko');
ok(!in_array('specific_location', (array) ($abdSpec['missing_fields'] ?? []), true), 'specific abd NOT missing location', json_encode($abdSpec['missing_fields'] ?? []));
ok(!in_array(($abdSpec['followup_question']['question_id'] ?? ''), ['SPECIFIC_LOCATION', 'PAIN_LOCATION'], true),
    'specific abd not re-ask location', (string) ($abdSpec['followup_question']['question_id'] ?? ''));

$head = assess('kasakit ulo ko, tatlo na ka semana, 3/10');
ok(!in_array('onset', (array) ($head['missing_fields'] ?? []), true), 'head no onset missing', json_encode($head['missing_fields'] ?? []));
ok(!in_array('severity', (array) ($head['missing_fields'] ?? []), true), 'head no severity missing', json_encode($head['missing_fields'] ?? []));
ok(($head['followup_question']['question_id'] ?? '') === 'ASSOCIATED_SYMPTOMS'
    || in_array('associated_symptoms', (array) ($head['missing_fields'] ?? []), true)
    || (($head['assessment_status'] ?? '') === 'COMPLETED'),
    'head next associated or triage-sufficient', (string) ($head['followup_question']['question_id'] ?? '') . ' ' . json_encode($head['missing_fields'] ?? []) . ' ' . ($head['assessment_status'] ?? ''));

$cough = assess('ginaubo ko');
$coughConcepts = (array) ($cough['active_concepts'] ?? $cough['completeness']['active_concepts'] ?? []);
ok(in_array('cough', $coughConcepts, true)
    || ($cough['completeness']['family'] ?? '') === 'cough',
    'cough concept', json_encode($coughConcepts) . ' fam=' . ($cough['completeness']['family'] ?? ''));
ok(($cough['followup_question']['question_id'] ?? '') !== 'PAIN_SEVERITY', 'cough not pain severity', (string) ($cough['followup_question']['question_id'] ?? ''));
ok(in_array(($cough['followup_question']['question_id'] ?? ''), ['ONSET', 'DURATION', 'COUGH_TYPE', 'BREATHING_SEVERITY', 'FEVER_CONFIRM'], true),
    'cough next respiratory-relevant', (string) ($cough['followup_question']['question_id'] ?? ''));

$eye = assess('Masakit akon mata');
$eyeConcepts = (array) ($eye['active_concepts'] ?? $eye['completeness']['active_concepts'] ?? []);
ok(str_contains((string) ($eye['completeness']['family'] ?? ''), 'eye')
    || in_array('eye', $eyeConcepts, true),
    'eye concept', (string) ($eye['completeness']['family'] ?? '') . ' ' . json_encode($eyeConcepts));
ok(($eye['followup_question']['question_id'] ?? '') === 'EYE_LATERALITY'
    || in_array('laterality', (array) ($eye['missing_fields'] ?? []), true),
    'eye asks which eye', (string) ($eye['followup_question']['question_id'] ?? '') . ' ' . json_encode($eye['missing_fields'] ?? []));

$chest = assess('Masakit dughan ko');
ok(($chest['followup_question']['question_id'] ?? '') === 'BREATHING_SEVERITY'
    || ($chest['followup_question']['question_id'] ?? '') === 'SPECIFIC_LOCATION'
    || in_array('specific_location', (array) ($chest['missing_fields'] ?? []), true)
    || in_array('dyspnea', (array) ($chest['missing_fields'] ?? []), true),
    'chest asks breathing or exact location', (string) ($chest['followup_question']['question_id'] ?? '') . ' ' . json_encode($chest['missing_fields'] ?? []));

$chestSpec = assess('Masakit sa tuo nga dughan ko');
ok(!in_array('specific_location', (array) ($chestSpec['missing_fields'] ?? []), true), 'specific chest not re-ask site', json_encode($chestSpec['missing_fields'] ?? []));

// Unseen / less-common complaints should still get a dynamic path (not a fixed generic pain scale only).
$skin = assess('May rash kag kati sa akon braso');
$skinConcepts = (array) ($skin['active_concepts'] ?? $skin['completeness']['active_concepts'] ?? []);
ok(in_array('skin', $skinConcepts, true)
    || ($skin['completeness']['family'] ?? '') === 'skin',
    'skin concept detected', json_encode($skinConcepts));
ok(($skin['followup_question']['question_id'] ?? '') !== '', 'skin asks something relevant', (string) ($skin['followup_question']['question_id'] ?? ''));
ok(($skin['followup_question']['question_id'] ?? '') !== 'PAIN_SEVERITY', 'skin not forced pain scale', (string) ($skin['followup_question']['question_id'] ?? ''));

$urine = assess('Masakit mag-ihi ko');
$urineConcepts = (array) ($urine['active_concepts'] ?? $urine['completeness']['active_concepts'] ?? []);
ok(in_array('urinary', $urineConcepts, true)
    || ($urine['completeness']['family'] ?? '') === 'urinary',
    'urinary concept', json_encode($urineConcepts));
ok(in_array(($urine['followup_question']['question_id'] ?? ''), ['URINARY_DETAIL', 'ONSET', 'ASSOCIATED_SYMPTOMS', 'DURATION'], true),
    'urinary relevant next', (string) ($urine['followup_question']['question_id'] ?? ''));

ok(in_array(($skin['followup_question']['question_id'] ?? ''), ['SKIN_SITE', 'ONSET', 'ASSOCIATED_SYMPTOMS', 'DURATION'], true),
    'skin relevant next', (string) ($skin['followup_question']['question_id'] ?? ''));

echo "\nFails: " . (int) ($GLOBALS['fails'] ?? 0) . "\n";
exit(($GLOBALS['fails'] ?? 0) > 0 ? 1 : 0);
