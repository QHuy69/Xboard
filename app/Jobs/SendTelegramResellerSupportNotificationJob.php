<?php

namespace App\Jobs;

use App\Services\Plugin\PluginManager;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTelegramResellerSupportNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 40;

    public function __construct(
        protected int $adminUserId,
        protected int $ticketId,
        protected int $ticketMessageId,
    ) {
        $this->onQueue('send_telegram');
    }

    public function handle(PluginManager $pluginManager): void
    {
        // A queued notification must honor a plugin disable that happened
        // after dispatch and before the worker received the job.
        if (!TelegramService::runtimeEnabled()) {
            return;
        }

        $plugin = $pluginManager->getPlugin('telegram');
        if (!$plugin || !method_exists($plugin, 'deliverQueuedSupportNotification')) {
            return;
        }

        $plugin->deliverQueuedSupportNotification(
            $this->adminUserId,
            $this->ticketId,
            $this->ticketMessageId,
        );
    }
}
