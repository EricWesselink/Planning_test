<?php

namespace App\Services;

use App\Contracts\SnagNotifier;
use App\Models\SnagItem;
use App\Models\Worker;
use App\Notifications\SnagAssignedNotification;
use Illuminate\Support\Facades\Notification;

class MailSnagNotifier implements SnagNotifier
{
    public function assigned(SnagItem $snag, Worker $worker, string $publicUrl): void
    {
        $this->send($snag, $worker, $publicUrl, 'assigned');
    }

    public function rework(SnagItem $snag, Worker $worker, string $publicUrl, ?string $note = null): void
    {
        $this->send($snag, $worker, $publicUrl, 'rework', $note);
    }

    private function send(SnagItem $snag, Worker $worker, string $publicUrl, string $context, ?string $note = null): void
    {
        if (! filled($worker->email)) {
            return;
        }

        Notification::route('mail', $worker->email)
            ->notify(new SnagAssignedNotification($snag, $publicUrl, $context, $note));
    }
}
