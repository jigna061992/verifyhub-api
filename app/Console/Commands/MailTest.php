<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

// php artisan mail:test someone@example.com
// Sends one test mail with the current .env settings and shows the exact error if it fails. The password is never printed.
class MailTest extends Command
{
    protected $signature = 'mail:test {to : Email address that receives the test mail}';

    protected $description = 'Send a test email to check the SMTP settings';

    public function handle(): int
    {
        $to     = (string) $this->argument('to');
        $mailer = (string) config('mail.default');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('Please enter a valid email address.');

            return self::FAILURE;
        }

        $smtp = (array) config('mail.mailers.smtp', []);

        $this->table(['Setting', 'Value'], [
            ['Mailer', $mailer],
            ['Host', $mailer === 'smtp' ? ($smtp['host'] ?? '-') : '-'],
            ['Port', $mailer === 'smtp' ? ($smtp['port'] ?? '-') : '-'],
            ['Username', $mailer === 'smtp' ? ($smtp['username'] ?: '(empty)') : '-'],
            ['From', config('mail.from.address')],
        ]);

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn("MAIL_MAILER is '{$mailer}': no real email is sent. Set MAIL_MAILER=smtp and run php artisan config:clear.");
        }

        if ($mailer === 'smtp' && str_contains(strtoupper((string) ($smtp['host'] ?? '')), 'PASTE_')) {
            $this->error('MAIL_HOST still has the placeholder value. Put the real SMTP host in .env.');

            return self::FAILURE;
        }

        try {
            Mail::raw(
                config('app.name') . " test mail.\nIf you can read this, your SMTP settings work.",
                fn ($m) => $m->to($to)->subject(config('app.name') . ' - SMTP test')
            );
        } catch (Throwable $e) {
            $this->error('Mail failed: ' . $e->getMessage());
            $this->line('Common reasons: wrong username/password (Gmail needs an App Password), wrong port, or the From address is not verified at the provider.');

            return self::FAILURE;
        }

        $this->info("Test mail handed over to '{$mailer}' for {$to}. Check the inbox and the spam folder.");

        return self::SUCCESS;
    }
}
