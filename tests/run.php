<?php
/** Run local regression suites in isolated processes; browser/live checks remain explicit. */
$failed = [];
$files = glob(__DIR__ . '/*.{php,cjs,py}', GLOB_BRACE);
sort($files);
foreach ($files as $file) {
    if ($file === __FILE__) continue;
    if (str_contains(file_get_contents($file), 'Run with wp eval-file')) {
        echo "SKIP (requires live WP-CLI): " . basename($file) . "\n";
        continue;
    }
    $runtime = ['php' => PHP_BINARY, 'cjs' => 'node', 'py' => 'python3'][pathinfo($file, PATHINFO_EXTENSION)];
    echo "\n" . basename($file) . "\n";
    passthru(escapeshellarg($runtime) . ' ' . escapeshellarg($file), $code);
    if ($code !== 0) $failed[] = basename($file);
}
if ($failed) {
    fwrite(STDERR, "\nFAILED: " . implode(', ', $failed) . "\n");
    exit(1);
}
echo "\nPASS: all local regression suites.\n";
