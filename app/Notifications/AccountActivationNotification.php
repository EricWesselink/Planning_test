<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountActivationNotification extends Notification
{
    public function __construct(public string $activationUrl) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Account activeren · Nicon Planning')
            ->line('Je account voor Nicon Planning is aangemaakt.')
            ->line('Klik hier om je wachtwoord in te stellen. De link is 24 uur geldig en werkt één keer.')
            ->action('Wachtwoord instellen', $this->activationUrl);
    }
}
