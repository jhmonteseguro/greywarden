<?php

/**
 * Prints line coverage per source directory from a PHPUnit Clover report.
 *
 *   php tests/tools/coverage-summary.php runtime/coverage/clover.xml
 *
 * Directories are grouped two levels deep for modules (modules/risk, ...) and
 * one level deep otherwise (models, helpers, ...).
 */

$file = $argv[1] ?? 'runtime/coverage/clover.xml';
if (!is_file($file)) {
    fwrite(STDERR, "No clover report at $file\n");
    exit(1);
}

$root = realpath(__DIR__ . '/../..') . '/';
$xml = simplexml_load_file($file);
$groups = [];
foreach ($xml->xpath('//file') as $f) {
    $path = substr((string) $f['name'], strlen($root));
    $parts = explode('/', $path);
    $group = $parts[0] === 'modules' ? $parts[0] . '/' . $parts[1] : $parts[0];
    $m = $f->metrics;
    $groups[$group][0] = ($groups[$group][0] ?? 0) + (int) $m['statements'];
    $groups[$group][1] = ($groups[$group][1] ?? 0) + (int) $m['coveredstatements'];
}
ksort($groups);

$total = [0, 0];
printf("%-22s %8s %8s %8s\n", 'Directory', 'Lines', 'Covered', '%');
foreach ($groups as $group => [$lines, $covered]) {
    printf("%-22s %8d %8d %7.1f%%\n", $group, $lines, $covered, $lines ? 100 * $covered / $lines : 100);
    $total[0] += $lines;
    $total[1] += $covered;
}
printf("%-22s %8d %8d %7.1f%%\n", 'TOTAL', $total[0], $total[1], $total[0] ? 100 * $total[1] / $total[0] : 100);
