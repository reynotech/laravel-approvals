#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation testing: breaks the code one small change at a time, on throwaway copies of the repo, and
 * runs the suite against each. A change no test notices (a survivor) is code the tests run without
 * really checking. Some survivors can't be caught, such as a change that behaves the same.
 *
 * Usage: bin/bench mutation [--workers=8] [--min=90] [--only=ApprovesChanges]
 * Writes build/bench/mutation.json.
 */

$root = dirname(__DIR__);
$options = getopt('', ['workers::', 'min::', 'only::']);
$workers = (int) ($options['workers'] ?? 8);
$minimum = (float) ($options['min'] ?? 90);
$only = $options['only'] ?? null;

$targets = [
    'src/Concerns/RequiresApprovalWhenChanges.php',
    'src/Concerns/ApprovesChanges.php',
    'src/Models/Modification.php',
    'src/Models/ModificationItem.php',
    'src/Scopes/ApprovalDraftScope.php',
];

$operators = [
    ['/===/', '!=='], ['/!==/', '==='],
    ['/&&/', '||'], ['/\|\|/', '&&'],
    ['/<= 0/', '< 0'], ['/>= /', '> '],
    ['/\btrue\b/', 'false'], ['/\bfalse\b/', 'true'],
    ['/\bif \(!/', 'if ('],
    ['/\(!\$/', '($'],
    ['/\(! \$/', '($'],
    ['/\$approving \?/', '!$approving ?'],
    ['/\?\? null/', '?? 1'],
    ["/'pending'/", "'x_pending'"], ["/'applied'/", "'x_applied'"], ["/'rejected'/", "'x_rejected'"], ["/'approved'/", "'x_approved'"],
];

// Lines that declare rather than do: comments, imports, properties.
$skip = '/^\s*(\*|\/\*|\/\/|use |namespace |declare|return \[|protected \$|public const|private bool|private \?|private mixed|protected int|protected bool|private \$|private static string)/';
// Whole statements worth deleting.
$deletable = '/^\s*(\$?[\w:>\-\(\)\$\'\[\]]+.*\);|\$[\w\->\[\]\'\$]+ = .*;|continue;|unset\(.*\);)\s*$/';

$jobs = [];

foreach ($targets as $file) {
    if ($only !== null && ! str_contains($file, $only)) {
        continue;
    }

    foreach (explode("\n", file_get_contents("$root/$file")) as $index => $line) {
        if (preg_match($skip, $line)) {
            continue;
        }

        $seen = [];

        foreach ($operators as [$pattern, $replacement]) {
            preg_match_all($pattern, $line, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [$text, $offset]) {
                $mutated = substr_replace($line, $replacement, $offset, strlen($text));

                if ($mutated !== $line && ! isset($seen[$mutated])) {
                    $seen[$mutated] = true;
                    $jobs[] = [$file, $index, $line, $mutated];
                }
            }
        }

        if (preg_match($deletable, $line)
            && ! str_contains($line, 'function')
            && ! str_ends_with(rtrim($line), '=> [')
            && substr_count($line, '(') === substr_count($line, ')')) {
            $jobs[] = [$file, $index, $line, ''];
        }
    }
}

$total = count($jobs);
echo "$total mutants on $workers workers. This takes a few minutes.\n";

$base = "$root/build/bench/mutation-workers";
$slots = [];

for ($i = 0; $i < $workers; $i++) {
    $dir = "$base/$i";
    exec('rm -rf ' . escapeshellarg($dir));
    mkdir($dir, 0777, true);

    foreach (['src', 'tests', 'config', 'database', 'vendor', 'composer.json', 'phpunit.xml.dist'] as $path) {
        exec('cp -R ' . escapeshellarg("$root/$path") . ' ' . escapeshellarg("$dir/"));
    }

    $slots[$i] = ['dir' => $dir, 'process' => null];
}

/** Applies a mutant to a slot and starts its suite. Returns false when the mutant isn't valid PHP. */
$start = function (array &$slot, array $job): bool {
    [$file, $index, , $mutated] = $job;

    $path = "{$slot['dir']}/$file";
    $slot['source'] = file_get_contents($path);
    $lines = explode("\n", $slot['source']);
    $lines[$index] = $mutated;
    file_put_contents($path, implode("\n", $lines));

    exec('php -l ' . escapeshellarg($path) . ' 2>/dev/null', $output, $status);

    if ($status !== 0) {
        file_put_contents($path, $slot['source']);

        return false;
    }

    $slot['process'] = proc_open(
        ['vendor/bin/phpunit', '--stop-on-failure', '--stop-on-error', '--no-progress'],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        $slot['dir']
    );
    $slot['job'] = $job;
    $slot['started'] = time();

    return true;
};

$queue = $jobs;
$results = [];
$done = 0;

while ($queue || array_filter($slots, fn ($slot) => $slot['process'] !== null)) {
    foreach ($slots as &$slot) {
        if ($slot['process'] !== null) {
            $status = proc_get_status($slot['process']);
            $timedOut = time() - $slot['started'] > 120;

            if ($status['running'] && ! $timedOut) {
                continue;
            }

            if ($timedOut && $status['running']) {
                proc_terminate($slot['process'], 9);
            }

            [$file, $index, $old, $new] = $slot['job'];
            $results[] = ['file' => $file, 'line' => $index + 1, 'old' => trim($old), 'new' => trim($new), 'killed' => $timedOut || $status['exitcode'] !== 0];
            proc_close($slot['process']);
            $slot['process'] = null;
            file_put_contents("{$slot['dir']}/$file", $slot['source']);

            if (++$done % 50 === 0) {
                echo "$done done\n";
            }
        }

        while ($queue && $slot['process'] === null) {
            $start($slot, array_shift($queue));
        }
    }
    unset($slot);

    usleep(20000);
}

exec('rm -rf ' . escapeshellarg($base));

$survivors = array_values(array_filter($results, fn ($result) => ! $result['killed']));
$count = count($results);
$score = $count ? round(($count - count($survivors)) / $count * 100, 1) : 100.0;

file_put_contents("$root/build/bench/mutation.json", json_encode(['total' => $count, 'score' => $score, 'survivors' => $survivors], JSON_PRETTY_PRINT));

foreach ($survivors as $survivor) {
    echo "\n{$survivor['file']}:{$survivor['line']}\n  - {$survivor['old']}\n  + {$survivor['new']}\n";
}

echo "\n$count mutants, " . ($count - count($survivors)) . ' caught, ' . count($survivors) . " survived: $score%. Details in build/bench/mutation.json\n";

if ($score < $minimum) {
    echo "Below the minimum of $minimum%.\n";
    exit(1);
}
