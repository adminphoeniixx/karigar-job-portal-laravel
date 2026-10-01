<?php

namespace App\Notifications;

use App\Enums\KycStatus;
use App\Models\KycDocument;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * An admin approved or rejected this user's verification. For an employer an
 * approval is what lets its jobs go live, so the push says so.
 */
class KycReviewedNotification extends Notification
{
    use Queueable;

    public function __construct(public KycDocument $kyc) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', FcmChannel::class];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return FcmMessage::create($this->title(), $this->line($notifiable), [
            'type' => 'kyc.reviewed',
            'status' => $this->kyc->status->value,
            'url' => '/kyc',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'kyc.reviewed',
            'status' => $this->kyc->status->value,
            'remarks' => $this->kyc->remarks,
            'message' => $this->line($notifiable),
            'url' => '/kyc',
        ];
    }

    private function title(): string
    {
        return $this->kyc->status === KycStatus::Verified
            ? __('You are verified')
            : __('Verification not approved');
    }

    private function line(object $notifiable): string
    {
        if ($this->kyc->status === KycStatus::Verified) {
            return method_exists($notifiable, 'isEmployer') && $notifiable->isEmployer()
                ? __('Your business is verified. You can post jobs now.')
                : __('Your documents are verified. Your profile now shows the verified badge.');
        }

        return __('Reason: :reason. Fix it and submit again.', ['reason' => $this->kyc->remarks]);
    }
}
