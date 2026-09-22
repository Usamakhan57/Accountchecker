<?php

declare(strict_types=1);

namespace AccountCheck\Support;

/**
 * Turns database rows into the shapes the API publishes.
 *
 * One place per resource, so the dashboard, the jobs list and the history page
 * cannot drift into returning three different versions of a job. It also draws
 * the line on what leaves the server: internal columns such as worker_id,
 * locked_at and provider metadata are not carried across.
 */
final class Presenter
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function job(array $row): array
    {
        $total = (int) ($row['total_items'] ?? 0);
        $processed = (int) ($row['processed_items'] ?? 0);

        return [
            'id' => (int) $row['id'],
            'uuid' => (string) ($row['uuid'] ?? ''),
            'checker_slug' => (string) ($row['checker_slug'] ?? ''),
            'checker_label' => (string) ($row['checker_label'] ?? ''),
            'status' => (string) ($row['status'] ?? 'PENDING'),
            'total_items' => $total,
            'processed_items' => $processed,
            'successful_items' => (int) ($row['successful_items'] ?? 0),
            'failed_items' => (int) ($row['failed_items'] ?? 0),
            'remaining_items' => max(0, $total - $processed),
            'progress_percent' => $total > 0 ? (int) floor(($processed / $total) * 100) : 0,
            'credits_reserved' => (int) ($row['credits_reserved'] ?? 0),
            'credits_spent' => (int) ($row['credits_spent'] ?? 0),
            'error_message' => $row['error_message'] ?? null,
            'source' => (string) ($row['source'] ?? 'PASTE'),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'started_at' => $row['started_at'] ?? null,
            'completed_at' => $row['completed_at'] ?? null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function jobs(array $rows): array
    {
        return array_map(self::job(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function result(array $row): array
    {
        $metadata = $row['metadata'] ?? null;

        return [
            'id' => (int) $row['id'],
            'job_id' => (int) ($row['job_id'] ?? 0),
            'input' => (string) ($row['raw_input'] ?? ''),
            'normalized_input' => (string) ($row['normalized_input'] ?? ''),
            'status' => (string) ($row['status'] ?? 'UNKNOWN'),
            'reason' => $row['reason'] ?? null,
            'source' => (string) ($row['source'] ?? ''),
            'checker_slug' => (string) ($row['checker_slug'] ?? ''),
            'checker_label' => (string) ($row['checker_label'] ?? ''),
            'response_time_ms' => isset($row['response_time_ms']) ? (int) $row['response_time_ms'] : null,
            'metadata' => is_string($metadata) ? json_decode($metadata, true) : $metadata,
            'checked_at' => (string) ($row['checked_at'] ?? ''),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function results(array $rows): array
    {
        return array_map(self::result(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function plan(array $row): array
    {
        $features = $row['features'] ?? null;
        $decoded = is_string($features) ? json_decode($features, true) : $features;

        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'description' => (string) ($row['description'] ?? ''),
            'price_cents' => (int) ($row['price_cents'] ?? 0),
            'currency' => (string) ($row['currency'] ?? 'USD'),
            'credits' => (int) ($row['credits'] ?? 0),
            'features' => is_array($decoded) ? array_values(array_map('strval', $decoded)) : [],
            'is_active' => (bool) ($row['is_active'] ?? true),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function walletTransaction(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'amount' => (int) $row['amount'],
            'type' => (string) $row['type'],
            'balance_after' => (int) $row['balance_after'],
            'description' => (string) ($row['description'] ?? ''),
            'reference' => $row['reference'] ?? null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function notification(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'title' => (string) $row['title'],
            'body' => (string) ($row['body'] ?? ''),
            'link' => $row['link'] ?? null,
            'read_at' => $row['read_at'] ?? null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function ticket(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'uuid' => (string) ($row['uuid'] ?? ''),
            'subject' => (string) $row['subject'],
            'status' => (string) $row['status'],
            'priority' => (string) ($row['priority'] ?? 'NORMAL'),
            'message_count' => (int) ($row['message_count'] ?? 0),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) ($row['updated_at'] ?? $row['created_at']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function ticketMessage(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'ticket_id' => (int) $row['ticket_id'],
            // Staff replies are attributed to the team, not to an individual
            // account, so a support agent's name is not exposed to users.
            'author_name' => ((bool) ($row['is_staff'] ?? false))
                ? 'AccountCheck support'
                : (string) ($row['author_name'] ?? 'You'),
            'is_staff' => (bool) ($row['is_staff'] ?? false),
            'body' => (string) $row['body'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function historyEntry(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'job_id' => isset($row['job_id']) ? (int) $row['job_id'] : null,
            'checker_slug' => (string) ($row['checker_slug'] ?? ''),
            'checker_label' => (string) ($row['checker_label'] ?? $row['checker_slug'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'total_items' => (int) ($row['total_items'] ?? 0),
            'successful_items' => (int) ($row['successful_items'] ?? 0),
            'failed_items' => (int) ($row['failed_items'] ?? 0),
            'credits_spent' => (int) ($row['credits_spent'] ?? 0),
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * Admin-facing user row. Includes wallet figures and status, never the
     * password hash.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function adminUser(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'uuid' => (string) $row['uuid'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'role' => (string) ($row['role_slug'] ?? 'USER'),
            'status' => (string) $row['status'],
            'email_verified' => !empty($row['email_verified_at']),
            'wallet_balance' => (int) ($row['wallet_balance'] ?? 0),
            'wallet_reserved' => (int) ($row['wallet_reserved'] ?? 0),
            'created_at' => (string) $row['created_at'],
            'last_login_at' => $row['last_login_at'] ?? null,
        ];
    }
}
