<?php

namespace App\Notifications;

use App\Notifications\Channels\FcmChannel;
use App\Notifications\Channels\TemplatedMailChannel;
use App\Notifications\Messages\FcmMessage;
use App\Notifications\Messages\TemplatedMailMessage;
use App\Services\AiCredits;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells the employer their AI call credits are running low, or have run out
 * and calls are waiting. Sent once per dip ({@see AiCredits::alertIfLow()}),
 * and at most twice a day for calls held back ({@see AiCredits::alertOutOfCredits()}).
 */
class AiCreditsLow extends Notification
{
    use Queueable;

    public function __construct(public int $balance, public bool $empty = false) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', FcmChannel::class, TemplatedMailChannel::class];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return FcmMessage::create($this->title(), $this->line(), [
            'type' => 'ai_credits.low',
            'balance' => (string) $this->balance,
            'empty' => $this->empty ? '1' : '0',
            'url' => '/employer/ai-credits',
        ]);
    }

    public function toTemplatedMail(object $notifiable): TemplatedMailMessage
    {
        return TemplatedMailMessage::create('ai_credits_low', [
            'employer_name' => $notifiable->name ?? '',
            'balance' => (string) $this->balance,
            'headline' => $this->line(),
            'action_url' => url('/employer/ai-credits'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ai_credits.low',
            'balance' => $this->balance,
            'empty' => $this->empty,
            'title' => $this->title(),
            'message' => $this->line(),
            'url' => '/employer/ai-credits',
        ];
    }

    private function title(): string
    {
        return $this->empty ? __('AI calls paused — recharge credits') : __('AI call credits running low');
    }

    private function line(): string
    {
        return $this->empty
            ? __('You have :balance credits left, not enough for an AI call. Shortlisted applicants are not being called until you recharge.', ['balance' => $this->balance])
            : __('Only :balance AI call credits left. Recharge so screening calls keep going.', ['balance' => $this->balance]);
    }
}
