<?php
/**
 * Focused regression for universal NLP audit fixes:
 * family promotion, injury/fracture, timing safety, body-location alias pollution.
 */
declare(strict_types=1);

require __DIR__ . '/nlp_cli_bootstrap.php';

$fail = 0;

function ok(bool $cond, string $label, string $detail = ''): void
{
    global $fail;
    if ($cond) {
        echo "OK\t{$label}" . ($detail !== '' ? "\t{$detail}" : '') . "\n";
        return;
    }
    $fail++;
    echo "FAIL\t{$label}" . ($detail !== '' ? "\t{$detail}" : '') . "\n";
}

/** @return array{families:list<string>,ids:list<string>,qid:string,domain:string,health:bool} */
function snap(string $text): array
{
    $r = ClinicalInterviewEngine::assess($text);
    $ctx = is_array($r['interview'] ?? null) ? $r['interview'] : [];
    $families = [];
    $ids = [];
    foreach ((array) ($ctx['complaints'] ?? []) as $c) {
        if (!is_array($c)) {
            continue;
        }
        foreach ((array) ($c['family_keys'] ?? []) as $fk) {
            $fk = strtolower(trim((string) $fk));
            if ($fk !== '' && $fk !== 'pain_no_location') {
                $families[] = $fk;
            }
        }
    }
    foreach ((array) ($ctx['chief_complaints'] ?? []) as $c) {
        if (!is_array($c)) {
            continue;
        }
        $id = strtoupper(trim((string) ($c['id'] ?? '')));
        if ($id !== '') {
            $ids[] = $id;
        }
        $fk = strtolower(trim((string) ($c['family_key'] ?? '')));
        if ($fk !== '' && $fk !== 'pain_no_location') {
            $families[] = $fk;
        }
    }
    $dom = HealthComplaintDomainDetector::detect($text);

    return [
        'families' => array_values(array_unique($families)),
        'ids' => array_values(array_unique($ids)),
        'qid' => strtoupper((string) ($r['followup_question']['question_id'] ?? '')),
        'domain' => (string) ($dom['domain'] ?? ''),
        'health' => !empty($dom['health_related']),
    ];
}

function hasAny(array $got, array $needles): bool
{
    $blob = strtolower(implode('|', $got));
    foreach ($needles as $n) {
        if ($n !== '' && str_contains($blob, strtolower($n))) {
            return true;
        }
    }
    return false;
}

function noAny(array $got, array $forbidden): bool
{
    return !hasAny($got, $forbidden);
}

// --- Injury / fracture (EN / Hil / Tag) ---
foreach ([
    'My arms is broken',
    'My arm is broken',
    'I fractured my arm',
    'Forearm snapped',
    'Nabali ang kamot ko',
    'Nabali ang braso ko',
] as $t) {
    $s = snap($t);
    ok(
        hasAny($s['ids'], ['MUSCULOSKELETAL']) || hasAny($s['families'], ['pain', 'musculoskeletal']),
        "injury family: {$t}",
        'families=' . implode(',', $s['families']) . ' ids=' . implode(',', $s['ids'])
    );
    ok(
        noAny($s['families'], ['ear', 'gastrointestinal', 'gi', 'cardiovascular', 'abdominal_pain'])
            && noAny($s['ids'], ['EAR', 'GASTROINTESTINAL', 'CARDIOVASCULAR', 'ABDOMINAL']),
        "injury no sibling pollution: {$t}",
        'families=' . implode(',', $s['families']) . ' ids=' . implode(',', $s['ids'])
    );
    ok($s['health'], "injury health_related: {$t}", 'domain=' . $s['domain']);
}

// Plural morphology for body locations
$locsArms = BodyLocationLexicon::extractDetailed('My arms is broken');
$canonsArms = array_map(
    static fn ($L) => strtolower((string) ($L['canonical_body_location'] ?? '')),
    $locsArms
);
ok(hasAny($canonsArms, ['arm']), 'arms→arm location', 'locs=' . implode(',', $canonsArms));

// --- Breathing ---
foreach (["I can't catch my breath", 'Budlay ginhawa', 'Hirap akong huminga'] as $t) {
    $s = snap($t);
    ok(
        hasAny($s['families'], ['breathing', 'respiratory']) || hasAny($s['ids'], ['DIFFICULTY_BREATHING']),
        "breathing: {$t}",
        'families=' . implode(',', $s['families']) . ' ids=' . implode(',', $s['ids'])
    );
    ok(
        noAny($s['families'], ['cardiovascular', 'ear', 'gastrointestinal'])
            && noAny($s['ids'], ['CARDIOVASCULAR', 'EAR']),
        "breathing no sibling pollution: {$t}",
        'families=' . implode(',', $s['families']) . ' ids=' . implode(',', $s['ids'])
    );
}

// --- Urinary ---
$s = snap('Burning when I empty my bladder');
ok(
    hasAny($s['families'], ['urinary']) || hasAny($s['ids'], ['URINARY']),
    'urinary burning bladder',
    'families=' . implode(',', $s['families']) . ' ids=' . implode(',', $s['ids'])
);

