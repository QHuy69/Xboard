<?php

namespace Plugin\Messenger;

use App\Services\Plugin\AbstractPlugin;

final class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('theme.support.messenger.page_username', function (string $fallback): string {
            $username = trim((string) $this->getConfig('page_username', ''));
            return preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username) ? $username : $fallback;
        });
    }
}
