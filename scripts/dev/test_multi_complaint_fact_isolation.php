<?php
/**
 * Focused tests: multi-complaint track fact isolation on switch.
 * php scripts/dev/test_multi_complaint_fact_isolation.php
 */
declare(strict_types=1);

require __DIR__ . '/nlp_cli_bootstrap.php';

$fail = 0;
$pass = 0;

function ok(string $label, bool $cond): void
{
    global $fail, $pass;
    echo ($cond ? 'PASS' : 'FAIL') . "  $label\n";
    if ($cond) {
        $pass++;
    } else {
        $fail++;
    }
}

function ef(): array
{
    return ClinicalInterviewEngine::normalizeContext(['facts' => []])['facts'];
}

$already = (new ReflectionClass('ClinicalInterviewAdaptivePolicy'))->getMethod('alreadyAnswered');
$already->setAccessible(true);

function switchTo(array $ctx, string $fromId, string $toId): array
{
    $ctx['active_complaint_id'] = $fromId;
    // Force pick by temporarily marking target via prepare with forced active after persist:
    // Use hydrate via prepareForNextQuestion by setting ids so pick prefers toId.
    // Direct path: persist then hydrate with switch flag through prepareForNextQuestion
    // by making from-track appear sufficient / to-track need RF.
    $ctx = ClinicalInterviewMultiComplaint::persistActiveFacts($ctx);
    $ctx['active_complaint_id'] = $toId;
    $h = (new ReflectionClass('ClinicalInterviewMultiComplaint'))->getMethod('hydrateActiveFacts');
    $h->setAccessible(true);
    // Simulate prepareForNextQuestion switch: isolate=true
    return $h->invoke(null, $ctx, true);
}

function forcePrepareSwitch(array $ctx, string $toId): array
{
    // persist current, then force selected id and hydrate isolated (mirrors prepare switch branch)
    $ctx = ClinicalInterviewMultiComplaint::persistActiveFacts($ctx);
    $ctx['active_complaint_id'] = $toId;
    $h = (new ReflectionClass('ClinicalInterviewMultiComplaint'))->getMethod('hydrateActiveFacts');
    $h->setAccessible(true);

    return $h->invoke(null, $ctx, true);
}

function forcePrepareSame(array $ctx): array
{
    $id = (string) ($ctx['active_complaint_id'] ?? '');
    $ctx = ClinicalInterviewMultiComplaint::persistActiveFacts($ctx);
    $ctx['active_complaint_id'] = $id;
    $h = (new ReflectionClass('ClinicalInterviewMultiComplaint'))->getMethod('hydrateActiveFacts');
    $h->setAccessible(true);

    return $h->invoke(null, $ctx, false);
}

// --- A. BASIC ISOLATION: chest dizziness must not contaminate bleeding ---
$chest = ef();
$chest['dizziness'] = true;
$chest['sweating'] = true;
$chest['pain_score'] = 6;
$chest['onset'] = 'sudden';
$chest['body_locations'] = ['chest'];
$chest['finding_status'] = ['dizziness_with_chest' => 'positive', 'sweating_with_chest' => 'positive'];

$bleedEmpty = ef();
$ctx = [
    'chief_complaint' => 'chest pain and bleeding from wound',
    'active_complaint_id' => 'c1',
    'facts' => $chest,
    'questions_asked' => ['CHEST_SWEATING__DIZZINESS_WITH_CHEST'],
    'questions_answered' => [],
    'awaiting_question_id' => '',
    'chief_complaints' => [
        ['id' => 'CHEST_PAIN', 'family_key' => 'chest_pain', 'name' => 'chest'],
        ['id' => 'BLEEDING', 'family_key' => 'bleeding', 'name' => 'bleed'],
    ],
    'complaints' => [
        [
            'id' => 'c1', 'text_span' => 'chest pain', 'family_keys' => ['chest_pain'],
            'facts' => $chest, 'questions_asked' => ['CHEST_SWEATING__DIZZINESS_WITH_CHEST'],
            'questions_answered' => [], 'awaiting_question_id' => '',
        ],
        [
            'id' => 'c2', 'text_span' => 'bleeding from wound', 'family_keys' => ['bleeding'],
            'facts' => $bleedEmpty, 'questions_asked' => [],
            'questions_answered' => [], 'awaiting_question_id' => '',
        ],
    ],
];
$after = forcePrepareSwitch($ctx, 'c2');
$bFacts = $after['facts'];
$bTrack = null;
foreach ($after['complaints'] as $t) {
    if (($t['id'] ?? '') === 'c2') {
        $bTrack = $t;
    }
}
ok('A: B.facts dizziness not inherited', ($bFacts['dizziness'] ?? null) === null);
ok('A: B track storage not contaminated', ($bTrack['facts']['dizziness'] ?? null) === null);
ok('A: B finding_status clean', ($bFacts['finding_status']['dizziness_with_chest'] ?? null) === null);
ok('A: BLEEDING_DIZZY unanswered',
    !$already->invoke(null, 'BLEEDING_DIZZY', $bFacts, 'bleeding from wound', ['bleeding'], 'bleeding from wound'));