// --- Blood must NOT become kidney location ---
$locs = BodyLocationLexicon::extractDetailed('I have blood in my hand');
$canons = [];
foreach ($locs as $L) {
    if (is_array($L)) {
        $canons[] = strtolower((string) ($L['canonical_body_location'] ?? $L['anatomical_region'] ?? ''));
    }
}
ok(!hasAny($canons, ['kidney', 'renal']), 'blood not kidney', 'locs=' . implode(',', $canons));
ok(hasAny($canons, ['hand']), 'blood+hand keeps hand', 'locs=' . implode(',', $canons));

$s = snap('I have blood in my hand');
ok(
    hasAny($s['families'], ['bleeding']) || hasAny($s['ids'], ['BLEEDING']),
    'blood in hand → bleeding',
    'families=' . implode(',', $s['families'])
);
ok(noAny($s['families'], ['urinary']) && noAny($s['ids'], ['URINARY']), 'blood in hand no urinary', 'families=' . implode(',', $s['families']));

// --- Greeting must not become medical complaint ---
$s = snap('hello how are you');
ok(!$s['health'], 'hello not health_related', 'domain=' . $s['domain']);
ok($s['ids'] === [] || noAny($s['ids'], ['HEADACHE', 'FEVER', 'MUSCULOSKELETAL', 'BLEEDING']), 'hello no clinical id', 'ids=' . implode(',', $s['ids']));

// --- Headache / eye / fever ---
$s = snap('I have headache');
ok(hasAny($s['families'], ['headache', 'head']) || hasAny($s['ids'], ['HEADACHE']), 'EN headache', 'families=' . implode(',', $s['families']) . ' ids=' . implode(',', $s['ids']));
ok(noAny($s['families'], ['ear', 'gastrointestinal']) && noAny($s['ids'], ['EAR']), 'EN headache no ear/GI', 'families=' . implode(',', $s['families']));

$s = snap('sakit ulo');
ok(hasAny($s['families'], ['headache', 'head']) || hasAny($s['ids'], ['HEADACHE']), 'HIL sakit ulo', 'families=' . implode(',', $s['families']) . ' ids=' . implode(',', $s['ids']));

$s = snap('masakit ang mata');
ok(hasAny($s['families'], ['eye']) || hasAny($s['ids'], ['EYE']), 'TAG masakit mata', 'families=' . implode(',', $s['families']));
ok(noAny($s['families'], ['ear']) && noAny($s['ids'], ['EAR']), 'TAG mata no ear', 'families=' . implode(',', $s['families']));

$s = snap('lagnat');
ok(hasAny($s['families'], ['fever']) || hasAny($s['ids'], ['FEVER']), 'TAG lagnat', 'families=' . implode(',', $s['families']));

$s = snap('hindi ako sigurado');
ok(true, 'TAG unsure non-crash', 'domain=' . $s['domain'] . ' families=' . implode(',', $s['families']));

// --- Timing: extractors + fuzzy protect + validator ---
foreach (['earlier', 'earlier today', 'recently', 'a while ago', 'yesterday', 'last night', 'kanina', 'gahapon'] as $t) {
    $dur = ClinicalFeatureExtractors::extractDuration($t);
    $onset = ClinicalFeatureExtractors::extractOnset($t);
    $label = trim((string) ($dur['label'] ?? ''));
    ok($label !== '' || $onset !== '', "timing extract: {$t}", 'label=' . $label . ' onset=' . $onset);

    $fz = NlpStep3DemoAnswerFuzzy::prepare($t);
    $corr = mb_strtolower(trim((string) ($fz['corrected'] ?? '')));
    ok(!str_contains($corr, 'eight'), "fuzzy keeps timing: {$t}", 'corrected=' . $corr);

    $v = ClinicalFollowUpAnswerValidator::validate($t, 'SYMPTOM_DURATION', ['language' => 'en']);
    ok(!empty($v['accept']), "validator accepts timing: {$t}", 'reason=' . ($v['reason'] ?? '') . ' corr=' . ($v['corrected_answer'] ?? ''));
    $accepted = mb_strtolower((string) ($v['corrected_answer'] ?? ''));
    ok(!str_contains($accepted, 'eight'), "validator no eight: {$t}", 'accepted=' . $accepted);
}

$fz = NlpStep3DemoAnswerFuzzy::prepare('last night');
ok(
    mb_strtolower((string) ($fz['corrected'] ?? '')) === 'last night',
    'last night fuzzy exact',
    'corrected=' . ($fz['corrected'] ?? '')
);

// --- Multi-complaint isolation smoke ---
$s = snap('Masakit ang tiyan kag dughan ko');
ok(
    hasAny($s['families'], ['abdominal_pain']) && hasAny($s['families'], ['chest_pain']),
    'multi tiyan+dughan both',
    'families=' . implode(',', $s['families'])
);

if ($fail > 0) {
    echo "FAILED={$fail}\n";
    exit(1);
}
echo "ALL_PASS\n";
exit(0);
