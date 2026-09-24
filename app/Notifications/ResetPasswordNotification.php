<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    public function __construct(public string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', ['token' => $this->token]);

        return (new MailMessage)
            ->subject('Wachtwoord herstellen · Nicon Planning')
            ->line('Er is een verzoek gedaan om je wachtwoord te herstellen.')
            ->line('De link is 60 minuten geldig en werkt één keer.')
            ->action('Wachtwoord herstellen', $url)
            ->line('Heb je dit niet aangevraagd, dan hoef je niets te doen.');
    }
}
