<?php

declare(strict_types=1);

/**
 * AccountCheck queue worker.
 *
 *   php backend/worker.php                     run until stopped
 *   php backend/worker.php --once              process one pass and exit
 *   php backend/worker.php --max-jobs=10       exit after ten jobs
 *   php backend/worker.php --sleep=5           seconds to wait when idle
 *   php backend/worker.php --max-runtime=1800  exit after 30 minutes
 *   php backend/worker.php --worker-id=web-1   fixed id instead of a generated one
 *   php backend/worker.php --quiet             log only, no stdout
 *
 * Batch checking never runs inside a web request. The API writes job rows and
 * this process does the work, which is what allows a 5,000-record job to exist
 * without any request running for minutes.
 *
 * Run several of these if you need more throughput: work is claimed with
 * atomic UPDATEs, so two workers cannot take the same job or the same record.
 *
 * Under systemd use Restart=always with a --max-runtime; the process exits
 * cleanly on SIGTERM after finishing its current chunk. See docs/DEPLOYMENT.md.
 */

use AccountCheck\Core\Container;
use AccountCheck\Queue\WorkerRuntime;

if (PHP_SAPI !== 'cli') {
    // Nothing under backend/ is web-reachable except public/index.php, but a
    // misconfigured document root should still not be able to start a worker.
    http_response_code(404);
    exit(1);
}

/** @var Container $container */
$container = require __DIR__ . '/bootstrap/app.php';

/**
 * @param list<string> $argv
 * @return array<string, mixed>
 */
$parseOptions = static function (array $argv): array {
    $options = [];

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--once') {
            $options['once'] = true;
            continue;
        }

        if ($argument === '--quiet') {
            $options['quiet'] = true;
            continue;
        }

        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }

        if (preg_match('/^--([a-z-]+)=(.*)$/', $argument, $matches) !== 1) {
            fwrite(STDERR, 'Unrecognised option: ' . $argument . PHP_EOL);
            exit(2);
        }

        [, $name, $value] = $matches;
        $key = str_replace('-', '_', $name);

        $options[$key] = in_array($key, ['max_jobs', 'sleep', 'max_runtime'], true)
            ? (int) $value
            : $value;
    }

    return $options;
};

$options = $parseOptions($argv);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<TEXT
    AccountCheck queue worker

      --once               process one pass and exit
      --max-jobs=N         exit after N jobs
      --sleep=N            seconds to wait when the queue is empty
      --max-runtime=N      exit after N seconds (0 disables)
      --worker-id=NAME     fixed worker id
      --quiet              log only, no stdout

    TEXT);
    exit(0);
}

exit($container->get(WorkerRuntime::class)->run($options));
