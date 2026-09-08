<?php

namespace App\Notifications;

use App\Models\SnagItem;
use App\Models\SnagPhoto;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class SnagAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public SnagItem $snag,
        public string $publicUrl,
        public string $context = 'assigned',
        public ?string $note = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $snag = $this->snag->loadMissing(['project.customer', 'area', 'photos']);
        $project = $snag->project;
        $address = $project->nawLine();

        $subject = $this->context === 'rework'
            ? 'Opnieuw uitvoeren: opleverpunt #'.$snag->number.' · '.$project->name
            : 'Opleverpunt #'.$snag->number.' · '.$project->name;

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting($this->context === 'rework'
                ? 'Opleverpunt #'.$snag->number.' opnieuw uitvoeren'
                : 'Opleverpunt #'.$snag->number)
            ->line('Project: '.$project->name)
            ->line('Adres: '.($address ?: '—'));

        if ($project->googleMapsUrl()) {
            $mail->line('Navigeren: '.$project->googleMapsUrl());
        }

        $mail->line('Opleverpunt: #'.$snag->number)
            ->line('Ruimte: '.($snag->area?->label() ?: 'niet gekoppeld'))
            ->line('Omschrijving: '.($snag->description ?: '—'))
            ->line('Gewenst gereed: '.($snag->due_date?->format('d-m-Y') ?: 'niet gezet'));

        if (filled($this->note)) {
            $mail->line('Opmerking: '.$this->note);
        }

        $mail->action('Open opleverpunt', $this->publicUrl)
            ->line('Je hoeft niet in te loggen. Deze link is alleen voor dit punt.');

        $photo = $snag->issuePhotos()->first() ?? $snag->photos->first();
        if ($photo instanceof SnagPhoto && Storage::disk('local')->exists($photo->file_path)) {
            $mail->attach(Storage::disk('local')->path($photo->file_path), [
                'as' => $photo->original_filename ?: 'constatering.jpg',
            ]);
        }

        return $mail;
    }
}
