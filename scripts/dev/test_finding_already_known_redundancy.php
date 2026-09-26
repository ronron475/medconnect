<?php
/**
 * Regression: AdaptivePolicy closes atomic slots when complaint/bridge already states the finding.
 *
 * php scripts/dev/test_finding_already_known_redundancy.php
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

$policy = new ReflectionClass('ClinicalInterviewAdaptivePolicy');
$known = $policy->getMethod('findingAlreadyKnown');
$known->setAccessible(true);
$list = $policy->getMethod('listCandidateSlots');
$list->setAccessible(true);
$hayFn = $policy->getMethod('fullCaseHaystack');
$hayFn->setAccessible(true);

/**
 * @param list<string> $open
 */
function openIds(array $cands): array
{
    return array_values(array_map(
        static fn ($r) => strtoupper((string) ($r['question_id'] ?? '')),
        $cands
    ));
}

/**
 * @param array<string, mixed> $facts
 * @param array<string, mixed> $bridge
 * @return array<string, mixed>
 */
function familyCtx(string $cc, string $familyKey, array $facts = [], array $bridge = []): array
{
    return [
        'chief_complaint' => $cc,
        'chief_complaints' => [[
            'id' => $familyKey,
            'family_key' => $familyKey,
            'text' => $cc,
        ]],
        'active_complaint_id' => $familyKey,
        'facts' => $facts,
        'semantic_bridge' => $bridge,
        'questions_asked' => [],
        'questions_answered' => [],
        'patient_turns' => [],
        'findings_asked' => [],
        'question_language' => 'english',
    ];
}

// --- Direct findingAlreadyKnown: stems + paraphrases ---
$vomitSkip = 'vomit|suka|nagsusuka|retch|emesis|throwing up|throw up';
$sweatSkip = 'sweat|singot|pinagpapawisan|diaphore';
$dizzSkip = 'dizz|faint|lipong|hilo|punaw|lightheaded|light-headed|woozy|vertigo';
$feverSkip = 'fever|lagnat|hilanat|febrile|pyrexia';

ok('stem vomiting known', $known->invoke(null, 'vomiting', [], 'abdominal pain and vomiting', $vomitSkip));
ok('paraphrase throwing up known', $known->invoke(null, 'vomiting', [], 'abdominal pain and throwing up', $vomitSkip));
ok('paraphrase retching known', $known->invoke(null, 'vomiting', [], 'abdominal pain with retching', $vomitSkip));
ok('exact nagsusuka known', $known->invoke(null, 'vomiting', [], 'sakit sa tiyan kag nagsusuka', $vomitSkip));

ok('stem sweating known', $known->invoke(null, 'sweating_with_chest', [], 'chest pain with a lot of sweating', $sweatSkip));
ok('paraphrase diaphoresis known', $known->invoke(null, 'sweating_with_chest', [], 'chest pain with diaphoresis', $sweatSkip));
ok('exact pinagpapawisan known', $known->invoke(null, 'sweating_with_chest', [], 'sakit sa dughan kag pinagpapawisan', $sweatSkip));

ok('stem dizzy known', $known->invoke(null, 'dizziness_with_chest', [], 'chest pain and dizzy', $dizzSkip));
ok('stem dizziness known', $known->invoke(null, 'dizziness_with_chest', [], 'chest pain and dizziness', $dizzSkip));
ok('paraphrase lightheaded known', $known->invoke(null, 'dizziness_with_chest', [], 'chest pain and lightheaded', $dizzSkip));
ok('paraphrase woozy known', $known->invoke(null, 'dizziness_with_chest', [], 'chest pain and feeling woozy', $dizzSkip));
ok('exact lipong known', $known->invoke(null, 'dizziness_with_chest', [], 'sakit sa dughan kag lipong', $dizzSkip));

ok('stem fever known', $known->invoke(null, 'fever_with_abdomen', [], 'abdominal pain with fever', $feverSkip));
ok('paraphrase febrile known', $known->invoke(null, 'fever_with_abdomen', [], 'abdominal pain and I feel febrile', $feverSkip));

// Shared dizziness fact alone must NOT close chest atom (preserved behavior).
ok(
    'shared dizziness fact alone does not close chest atom',
    !$known->invoke(null, 'dizziness_with_chest', ['dizziness' => true], '', $dizzSkip)
);

