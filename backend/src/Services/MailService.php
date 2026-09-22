<?php

declare(strict_types=1);

namespace AccountCheck\Services;

use AccountCheck\Core\Config;
use AccountCheck\Support\Logger;
use AccountCheck\Support\Str;

/**
 * Transactional email.
 *
 * Two drivers: `log` writes the message to the mail log (the default, and what
 * development and tests use) and `smtp` sends over SMTP. Credentials come from
 * the environment and never appear in a message, a log line or a response.
 *
 * Sending never throws into a request: a failed welcome email must not fail a
 * registration. Failures are logged and reported by the return value.
 */
final class MailService
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function sendWelcome(string $email, string $name): bool
    {
        return $this->send($email, 'Welcome to AccountCheck', $this->layout(
            'Welcome to AccountCheck',
            [
                'Hello ' . $name . ',',
                'Your AccountCheck account is ready. You can start a check from the dashboard, and the duplicate finder and name generator are available straight away.',
                'Every check runs through authorized verification sources only. Where no authorized source exists for a platform, a result is reported as UNAVAILABLE rather than guessed.',
            ],
        ));
    }

    public function sendEmailVerification(string $email, string $name, string $verifyUrl): bool
    {
        return $this->send($email, 'Verify your AccountCheck email address', $this->layout(
            'Verify your email address',
            [
                'Hello ' . $name . ',',
                'Confirm this address to finish setting up your AccountCheck account:',
                $verifyUrl,
                'The link expires in 24 hours. If you did not create an account, you can ignore this message.',
            ],
        ));
    }

    public function sendPasswordReset(string $email, string $name, string $resetUrl): bool
    {
        return $this->send($email, 'Reset your AccountCheck password', $this->layout(
            'Reset your password',
            [
                'Hello ' . $name . ',',
                'Use this link to choose a new password:',
                $resetUrl,
                'The link expires in 60 minutes and can be used once. If you did not ask for a reset, no action is needed — your current password still works.',
            ],
        ));
    }

    public function sendJobCompleted(string $email, string $name, string $checkerLabel, int $total, int $successful, string $jobUrl): bool
    {
        return $this->send($email, 'Your ' . $checkerLabel . ' job has finished', $this->layout(
            'Job finished',
            [
                'Hello ' . $name . ',',
                sprintf('Your %s job processed %s records, %s of which returned a result.', $checkerLabel, number_format($total), number_format($successful)),
                'View the results: ' . $jobUrl,
            ],
        ));
    }

    public function sendJobFailed(string $email, string $name, string $checkerLabel, string $reason, string $jobUrl): bool
    {
        return $this->send($email, 'Your ' . $checkerLabel . ' job did not finish', $this->layout(
            'Job did not finish',
            [
                'Hello ' . $name . ',',
                'Your ' . $checkerLabel . ' job stopped before completing.',
                'Reason: ' . $reason,
                'Credits for records that were not processed have been returned to your wallet.',
                'View the job: ' . $jobUrl,
            ],
        ));
    }

    public function sendSupportReply(string $email, string $name, string $subject, string $ticketUrl): bool
    {
        return $this->send($email, 'Re: ' . $subject, $this->layout(
            'New reply on your ticket',
            [
                'Hello ' . $name . ',',
                'There is a new reply on your support ticket "' . $subject . '".',
                'Read and reply: ' . $ticketUrl,
            ],
        ));
    }

    public function sendWalletUpdate(string $email, string $name, int $amount, int $balance, string $description): bool
    {
        $direction = $amount >= 0 ? 'added to' : 'deducted from';

        return $this->send($email, 'Your AccountCheck wallet was updated', $this->layout(
            'Wallet updated',
            [
                'Hello ' . $name . ',',
                sprintf('%s credits were %s your wallet. %s', number_format(abs($amount)), $direction, $description),
                sprintf('Your balance is now %s credits.', number_format($balance)),
            ],
        ));
    }

    /**
     * @return bool False when the message could not be handed to the transport.
     */
    public function send(string $to, string $subject, string $body): bool
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->logger->channel('mail')->warning('Refused to send to an invalid address');

            return false;
        }

        // Header injection guard: a newline in the subject would let a crafted
        // value append its own headers.
        $subject = trim(str_replace(["\r", "\n"], ' ', $subject));

        $driver = $this->config->string('mail.driver', 'log');

        try {
            return match ($driver) {
                'smtp' => $this->sendSmtp($to, $subject, $body),
                default => $this->sendToLog($to, $subject, $body),
            };
        } catch (\Throwable $e) {
            // A mail failure must never break the request that triggered it.
            $this->logger->channel('mail')->error('Mail delivery failed', [
                'recipient' => Str::maskEmail($to),
                'subject' => $subject,
                'driver' => $driver,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function sendToLog(string $to, string $subject, string $body): bool
    {
        $this->logger->channel('mail')->info('Mail queued (log driver)', [
            'recipient' => Str::maskEmail($to),
            'subject' => $subject,
            'body' => $body,
        ]);

        return true;
    }

    /**
     * Hands the message to PHP's mail() with SMTP configured at the system
     * level, which is what a PHP-FPM host with a local relay expects.
     *
     * A deployment using an external provider should replace this method with
     * that provider's client; everything else in the application talks to
     * MailService, so nothing else changes.
     */
    private function sendSmtp(string $to, string $subject, string $body): bool
    {
        $fromAddress = $this->config->string('mail.from.address', 'no-reply@example.com');
        $fromName = str_replace(["\r", "\n"], '', $this->config->string('mail.from.name', 'AccountCheck'));

        $headers = [
            'From' => sprintf('%s <%s>', $fromName, $fromAddress),
            'Reply-To' => $fromAddress,
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=utf-8',
            'X-Mailer' => 'AccountCheck',
        ];

        $sent = mail($to, $subject, $body, $headers);

        if (!$sent) {
            $this->logger->channel('mail')->error('SMTP transport rejected the message', [
                'recipient' => Str::maskEmail($to),
                'subject' => $subject,
            ]);
        }

        return $sent;
    }

    /** @param list<string> $paragraphs */
    private function layout(string $heading, array $paragraphs): string
    {
        $lines = [$heading, str_repeat('=', mb_strlen($heading)), ''];

        foreach ($paragraphs as $paragraph) {
            $lines[] = $paragraph;
            $lines[] = '';
        }

        $lines[] = '--';
        $lines[] = 'AccountCheck';
        $lines[] = $this->config->string('app.url', '');

        return implode(PHP_EOL, $lines);
    }
}
