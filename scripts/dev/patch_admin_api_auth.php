<?php
$files = glob(__DIR__ . '/../../app/api/admin/*.php');
foreach ($files as $f) {
    if (basename($f) === '_auth.php') {
        continue;
    }
    $c = file_get_contents($f);
    $orig = $c;
    $c = preg_replace(
        "/if \(empty\(\$_SESSION\['user_role'\]\) \|\| \$_SESSION\['user_role'\] !== 'admin'\) \{[^}]+\}/s",
        "require_once dirname(dirname(dirname(__DIR__))) . '/app/api/admin/_auth.php';",
        $c,
        1
    );
    if ($c !== $orig) {
        file_put_contents($f, $c);
        echo "Updated: " . basename($f) . "\n";
    }
}
