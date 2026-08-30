<?php

namespace Plugin\Crisp;

use App\Services\Plugin\AbstractPlugin;

final class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('theme.support.crisp.website_id', function (string $fallback): string {
            $websiteId = trim((string) $this->getConfig('website_id', ''));
            return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $websiteId)
                ? $websiteId
                : $fallback;
        });
    }
}
