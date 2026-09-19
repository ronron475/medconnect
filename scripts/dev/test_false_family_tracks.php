<?php
/**
 * Focused regression: wrong-family / false-track promotion.
 * Ensures evidence-based complaint tracks (no spurious chest/abdominal/fever bleed).
 */
declare(strict_types=1);

require __DIR__ . '/nlp_cli_bootstrap.php';

$fail = 0;

function ok(bool $cond, string $label, string $detail = ''): void
{
    global $fail;
    if ($cond) {
        echo "OK\t{$label}\n";
        return;
    }
    $fail++;
    echo "FAIL\t{$label}" . ($detail !== '' ? "\t{$detail}" : '') . "\n";
}

/**
 * @return array{families:list<string>,tracks:list<string>,qid:string}
 */
function snapshot(string $text): array
{
    $r = ClinicalInterviewEngine::assess($text);
    $ctx = is_array($r['interview'] ?? null) ? $r['interview'] : [];
    $families = [];
    foreach ((array) ($ctx['complaints'] ?? []) as $c) {
        if (!is_array($c)) {
            continue;
        }
        foreach ((array) ($c['family_keys'] ?? []) as $fk) {
            $fk = strtolower(trim((string) $fk));
            if ($fk !== '' && $fk !== 'pain' && $fk !== 'pain_no_location') {
                $families[] = $fk;
            }
        }
    }
    $families = array_values(array_unique($families));
    $tracks = [];
    foreach ((array) ($ctx['complaints'] ?? []) as $c) {
        if (!is_array($c)) {
            continue;
        }
        $span = trim((string) ($c['text_span'] ?? ''));
        $keys = array_map('strtolower', array_map('strval', (array) ($c['family_keys'] ?? [])));
        $tracks[] = ($span !== '' ? $span : '?') . '[' . implode(',', $keys) . ']';
    }
    $qid = strtoupper((string) ($r['followup_question']['question_id'] ?? ''));

    return ['families' => $families, 'tracks' => $tracks, 'qid' => $qid];
}

/** @param list<string> $got @param list<string> $forbidden */
function assertNo(array $got, array $forbidden, string $label): void
{
    $hit = array_values(array_intersect($got, $forbidden));
    ok($hit === [], $label, 'got=' . implode(',', $got) . ' forbidden=' . implode(',', $hit));
}

/** @param list<string> $got @param list<string> $required */
function assertHas(array $got, array $required, string $label): void
{
    $missing = [];
    foreach ($required as $need) {
        if (!in_array($need, $got, true)) {
            $missing[] = $need;
        }
    }
    ok($missing === [], $label, 'got=' . implode(',', $got) . ' missing=' . implode(',', $missing));
}

// --- English ---
$s = snapshot('I have stomach pain.');
assertHas($s['families'], ['abdominal_pain'], 'EN stomach has abdominal');
assertNo($s['families'], ['chest_pain', 'respiratory', 'breathing', 'cough'], 'EN stomach no chest/resp');
ok(
    $s['qid'] === '' || !preg_match('/BREATHING|CHEST|RESPIR/i', $s['qid']),
    'EN stomach next Q not breathing/chest',
    $s['qid']
);

$s = snapshot('I have a fever');
assertHas($s['families'], ['fever'], 'EN fever has fever');
assertNo($s['families'], ['abdominal_pain', 'chest_pain'], 'EN fever no abdominal/chest');

$s = snapshot('weakness in my left arm');
assertHas($s['families'], ['neuro'], 'EN arm weakness has neuro');
assertNo($s['families'], ['abdominal_pain', 'chest_pain', 'gastrointestinal'], 'EN arm weakness no abdominal/GI');

// --- Hiligaynon / Ilonggo ---
$s = snapshot('gasakit tiyan ko');
assertHas($s['families'], ['abdominal_pain'], 'HIL tiyan has abdominal');
assertNo($s['families'], ['chest_pain', 'respiratory'], 'HIL tiyan no chest/resp');

$s = snapshot('may hilanat ako');
assertHas($s['families'], ['fever'], 'HIL hilanat has fever');
assertNo($s['families'], ['abdominal_pain'], 'HIL hilanat no abdominal');

$s = snapshot('nangaluya ang wala nga kamot');
assertHas($s['families'], ['neuro'], 'HIL nangaluya kamot has neuro');
assertNo($s['families'], ['abdominal_pain', 'gastrointestinal'], 'HIL nangaluya no abdominal/GI');

// --- Tagalog ---
$s = snapshot('masakit ang tiyan ko');
assertHas($s['families'], ['abdominal_pain'], 'TAG tiyan has abdominal');
assertNo($s['families'], ['chest_pain', 'respiratory'], 'TAG tiyan no chest/resp');

$s = snapshot('may lagnat ako');
assertHas($s['families'], ['fever'], 'TAG lagnat has fever');
assertNo($s['families'], ['abdominal_pain'], 'TAG lagnat no abdominal');

$s = snapshot('nanghihina ang kaliwang braso');
assertHas($s['families'], ['neuro'], 'TAG braso weakness has neuro');
assertNo($s['families'], ['abdominal_pain', 'gastrointestinal'], 'TAG braso no abdominal/GI');

// --- True multi-complaint ---
$s = snapshot('Masakit ang tiyan kag dughan ko');
assertHas($s['families'], ['abdominal_pain', 'chest_pain'], 'MULTI tiyan+dughan both tracks');
ok(count($s['tracks']) >= 2 || count($s['families']) >= 2, 'MULTI has multiple families/tracks', implode(' | ', $s['tracks']));

if ($fail > 0) {
    echo "FAILED={$fail}\n";
    exit(1);
}
echo "ALL_PASS\n";
exit(0);