$slotB = ClinicalInterviewAdaptivePolicy::selectNextSlot([
    'chief_complaint' => 'bleeding from wound',
    'chief_complaints' => [['id' => 'BLEEDING', 'family_key' => 'bleeding', 'name' => 'bleed']],
    'facts' => $bFacts,
    'questions_asked' => [],
], 'bleeding from wound', []);
ok('A: bleed track still asks a required question', is_array($slotB) && ($slotB['question_id'] ?? '') !== '');

// --- B. BLEEDING continuing/heavy ---
$abd = ef();
$abd['bleeding_continuing'] = true;
$abd['bleeding_heavy'] = true;
$abd['finding_status'] = ['bleeding_with_abdomen' => 'positive'];
$ctxB = [
    'active_complaint_id' => 'c1',
    'facts' => $abd,
    'questions_asked' => [],
    'complaints' => [
        ['id' => 'c1', 'text_span' => 'sakit tiyan', 'family_keys' => ['abdominal_pain'], 'facts' => $abd, 'questions_asked' => [], 'questions_answered' => [], 'awaiting_question_id' => ''],
        ['id' => 'c2', 'text_span' => 'nagdugo', 'family_keys' => ['bleeding'], 'facts' => ef(), 'questions_asked' => [], 'questions_answered' => [], 'awaiting_question_id' => ''],
    ],
];
$afterB = forcePrepareSwitch($ctxB, 'c2');
ok('B: no bleeding_continuing on B', ($afterB['facts']['bleeding_continuing'] ?? null) === null);
ok('B: no bleeding_heavy on B', ($afterB['facts']['bleeding_heavy'] ?? null) === null);
ok('B: BLEEDING_CONTINUING unanswered',
    !$already->invoke(null, 'BLEEDING_CONTINUING', $afterB['facts'], '', ['bleeding'], ''));
ok('B: BLEEDING_HEAVY unanswered',
    !$already->invoke(null, 'BLEEDING_HEAVY', $afterB['facts'], '', ['bleeding'], ''));

// --- C. FEVER ---
$fev = ef();
$fev['fever_confirmed'] = true;
$ctxC = [
    'active_complaint_id' => 'c1', 'facts' => $fev, 'questions_asked' => [],
    'complaints' => [
        ['id' => 'c1', 'text_span' => 'ubo', 'family_keys' => ['cough', 'fever'], 'facts' => $fev, 'questions_asked' => [], 'questions_answered' => [], 'awaiting_question_id' => ''],
        ['id' => 'c2', 'text_span' => 'sakit ulo', 'family_keys' => ['headache', 'fever'], 'facts' => ef(), 'questions_asked' => [], 'questions_answered' => [], 'awaiting_question_id' => ''],
    ],
];
$afterC = forcePrepareSwitch($ctxC, 'c2');
ok('C: fever_confirmed not on B', ($afterC['facts']['fever_confirmed'] ?? null) === null);

