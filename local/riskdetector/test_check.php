<?php
// Simple server-side test script for local_riskdetector.
// Run from plugin folder: php test_check.php

$pluginroot = __DIR__;

$tests = [
    'version.php exists' => file_exists($pluginroot . '/version.php'),
    'lib.php exists' => file_exists($pluginroot . '/lib.php'),
    'settings.php exists' => file_exists($pluginroot . '/settings.php'),
    'db/access.php exists' => file_exists($pluginroot . '/db/access.php'),
    'db/install.xml exists' => file_exists($pluginroot . '/db/install.xml'),
    'db/messages.php exists' => file_exists($pluginroot . '/db/messages.php'),
    'db/upgrade.php exists' => file_exists($pluginroot . '/db/upgrade.php'),
    'admin.php exists' => file_exists($pluginroot . '/admin.php'),
    'dashboard.php exists' => file_exists($pluginroot . '/dashboard.php'),
    'configure.php exists' => file_exists($pluginroot . '/configure.php'),
    'notify.php exists' => file_exists($pluginroot . '/notify.php'),
    'export.php exists' => file_exists($pluginroot . '/export.php'),
];

echo "local_riskdetector Code-Based Functional Test Results\n";
echo "====================================================\n\n";

$passed = 0;
$total = count($tests);

foreach ($tests as $testname => $result) {
    if ($result) {
        echo "[PASS] " . $testname . PHP_EOL;
        $passed++;
    } else {
        echo "[FAIL] " . $testname . PHP_EOL;
    }
}

echo "\n====================================================\n";
echo "Passed: {$passed}/{$total}\n";

if ($passed === $total) {
    echo "Overall Result: PASS\n";
    exit(0);
}

echo "Overall Result: FAIL\n";
exit(1);