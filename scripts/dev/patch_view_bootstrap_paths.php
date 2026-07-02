<?php
/**
 * One-time patch: fix view bootstrap requires after resources/views move.
 */
declare(strict_types=1);

$viewsRoot = dirname(__DIR__, 2) . '/resources/views';
$walker = <<<'PHP'
if (!defined('BASE_PATH')) {
    $d = __DIR__;
    while ($d !== dirname($d)) {
        if (is_file($d . '/mc_load.php')) {
            require_once $d . '/mc_load.php';
            break;
        }
        $d = dirname($d);
    }
}
PHP;

$patterns = [
    "/require_once __DIR__ \. '\/\\.\\.\\/\\.\\.\\/bootstrap\\.php';\\s*\\n/" => $walker . "\n",
    "/require_once dirname\\(__DIR__, 3\\) \. '\\/bootstrap\\.php';\\s*\\n/" => $walker . "\n",
    "/require_once __DIR__ \. '\/\\.\\.\\/\\.\\.\\/config\\/db\\.php';\\s*\\n/" => '',
    "/require_once dirname\\(__DIR__, 3\\) \. '\\/config\\/db\\.php';\\s*\\n/" => '',
    "/require_once dirname\\(__DIR__, 4\\) \. '\\/config\\/db\\.php';\\s*\\n/" => '',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($viewsRoot, FilesystemIterator::SKIP_DOTS)
);

$changed = 0;
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    if (in_array($file->getFilename(), ['mc_load.php', '_mc_init.php'], true)) {
        continue;
    }

    $path = $file->getPathname();
    $content = file_get_contents($path);
    $original = $content;

    foreach ($patterns as $pattern => $replacement) {
        $content = preg_replace($pattern, $replacement, $content);
    }

    // Fix app/includes paths that still use ../../app from old depth
    $content = preg_replace(
        "/require_once __DIR__ \. '\/\\.\\.\\/\\.\\.\\/app\//",
        "require_once BASE_PATH . '/app/",
        $content
    );

    $content = preg_replace(
        "/require_once dirname\\(__DIR__, 3\\) \. '\\/app\//",
        "require_once BASE_PATH . '/app/",
        $content
    );

    $content = preg_replace(
        "/dirname\\(__DIR__, 3\\) \. '\\/storage'/",
        "STORAGE_PATH",
        $content
    );

    $content = preg_replace(
        "/dirname\\(__DIR__, 3\\) \. '\\/app\\/includes\\/nav\//",
        "BASE_PATH . '/app/includes/nav/",
        $content
    );

    if ($content !== $original) {
        file_put_contents($path, $content);
        $changed++;
    }
}

echo "Patched {$changed} view files.\n";