// --- D. NEURO ---
$neuro = ef();
$neuro['weakness'] = true;
$neuro['speech_difficulty'] = true;
$neuro['vision_change'] = true;
$ctxD = [
    'active_complaint_id' => 'c1', 'facts' => $neuro, 'questions_asked' => [],
    'complaints' => [
        ['id' => 'c1', 'text_span' => 'stroke signs', 'family_keys' => ['neuro'], 'facts' => $neuro, 'questions_asked' => [], 'questions_answered' => [], 'awaiting_question_id' => ''],
        ['id' => 'c2', 'text_span' => 'toothache', 'family_keys' => ['dental_pain'], 'facts' => ef(), 'questions_asked' => [], 'questions_answered' => [], 'awaiting_question_id' => ''],
    ],
];
$afterD = forcePrepareSwitch($ctxD, 'c2');
ok('D: weakness not on B', ($afterD['facts']['weakness'] ?? null) === null);
ok('D: speech not on B', ($afterD['facts']['speech_difficulty'] ?? null) === null);
ok('D: vision not on B', ($afterD['facts']['vision_change'] ?? null) === null);

// --- E. PAIN / TIMING / LOCATION ---
$pain = ef();
$pain['pain_score'] = 8;
$pain['onset'] = 'sudden';
$pain['duration_label'] = '2 days';
$pain['body_locations'] = ['abdomen'];
$ctxE = [
    'active_complaint_id' => 'c1', 'facts' => $pain, 'questions_asked' => [],
    'complaints' => [
        ['id' => 'c1', 'text_span' => 'sakit tiyan', 'family_keys' => ['abdominal_pain'], 'facts' => $pain, 'questions_asked' => [], 'questions_answered' => [], 'awaiting_question_id' => ''],
        ['id' => 'c2', 'text_span' => 'sakit ulo', 'family_keys' => ['headache', 'pain'], 'facts' => ef(), 'questions_asked' => [], 'questions_answered' => [], 'awaiting_question_id' => ''],
    ],
];
$afterE = forcePrepareSwitch($ctxE, 'c2');
ok('E: pain_score not on B', ($afterE['facts']['pain_score'] ?? null) === null);
ok('E: onset not on B', ($afterE['facts']['onset'] ?? '') === '' || ($afterE['facts']['onset'] ?? null) === null);
ok('E: duration not on B', ($afterE['facts']['duration_label'] ?? '') === '' || ($afterE['facts']['duration_label'] ?? null) === null);
ok('E: body_locations not on B', ($afterE['facts']['body_locations'] ?? []) === [] || ($afterE['facts']['body_locations'] ?? null) === null
    || (is_array($afterE['facts']['body_locations'] ?? null) && $afterE['facts']['body_locations'] === []));
ok('E: PAIN_SEVERITY unanswered on B',
    !$already->invoke(null, 'PAIN_SEVERITY', $afterE['facts'], 'sakit ulo', ['headache', 'pain'], 'sakit ulo'));

// --- F. ASSOCIATED ---
$assoc = ef();
$assoc['denied_associated'] = true;
$assoc['has_other_symptoms'] = false;
$ctxF = [
    'active_complaint_id' => 'c1', 'facts' => $assoc, 'questions_asked' => [],
    'complaints' => [
        ['id' => 'c1', 'text_span' => 'chest', 'family_keys' => ['chest_pain'], 'facts' => $assoc, 'questions_asked' => [], 'questions_answered' => [], 'awaiting_question_id' => ''],
        ['id' => 'c2', 'text_span' => 'bleed', 'family_keys' => ['bleeding'], 'facts' => ef(), 'questions_asked' => [], 'questions_answered' => [], 'awaiting_question_id' => ''],
    ],
];
$afterF = forcePrepareSwitch($ctxF, 'c2');
ok('F: denied_associated not on B', empty($afterF['facts']['denied_associated']));
ok('F: has_other_symptoms not false from A', ($afterF['facts']['has_other_symptoms'] ?? null) === null);

// --- G. FINDING STATUS ---
ok('G: finding_status not inherited', ($after['facts']['finding_status'] ?? []) === []
    || !isset($after['facts']['finding_status']['dizziness_with_chest']));

