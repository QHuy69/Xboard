<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Spatie\DbDumper\Databases\MySql;
use Spatie\DbDumper\Databases\Sqlite;

class EncryptedDatabaseBackupService
{
    private const MAGIC = "XBDTG001";
    private const SALT_BYTES = 16;
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;
    private const CHUNK_BYTES = 1024 * 1024;
    private const PBKDF2_ITERATIONS = 210000;

    /**
     * Create a compressed, authenticated and encrypted database dump.
     * The caller owns the returned file and must delete it after delivery.
     */
    public function create(string $password): string
    {
        $this->validatePassword($password);

        $directory = storage_path('app/telegram-backups');
        File::ensureDirectoryExists($directory, 0700, true);
        @chmod($directory, 0700);

        $base = $directory . DIRECTORY_SEPARATOR . 'xboard-' . now()->format('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $sqlPath = $base . '.sql';
        $gzipPath = $sqlPath . '.gz';
        $encryptedPath = $gzipPath . '.xbenc';

        try {
            $this->dumpDatabase($sqlPath);
            @chmod($sqlPath, 0600);
            $this->compress($sqlPath, $gzipPath);
            @chmod($gzipPath, 0600);
            $this->encryptFile($gzipPath, $encryptedPath, $password);

            return $encryptedPath;
        } catch (\Throwable $e) {
            File::delete($encryptedPath);
            throw $e;
        } finally {
            File::delete([$sqlPath, $gzipPath]);
        }
    }

    public function encryptFile(string $inputPath, string $outputPath, string $password): void
    {
        $this->validatePassword($password);
        $input = fopen($inputPath, 'rb');
        $output = fopen($outputPath, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            throw new RuntimeException('Cannot open database backup file for encryption.');
        }

        $salt = random_bytes(self::SALT_BYTES);
        $key = hash_pbkdf2('sha256', $password, $salt, self::PBKDF2_ITERATIONS, 32, true);
        $index = 0;

        try {
            $this->writeAll($output, self::MAGIC . $salt . pack('N', self::PBKDF2_ITERATIONS));
            while (!feof($input)) {
                $plain = fread($input, self::CHUNK_BYTES);
                if ($plain === false) throw new RuntimeException('Cannot read database backup during encryption.');
                if ($plain === '') continue;

                $nonce = random_bytes(self::NONCE_BYTES);
                $tag = '';
                $cipher = openssl_encrypt(
                    $plain,
                    'aes-256-gcm',
                    $key,
                    OPENSSL_RAW_DATA,
                    $nonce,
                    $tag,
                    self::MAGIC . pack('N', $index),
                    self::TAG_BYTES
                );
                if ($cipher === false || strlen($tag) !== self::TAG_BYTES) {
                    throw new RuntimeException('Database backup encryption failed.');
                }
                $this->writeAll($output, pack('N', strlen($cipher)) . $nonce . $tag . $cipher);
                $index++;
            }

            // An authenticated final record makes truncation detectable.
            $nonce = random_bytes(self::NONCE_BYTES);
            $tag = '';
            $final = openssl_encrypt('', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, self::MAGIC . 'final' . pack('N', $index), self::TAG_BYTES);
            if ($final === false || $final !== '' || strlen($tag) !== self::TAG_BYTES) {
                throw new RuntimeException('Cannot finalize database backup encryption.');
            }
            $this->writeAll($output, pack('N', 0) . $nonce . $tag);
        } catch (\Throwable $e) {
            fclose($input);
            fclose($output);
            File::delete($outputPath);
            throw $e;
        }

        fclose($input);
        fclose($output);
        @chmod($outputPath, 0600);
    }

    /** Used by the restore command and the encryption round-trip test. */
    public function decryptFile(string $inputPath, string $outputPath, string $password): void
    {
        $this->validatePassword($password);
        if (File::exists($outputPath)) throw new RuntimeException('Restore output already exists.');

        $input = fopen($inputPath, 'rb');
        $output = fopen($outputPath, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            throw new RuntimeException('Cannot open database backup for decryption.');
        }

        try {
            $magic = $this->readExact($input, strlen(self::MAGIC));
            if (!hash_equals(self::MAGIC, $magic)) throw new RuntimeException('Invalid encrypted backup format.');
            $salt = $this->readExact($input, self::SALT_BYTES);
            $iterations = unpack('Nvalue', $this->readExact($input, 4))['value'] ?? 0;
            if ($iterations < 100000 || $iterations > 1000000) throw new RuntimeException('Invalid backup key derivation settings.');
            $key = hash_pbkdf2('sha256', $password, $salt, $iterations, 32, true);

            $index = 0;
            while (true) {
                $length = unpack('Nvalue', $this->readExact($input, 4))['value'] ?? -1;
                if ($length < 0 || $length > self::CHUNK_BYTES) throw new RuntimeException('Invalid encrypted backup record.');
                $nonce = $this->readExact($input, self::NONCE_BYTES);
                $tag = $this->readExact($input, self::TAG_BYTES);

                if ($length === 0) {
                    $final = openssl_decrypt('', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, self::MAGIC . 'final' . pack('N', $index));
                    if ($final !== '' || fread($input, 1) !== '') throw new RuntimeException('Encrypted backup is truncated or has trailing data.');
                    break;
                }

                $cipher = $this->readExact($input, $length);
                $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, self::MAGIC . pack('N', $index));
                if ($plain === false) throw new RuntimeException('Encrypted backup authentication failed.');
                $this->writeAll($output, $plain);
                $index++;
            }
        } catch (\Throwable $e) {
            fclose($input);
            fclose($output);
            File::delete($outputPath);
            throw $e;
        }

        fclose($input);
        fclose($output);
        @chmod($outputPath, 0600);
    }

