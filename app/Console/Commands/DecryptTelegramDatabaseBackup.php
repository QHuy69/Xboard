<?php

namespace App\Console\Commands;

use App\Services\EncryptedDatabaseBackupService;
use Illuminate\Console\Command;

class DecryptTelegramDatabaseBackup extends Command
{
    protected $signature = 'backup:telegram-decrypt
        {input : Encrypted .xbenc backup file}
        {output : Destination .sql.gz path}
        {--password-env=TELEGRAM_DATABASE_BACKUP_PASSWORD : Environment variable containing the encryption password}';

    protected $description = 'Decrypt an encrypted Telegram database backup without putting its password in the command line';

    public function handle(EncryptedDatabaseBackupService $backupService): int
    {
        $environmentName = trim((string) $this->option('password-env'));
        $password = $environmentName !== '' ? (string) getenv($environmentName) : '';
        if (strlen($password) < 16) {
            $this->error("Set {$environmentName} to the backup password (at least 16 characters) before restoring.");
            return self::FAILURE;
        }

        try {
            $backupService->decryptFile(
                (string) $this->argument('input'),
                (string) $this->argument('output'),
                $password
            );
            $this->info('Backup decrypted successfully. The output remains gzip-compressed.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Backup decryption failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
