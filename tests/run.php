<?php
/**
 * Runs every tests/test_*.php spec's $TESTS list and reports pass/fail.
 *
 *   php tests/run.php
 *
 * No server needed — pure-logic checks only (no DB/network round-trip). Exits non-zero if any
 * test fails or errors, for use as a CI gate. Deliberately dependency-free (no PHPUnit/Composer)
 * so it runs identically in CI and on the plain PHP hosting this project deploys to.
 */

declare(strict_types=1);

$testsDir = __DIR__;
$specs = glob($testsDir . '/test_*.php');
sort($specs);

if (!$specs) {
    fwrite(STDERR, "No unit tests discovered.\n");
    exit(1);
}

$failures = [];
$total = 0;

foreach ($specs as $specFile) {
    $TESTS = [];
    require $specFile;

    foreach ($TESTS as $name => $test) {
        $total++;
        $label = basename($specFile) . '::' . (is_string($name) ? $name : $test[1] ?? 'test');
        try {
            $test();
            echo "PASS $label\n";
        } catch (\Throwable $e) {
            echo "FAIL $label — {$e->getMessage()}\n";
            $failures[] = $label;
        }
    }
}

$passed = $total - count($failures);
echo "\n{$passed}/{$total} passed.\n";

if ($failures) {
    echo 'Failed: ' . implode(', ', $failures) . "\n";
    exit(1);
}
exit(0);