// --- listCandidateSlots: known complaint text closes atoms before Gemini ---
$cases = [
    [
        'label' => 'vomiting in complaint closes vomit atom',
        'cc' => 'abdominal pain and vomiting',
        'family' => 'abdominal_pain',
        'facts' => ['body_locations' => ['abdomen']],
        'closed' => 'ABDOMINAL_ASSOCIATED__VOMITING',
        'still_open' => 'ABDOMINAL_ASSOCIATED__FEVER_WITH_ABDOMEN',
    ],
    [
        'label' => 'throwing up closes vomit atom',
        'cc' => 'abdominal pain and throwing up',
        'family' => 'abdominal_pain',
        'facts' => ['body_locations' => ['abdomen']],
        'closed' => 'ABDOMINAL_ASSOCIATED__VOMITING',
        'still_open' => 'ABDOMINAL_ASSOCIATED__BLEEDING_WITH_ABDOMEN',
    ],
    [
        'label' => 'sweating in complaint closes sweat atom',
        'cc' => 'chest pain with a lot of sweating',
        'family' => 'chest_pain',
        'facts' => ['body_locations' => ['chest']],
        'closed' => 'CHEST_SWEATING__SWEATING_WITH_CHEST',
        'still_open' => 'CHEST_SWEATING__DIZZINESS_WITH_CHEST',
    ],
    [
        'label' => 'diaphoresis closes sweat atom',
        'cc' => 'chest pain with diaphoresis',
        'family' => 'chest_pain',
        'facts' => ['body_locations' => ['chest']],
        'closed' => 'CHEST_SWEATING__SWEATING_WITH_CHEST',
        'still_open' => 'CHEST_SWEATING__DIZZINESS_WITH_CHEST',
    ],
    [
        'label' => 'dizzy closes dizziness atom',
        'cc' => 'chest pain and dizzy',
        'family' => 'chest_pain',
        'facts' => ['body_locations' => ['chest']],
        'closed' => 'CHEST_SWEATING__DIZZINESS_WITH_CHEST',
        'still_open' => 'CHEST_SWEATING__SWEATING_WITH_CHEST',
    ],
    [
        'label' => 'lightheaded closes dizziness atom',
        'cc' => 'chest pain and lightheaded',
        'family' => 'chest_pain',
        'facts' => ['body_locations' => ['chest']],
        'closed' => 'CHEST_SWEATING__DIZZINESS_WITH_CHEST',
        'still_open' => 'CHEST_SWEATING__SWEATING_WITH_CHEST',
    ],
];

foreach ($cases as $case) {
    $ctx = familyCtx($case['cc'], $case['family'], $case['facts']);
    $ids = openIds($list->invoke(null, $ctx, $case['cc'], []));
    ok(
        $case['label'] . ' closed',
        !in_array($case['closed'], $ids, true),
        'open=' . implode(',', $ids)
    );
    ok(
        $case['label'] . ' sibling still open',
        in_array($case['still_open'], $ids, true),
        'open=' . implode(',', $ids)
    );
}

// Semantic bridge gloss "vomiting" must close vomit atom (stem match on bridge text in haystack).
$bridgeCtx = familyCtx(
    'sakit sa tiyan kag naga-uli',
    'abdominal_pain',
    ['body_locations' => ['abdomen']],
    [
        'ollama_meaning' => 'abdominal pain with vomiting',
        'gemini_concept' => 'vomiting',
        'python_symptoms' => ['vomiting'],
    ]
);
$hayB = $hayFn->invoke(null, $bridgeCtx, $bridgeCtx['chief_complaint'], $bridgeCtx['facts']);
$idsB = openIds($list->invoke(null, $bridgeCtx, $bridgeCtx['chief_complaint'], []));
ok('bridge haystack contains vomiting', str_contains($hayB, 'vomiting'));
ok(
    'bridge gloss vomiting closes vomit atom',
    !in_array('ABDOMINAL_ASSOCIATED__VOMITING', $idsB, true),
    'open=' . implode(',', $idsB)
);
ok(
    'bridge does not close unrelated bleed atom',
    in_array('ABDOMINAL_ASSOCIATED__BLEEDING_WITH_ABDOMEN', $idsB, true),
    'open=' . implode(',', $idsB)
);

// Structured finding_status still authoritative.
$statusCtx = familyCtx('chest pain', 'chest_pain', [
    'body_locations' => ['chest'],
    'finding_status' => [
        'sweating_with_chest' => 'positive',
        'dizziness_with_chest' => 'negative',
    ],
]);
$idsS = openIds($list->invoke(null, $statusCtx, 'chest pain', []));
ok('finding_status closes both CHEST atoms', !in_array('CHEST_SWEATING__SWEATING_WITH_CHEST', $idsS, true)
    && !in_array('CHEST_SWEATING__DIZZINESS_WITH_CHEST', $idsS, true), implode(',', $idsS));

echo "\n{$pass} PASS, {$fail} FAIL\n";
echo $fail === 0 ? "ALL_PASS\n" : "FAILURES\n";
exit($fail === 0 ? 0 : 1);