    private function dumpDatabase(string $path): void
    {
        $driver = (string) config('database.default');
        if ($driver === 'mysql') {
            $connection = (array) config('database.connections.mysql');
            MySql::create()
                ->setHost((string) ($connection['host'] ?? '127.0.0.1'))
                ->setPort((int) ($connection['port'] ?? 3306))
                ->setDbName((string) ($connection['database'] ?? ''))
                ->setUserName((string) ($connection['username'] ?? ''))
                ->setPassword((string) ($connection['password'] ?? ''))
                ->dumpToFile($path);
            return;
        }

        if ($driver === 'sqlite') {
            Sqlite::create()
                ->setDbName((string) config('database.connections.sqlite.database'))
                ->dumpToFile($path);
            return;
        }

        throw new RuntimeException("Telegram database backup does not support driver '{$driver}'.");
    }

    private function compress(string $inputPath, string $outputPath): void
    {
        $input = fopen($inputPath, 'rb');
        $output = gzopen($outputPath, 'wb9');
        if ($input === false || $output === false) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) gzclose($output);
            throw new RuntimeException('Cannot open database dump for compression.');
        }

        try {
            while (!feof($input)) {
                $chunk = fread($input, self::CHUNK_BYTES);
                if ($chunk === false) throw new RuntimeException('Cannot read database dump during compression.');
                if ($chunk !== '' && gzwrite($output, $chunk) === false) throw new RuntimeException('Cannot compress database dump.');
            }
        } finally {
            fclose($input);
            gzclose($output);
        }
    }

    private function validatePassword(string $password): void
    {
        if (strlen($password) < 16) throw new RuntimeException('Database backup encryption password must contain at least 16 characters.');
        if (!extension_loaded('openssl')) throw new RuntimeException('OpenSSL extension is required for encrypted database backups.');
    }

    /** @param resource $stream */
    private function readExact($stream, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($stream, $length - strlen($data));
            if ($chunk === false || $chunk === '') throw new RuntimeException('Encrypted backup is incomplete.');
            $data .= $chunk;
        }
        return $data;
    }

    /** @param resource $stream */
    private function writeAll($stream, string $data): void
    {
        $offset = 0;
        while ($offset < strlen($data)) {
            $written = fwrite($stream, substr($data, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('Cannot write database backup file.');
            $offset += $written;
        }
    }
}
