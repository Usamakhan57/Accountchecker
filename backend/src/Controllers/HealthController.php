<?php

declare(strict_types=1);

namespace AccountCheck\Controllers;

use AccountCheck\Core\Config;
use AccountCheck\Core\Database;
use AccountCheck\Core\Request;
use AccountCheck\Core\Response;
use Throwable;

/**
 * Liveness and readiness endpoints.
 *
 * These are unauthenticated, so they report status only: no version strings,
 * hostnames, driver messages or connection details.
 */
final class HealthController extends Controller
{
    public function __construct(
        private readonly Database $database,
        private readonly Config $config,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::success(['status' => 'ok'], 'Service is healthy');
    }

    public function database(Request $request): Response
    {
        try {
            $this->database->scalar('SELECT 1');
        } catch (Throwable) {
            return Response::error('Database is not reachable.', 'DATABASE_UNAVAILABLE', 503);
        }

        return Response::success(['status' => 'ok', 'component' => 'database'], 'Database is reachable');
    }

    /**
     * Reports whether a worker has claimed work recently and how deep the
     * queue is, so the admin panel can show a health indicator.
     */
    public function worker(Request $request): Response
    {
        try {
            $pending = (int) ($this->database->scalar(
                "SELECT COUNT(*) FROM checker_jobs WHERE status IN ('PENDING', 'QUEUED')",
            ) ?? 0);

            $processing = (int) ($this->database->scalar(
                "SELECT COUNT(*) FROM checker_jobs WHERE status = 'PROCESSING'",
            ) ?? 0);

            $lastHeartbeat = $this->database->scalar(
                'SELECT MAX(last_seen_at) FROM worker_heartbeats',
            );
        } catch (Throwable) {
            return Response::error('Worker status is not available.', 'WORKER_STATUS_UNAVAILABLE', 503);
        }

        $staleAfter = $this->config->int('checkers.queue.lock_ttl_seconds', 300);
        $secondsSinceHeartbeat = is_string($lastHeartbeat)
            ? max(0, time() - (int) strtotime($lastHeartbeat))
            : null;

        $healthy = $secondsSinceHeartbeat !== null && $secondsSinceHeartbeat <= $staleAfter;

        return Response::success([
            'status' => $healthy ? 'ok' : 'stale',
            'component' => 'worker',
            'pending_jobs' => $pending,
            'processing_jobs' => $processing,
            'seconds_since_heartbeat' => $secondsSinceHeartbeat,
        ], $healthy ? 'Worker is running' : 'No recent worker heartbeat');
    }
}
