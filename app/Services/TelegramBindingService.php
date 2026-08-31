<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TelegramBindingService
{
    public const TOKEN_TTL_SECONDS = 600;

    private const PAYLOAD_PREFIX = 'bind_';
    private const LOCK_TTL_SECONDS = 30;
    private const LOCK_WAIT_SECONDS = 5;
    private const MAX_SIGNED_BIGINT = '9223372036854775807';

    /**
     * Issue one opaque, single-use Telegram deep-link payload.
     *
     * Repeated dashboard reads reuse the same still-valid payload so a focus,
     * pageshow or second tab cannot revoke the link that the customer is in
     * the process of opening. The bearer value is encrypted in cache while
     * the lookup/consume key remains its SHA-256 digest.
     *
     * @return array{payload: string, expires_in: int}
     */
    public function issue(User $user): array
    {
        $lock = Cache::lock($this->userLockKey((int) $user->id), self::LOCK_TTL_SECONDS);

        return $lock->block(self::LOCK_WAIT_SECONDS, function () use ($user): array {
            $userId = (int) $user->id;
            $pointerKey = $this->userPointerKey($userId);

            $reusable = $this->reusableToken($userId, Cache::get($pointerKey));
            if ($reusable !== null) {
                return $reusable;
            }

            $this->forgetOutstandingToken($userId);

            // 192 random bits remain comfortably below Telegram's 64-byte
            // start-parameter limit after base64url encoding and our prefix.
            $opaque = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
            $payload = self::PAYLOAD_PREFIX . $opaque;
            $digest = hash('sha256', $payload);
            $expiresAt = time() + self::TOKEN_TTL_SECONDS;

            Cache::put($this->tokenKey($digest), [
                'user_id' => $userId,
                'expires_at' => $expiresAt,
            ], self::TOKEN_TTL_SECONDS);
            Cache::put($pointerKey, [
                'digest' => $digest,
                'encrypted_payload' => Crypt::encryptString($payload),
                'expires_at' => $expiresAt,
            ], self::TOKEN_TTL_SECONDS);

            return ['payload' => $payload, 'expires_in' => self::TOKEN_TTL_SECONDS];
        });
    }

    /**
     * Revoke any unconsumed dashboard deep link for an XBoard user.
     *
     * This is idempotent and shares the issue/consume target lock, so a token
     * that has just been pulled cannot bind after an unbind or explicit revoke.
     */
    public function revoke(User|int $user): void
    {
        $userId = $user instanceof User ? (int) $user->id : $user;
        if ($userId <= 0) {
            return;
        }

        $lock = Cache::lock($this->userLockKey($userId), self::LOCK_TTL_SECONDS);
        $lock->block(self::LOCK_WAIT_SECONDS, function () use ($userId): void {
            $this->forgetOutstandingToken($userId);
        });
    }

    /**
     * Consume a payload exactly once and bind it to the Telegram actor.
     *
     * The token is consumed even when the requested binding conflicts. This
     * avoids turning a captured payload into an account-enumeration primitive.
     */
    public function consume(string $payload, string $telegramActorId): ?User
    {
        if (!$this->validActorId($telegramActorId) || !$this->validPayload($payload)) {
            return null;
        }

        $digest = hash('sha256', $payload);
        $lock = Cache::lock($this->consumeLockKey($digest), self::LOCK_TTL_SECONDS);

        return $lock->block(self::LOCK_WAIT_SECONDS, function () use ($digest, $telegramActorId): ?User {
            $record = Cache::get($this->tokenKey($digest));
            if (!is_array($record) || (int) ($record['user_id'] ?? 0) <= 0) {
                return null;
            }

            $userId = (int) $record['user_id'];

            // A token lock alone is insufficient: two different users can
            // present two different valid tokens for the same Telegram actor.
            // Serialize both sides of the relationship before checking the
            // database so that a missing actor row cannot be observed twice.
            $targetLock = Cache::lock($this->userLockKey($userId), self::LOCK_TTL_SECONDS);

            return $targetLock->block(self::LOCK_WAIT_SECONDS, function () use ($digest, $userId, $telegramActorId): ?User {
                $pointerKey = $this->userPointerKey($userId);
                $currentDigest = $this->pointerDigest(Cache::get($pointerKey));
                if ($currentDigest === null || !hash_equals($currentDigest, $digest)) {
                    return null;
                }

                // Claim the bearer only after taking the same per-user lock as
                // issue/revoke. Dashboard refreshes therefore cannot revoke a
                // token between lookup and consumption.
                $claimed = Cache::pull($this->tokenKey($digest));
                if (!is_array($claimed) || (int) ($claimed['user_id'] ?? 0) !== $userId) {
                    Cache::forget($pointerKey);
                    return null;
                }

                Cache::forget($pointerKey);

                $actorLock = Cache::lock($this->actorLockKey($telegramActorId), self::LOCK_TTL_SECONDS);

                return $actorLock->block(self::LOCK_WAIT_SECONDS, function () use ($userId, $telegramActorId): ?User {
                    return DB::transaction(function () use ($userId, $telegramActorId): ?User {
                        $target = User::query()->whereKey($userId)->lockForUpdate()->first();
                        if (!$target) {
                            return null;
                        }

                        $actorOwner = User::query()
                            ->where('telegram_id', $telegramActorId)
                            ->lockForUpdate()
                            ->first();

                        if ($actorOwner && (int) $actorOwner->id !== (int) $target->id) {
                            Log::notice('Telegram account binding rejected', [
                                'reason' => 'telegram_actor_already_bound',
                                'target_user_id' => (int) $target->id,
                            ]);
                            return null;
                        }

                        if ($target->telegram_id !== null
                            && (string) $target->telegram_id !== $telegramActorId) {
                            Log::notice('Telegram account binding rejected', [
                                'reason' => 'xboard_user_already_bound',
                                'target_user_id' => (int) $target->id,
                            ]);
                            return null;
                        }

                        // Keep Telegram IDs as decimal strings at application
                        // boundaries; JavaScript cannot safely represent every
                        // Telegram 64-bit identifier as a number.
                        $target->telegram_id = $telegramActorId;
                        $target->saveOrFail();

                        Log::notice('Telegram account binding completed', [
                            'target_user_id' => (int) $target->id,
                        ]);

                        return $target;
                    });
                });
            });
        });
    }

    private function validPayload(string $payload): bool
    {
        return preg_match('/^' . self::PAYLOAD_PREFIX . '[A-Za-z0-9_-]{32}$/', $payload) === 1;
    }

    private function validActorId(string $telegramActorId): bool
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/', $telegramActorId) !== 1) {
            return false;
        }

        return strlen($telegramActorId) < strlen(self::MAX_SIGNED_BIGINT)
            || strcmp($telegramActorId, self::MAX_SIGNED_BIGINT) <= 0;
    }

    private function tokenKey(string $digest): string
    {
        return 'telegram:binding:token:' . $digest;
    }

    private function userPointerKey(int $userId): string
    {
        return 'telegram:binding:user-token:' . $userId;
    }

    private function userLockKey(int $userId): string
    {
        return 'telegram:binding:user-lock:' . $userId;
    }

    private function consumeLockKey(string $digest): string
    {
        return 'telegram:binding:consume:' . $digest;
    }

    private function actorLockKey(string $telegramActorId): string
    {
        return 'telegram:binding:actor-lock:' . hash('sha256', $telegramActorId);
    }

    private function forgetOutstandingToken(int $userId): void
    {
        $digest = $this->pointerDigest(Cache::pull($this->userPointerKey($userId)));
        if ($digest !== null) {
            Cache::forget($this->tokenKey($digest));
        }
    }

    /** @return array{payload: string, expires_in: int}|null */
    private function reusableToken(int $userId, mixed $pointer): ?array
    {
        if (!is_array($pointer)) {
            return null;
        }

        $digest = $this->pointerDigest($pointer);
        $encryptedPayload = $pointer['encrypted_payload'] ?? null;
        $expiresAt = filter_var($pointer['expires_at'] ?? null, FILTER_VALIDATE_INT);
        if ($digest === null
            || !is_string($encryptedPayload)
            || $encryptedPayload === ''
            || $expiresAt === false
            || $expiresAt <= time()) {
            return null;
        }

        $record = Cache::get($this->tokenKey($digest));
        if (!is_array($record)
            || (int) ($record['user_id'] ?? 0) !== $userId
            || (int) ($record['expires_at'] ?? 0) <= time()) {
            return null;
        }

        try {
            $payload = Crypt::decryptString($encryptedPayload);
        } catch (\Throwable) {
            return null;
        }

        if (!$this->validPayload($payload)
            || !hash_equals($digest, hash('sha256', $payload))) {
            return null;
        }

        return [
            'payload' => $payload,
            'expires_in' => max(1, $expiresAt - time()),
        ];
    }

    private function pointerDigest(mixed $pointer): ?string
    {
        // Accept the legacy string pointer during a rolling deployment. It can
        // still be consumed/revoked, but cannot be reused because the old cache
        // entry deliberately did not retain a recoverable bearer payload.
        $digest = is_string($pointer)
            ? $pointer
            : (is_array($pointer) ? ($pointer['digest'] ?? null) : null);
        return is_string($digest) && preg_match('/^[a-f0-9]{64}$/', $digest) === 1
            ? $digest
            : null;
    }
}
