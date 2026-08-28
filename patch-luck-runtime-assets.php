<?php

declare(strict_types=1);

$targets = [
    '/www/public/theme/Luck/assets/lsrL0SOU-v2.js',
    '/www/storage/theme/Luck/assets/lsrL0SOU-v2.js',
];
$replacements = [
    '"./DM1yaN1X.js"' => '"./DM1yaN1X-v2.js"',
    '"./BBbuoBq5.js"' => '"./BBbuoBq5-v12.js"',
    '"./3u1s8V6K.js"' => '"./3u1s8V6K-v2.js"',
    '"./BEq_qS6Y.js"' => '"./BEq_qS6Y-v2.js"',
];

foreach ($targets as $target) {
    if (!is_file($target)) {
        continue;
    }
    $contents = file_get_contents($target);
    if ($contents === false) {
        throw new RuntimeException("Cannot read {$target}");
    }
    $patched = str_replace(array_keys($replacements), array_values($replacements), $contents);
    if ($patched !== $contents && file_put_contents($target, $patched) === false) {
        throw new RuntimeException("Cannot patch {$target}");
    }
    echo "[entrypoint] Luck order route imports verified: {$target}\n";
}