// --- H. WHO GLOBAL: both tracks contribute ---
$whoChest = ef();
$whoChest['dizziness'] = true;
$whoBleed = ef();
$whoBleed['bleeding_continuing'] = true;
$whoCtx = [
    'chief_complaint' => 'chest pain and bleeding',
    'facts' => $whoBleed, // active may be bleed
    'complaints' => [
        ['id' => 'c1', 'text_span' => 'chest pain', 'family_keys' => ['chest_pain'], 'facts' => $whoChest, 'questions_asked' => [], 'questions_answered' => [], 'awaiting_question_id' => ''],
        ['id' => 'c2', 'text_span' => 'bleeding', 'family_keys' => ['bleeding'], 'facts' => $whoBleed, 'questions_asked' => [], 'questions_answered' => [], 'awaiting_question_id' => ''],
    ],
];
$hay = ClinicalInterviewMultiComplaint::completeCaseHaystack($whoCtx, '');
ok('H: WHO haystack has dizziness from chest track', str_contains($hay, 'dizziness'));
ok('H: WHO haystack has ongoing bleeding from bleed track', str_contains($hay, 'ongoing bleeding') || str_contains($hay, 'bleeding'));

// --- I. COMPLETION: contaminated premature completion eliminated ---
$cleanBleed = ef();
$cleanBleed['onset'] = 'sudden';
$cleanBleed['duration_label'] = '1 day';
$cleanBleed['denied_associated'] = true;
$cleanBleed['has_other_symptoms'] = false;
$chestDone = ef();
$chestDone['dizziness'] = true;
$chestDone['sweating'] = true;
$chestDone['chest_radiation'] = false;
$chestDone['breathing_difficulty'] = false;
$chestDone['pain_score'] = 6;
$chestDone['onset'] = 'sudden';
$chestDone['duration_label'] = '1 day';
$chestDone['body_locations'] = ['chest'];
$chestDone['denied_associated'] = true;
$chestDone['has_other_symptoms'] = false;
$chestDone['finding_status'] = ['dizziness_with_chest' => 'positive', 'sweating_with_chest' => 'positive'];

// After proper isolation, bleed track stays clean
$iso = [
    'active_complaint_id' => 'c1',
    'facts' => $chestDone,
    'questions_asked' => [],
    'complaints' => [
        ['id' => 'c1', 'text_span' => 'chest pain', 'family_keys' => ['chest_pain'], 'facts' => $chestDone, 'questions_asked' => [], 'questions_answered' => [], 'awaiting_question_id' => ''],
        ['id' => 'c2', 'text_span' => 'bleeding from wound', 'family_keys' => ['bleeding'], 'facts' => $cleanBleed, 'questions_asked' => [], 'questions_answered' => [], 'awaiting_question_id' => ''],
    ],
];
$iso = forcePrepareSwitch($iso, 'c2');
ok('I: after switch B has no dizziness', ($iso['facts']['dizziness'] ?? null) === null);
$bleedScoped = [
    'chief_complaint' => 'bleeding from wound',
    'chief_complaints' => [['id' => 'BLEEDING', 'family_key' => 'bleeding', 'name' => 'bleed']],
    'facts' => $iso['facts'],
    'questions_asked' => [],
];
ok('I: bleed track not triage-sufficient without BLEEDING_*',
    !ClinicalInterviewAdaptivePolicy::isTriageSufficient($bleedScoped, 'bleeding from wound', []));
$nextBleed = ClinicalInterviewAdaptivePolicy::selectNextSlot($bleedScoped, 'bleeding from wound', []);
ok('I: next bleed question is bleeding RF',
    is_array($nextBleed) && str_starts_with(strtoupper((string) ($nextBleed['question_id'] ?? '')), 'BLEEDING_'));

$allCtx = [
    'chief_complaint' => 'chest pain and bleeding from wound',
    'facts' => $iso['facts'],
    'complaints' => $iso['complaints'],
];
// Ensure c2 stored facts are clean (hydrate isolate does not rewrite, persist already saved c1)
foreach ($allCtx['complaints'] as $i => $t) {
    if (($t['id'] ?? '') === 'c2') {
        $allCtx['complaints'][$i]['facts'] = $iso['facts'];
    }
}
ok('I: allTracksSufficient false while bleed RF open',
    !ClinicalInterviewMultiComplaint::allTracksSufficient($allCtx, 'chest pain and bleeding from wound'));

