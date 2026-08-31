<?php

namespace App\Jobs;

use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected string $telegramId;
    protected $text;

    // Telegram has no idempotency key for sendMessage. Retrying a queued job
    // after an ambiguous timeout can deliver the same notification twice.
    public $tries = 1;
    // Allow the single HTTP attempt to finish before the worker terminates it.
    public $timeout = 40;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(int|string $telegramId, string $text)
    {
        $this->onQueue('send_telegram');
        $this->telegramId = (string) $telegramId;
        $this->text = $text;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $telegramService = new TelegramService();
        $telegramService->sendMessage($this->telegramId, $this->text, 'markdown');
    }
}
