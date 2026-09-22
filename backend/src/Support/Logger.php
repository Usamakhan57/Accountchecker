<?php

declare(strict_types=1);

namespace AccountCheck\Support;

/**
 * Line-delimited JSON logger writing to storage/logs/{channel}-{date}.log.
 *
 * Secrets are redacted by key name before anything is written; nothing in this
 * class is ever echoed to the HTTP response.
 */
final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    private const REDACT_KEYS = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'token', 'access_token', 'refresh_token', 'api_key', 'apikey', 'secret',
        'authorization', 'cookie', 'session', 'session_id', 'csrf', 'csrf_token',
        'db_password', 'mail_password', 'private_key',
    ];

    public function __construct(
        private readonly string $directory,
        private readonly string $minimumLevel = 'debug',
        private readonly string $channel = 'app',
    ) {
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function channel(string $channel): self
    {
        return new self($this->directory, $this->minimumLevel, $channel);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);
        $threshold = self::LEVELS[strtolower($this->minimumLevel)] ?? 0;

        if ((self::LEVELS[$level] ?? 0) < $threshold) {
            return;
        }

        $entry = [
            'timestamp' => gmdate('c'),
            'level' => $level,
            'channel' => $this->channel,
            'message' => $message,
            'context' => self::redact($context),
        ];

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            return;
        }

        $this->write($line);
    }

    /**
     * @param array<array-key, mixed> $context
     * @return array<array-key, mixed>
     */
    public static function redact(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            $isSensitive = is_string($key)
                && in_array(strtolower($key), self::REDACT_KEYS, true);

            if ($isSensitive) {
                $clean[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::redact($value);
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
                continue;
            }

            $clean[$key] = is_object($value) ? $value::class : gettype($value);
        }

        return $clean;
    }

    private function write(string $line): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            return;
        }

        $file = sprintf('%s/%s-%s.log', rtrim($this->directory, '/'), $this->channel, gmdate('Y-m-d'));

        // Appends are atomic for short lines, which keeps concurrent workers
        // from interleaving partial records.
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