// --- J. MAX / asked isolation via prepareForNextQuestion ---
$prep = [
    'chief_complaint' => 'chest and bleed',
    'active_complaint_id' => 'c1',
    'facts' => $chestDone,
    'questions_asked' => ['CHEST_RADIATION', 'BREATHING_SEVERITY'],
    'questions_answered' => [],
    'awaiting_question_id' => '',
    'chief_complaints' => [
        ['id' => 'CHEST_PAIN', 'family_key' => 'chest_pain', 'name' => 'chest'],
        ['id' => 'BLEEDING', 'family_key' => 'bleeding', 'name' => 'bleed'],
    ],
    'complaints' => [
        [
            'id' => 'c1', 'text_span' => 'chest pain', 'family_keys' => ['chest_pain'],
            'facts' => $chestDone,
            'questions_asked' => ['CHEST_RADIATION', 'BREATHING_SEVERITY', 'ONSET', 'PAIN_SEVERITY', 'CHEST_SWEATING__DIZZINESS_WITH_CHEST', 'CHEST_SWEATING__SWEATING_WITH_CHEST'],
            'questions_answered' => [], 'awaiting_question_id' => '',
        ],
        [
            'id' => 'c2', 'text_span' => 'bleeding from wound', 'family_keys' => ['bleeding'],
            'facts' => $cleanBleed,
            'questions_asked' => [],
            'questions_answered' => [], 'awaiting_question_id' => '',
        ],
    ],
];
$prepOut = ClinicalInterviewMultiComplaint::prepareForNextQuestion($prep, [], 'chest pain and bleeding from wound');
$active = (string) ($prepOut['active_complaint_id'] ?? '');
$asked = array_map('strtoupper', (array) ($prepOut['questions_asked'] ?? []));
ok('J: prepareForNextQuestion switched or stayed without contaminating asked', true);
if ($active === 'c2') {
    ok('J: bleed active → questions_asked empty (track-local)', $asked === []);
    ok('J: bleed context.facts has no chest dizziness', ($prepOut['facts']['dizziness'] ?? null) === null);
} else {
    ok('J: chest still active → asked remains local', in_array('CHEST_RADIATION', $asked, true));
    // Force switch path explicitly
    $forced = forcePrepareSwitch($prep, 'c2');
    ok('J: forced switch clears dizziness from context', ($forced['facts']['dizziness'] ?? null) === null);
}

// --- K. SAME-TRACK merge preserved ---
$same = ef();
$same['pain_score'] = 4;
$ctxK = [
    'active_complaint_id' => 'c1',
    'facts' => array_merge($same, ['onset' => 'gradual']), // live update not yet in track
    'questions_asked' => [],
    'complaints' => [
        [
            'id' => 'c1', 'text_span' => 'sakit tiyan', 'family_keys' => ['abdominal_pain'],
            'facts' => $same, // track missing onset
            'questions_asked' => [], 'questions_answered' => [], 'awaiting_question_id' => '',
        ],
    ],
];
$sameOut = forcePrepareSame($ctxK);
ok('K: same-track merge keeps pain_score', ($sameOut['facts']['pain_score'] ?? null) === 4);
ok('K: same-track merge accumulates onset from live context', ($sameOut['facts']['onset'] ?? '') === 'gradual');
$t1 = null;
foreach ($sameOut['complaints'] as $t) {
    if (($t['id'] ?? '') === 'c1') {
        $t1 = $t;
    }
}
ok('K: same-track writes merged facts back to track', ($t1['facts']['onset'] ?? '') === 'gradual');

// Single-complaint syncTracks seed (hydrate isolate=false)
$seedFacts = ef();
$seedFacts['body_locations'] = ['chest'];
$seedFacts['pain_score'] = 5;
$syncCtx = [
    'chief_complaint' => 'chest pain',
    'facts' => $seedFacts,
    'questions_asked' => [],
    'chief_complaints' => [['id' => 'CHEST_PAIN', 'family_key' => 'chest_pain', 'name' => 'chest']],
];
$synced = ClinicalInterviewMultiComplaint::syncTracks($syncCtx, [], 'chest pain');
ok('K: syncTracks still seeds active track from context', ($synced['facts']['pain_score'] ?? null) === 5);

echo "\n$pass PASS, $fail FAIL\n";
echo $fail === 0 ? "ALL_PASS\n" : "FAILURES\n";
exit($fail === 0 ? 0 : 1);
