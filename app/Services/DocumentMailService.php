<?php

namespace App\Services;

use App\Enums\DocumentMailStatus;
use App\Enums\DocumentMailType;
use App\Mail\DocumentPdfMail;
use App\Models\DocumentMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DocumentMailService
{
    /**
     * @param  callable(): array{binary: string, filename: string}  $render
     */
    public function send(
        User $user,
        DocumentMailType $type,
        string $recipient,
        ?string $cc,
        string $subject,
        string $body,
        callable $render,
        ?int $projectId = null,
        ?int $workTicketId = null,
    ): DocumentMail {
        $filename = 'document.pdf';

        try {
            $rendered = $render();
            $filename = $rendered['filename'];
            $pending = Mail::to($recipient);
            if (filled($cc)) {
                $pending->cc($cc);
            }
            $pending->send(new DocumentPdfMail($body, $subject, $filename, $rendered['binary']));

            return DocumentMail::query()->create([
                'user_id' => $user->id,
                'sender_name' => $user->name,
                'recipient' => $recipient,
                'cc' => $cc,
                'subject' => $subject,
                'body' => $body,
                'document_type' => $type,
                'project_id' => $projectId,
                'work_ticket_id' => $workTicketId,
                'attachment_filename' => $filename,
                'status' => DocumentMailStatus::Sent,
                'sent_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::error('Documentmail mislukt', [
                'document_type' => $type->value,
                'project_id' => $projectId,
                'work_ticket_id' => $workTicketId,
                'user_id' => $user->id,
                'exception' => $exception,
            ]);

            return DocumentMail::query()->create([
                'user_id' => $user->id,
                'sender_name' => $user->name,
                'recipient' => $recipient,
                'cc' => $cc,
                'subject' => $subject,
                'body' => $body,
                'document_type' => $type,
                'project_id' => $projectId,
                'work_ticket_id' => $workTicketId,
                'attachment_filename' => $filename,
                'status' => DocumentMailStatus::Failed,
                'error_message' => 'De e-mail kon niet worden verzonden.',
            ]);
        }
    }

    public function weekplanningDraft(User $user, int $weekNumber): array
    {
        $company = (string) config('company.name');

        return [
            'subject' => 'Planning '.$company.' - week '.$weekNumber,
            'body' => $this->body(
                $user,
                $company,
                'Hierbij ontvangt u de planning van '.$company.' voor week '.$weekNumber.'.',
            ),
        ];
    }

    public function personnelWeekDraft(User $user, int $weekNumber): array
    {
        $company = (string) config('company.name');

        return [
            'subject' => 'Personeelsplanning '.$company.' - week '.$weekNumber,
            'body' => $this->body(
                $user,
                $company,
                'Hierbij ontvangt u de personeelsplanning van '.$company.' voor week '.$weekNumber.'.',
            ),
        ];
    }

    public function workTicketDraft(User $user, string $kindLabel, string $number, string $projectName, string $company): array
    {
        $projectName = trim($projectName);

        return [
            'subject' => trim($kindLabel.' '.$number.($projectName !== '' ? ' - '.$projectName : '')),
            'body' => $this->body(
                $user,
                $company,
                'Hierbij ontvangt u de '.mb_strtolower($kindLabel).' '.$number.($projectName !== '' ? ' voor '.$projectName : '').'.',
            ),
        ];
    }

    private function body(User $user, string $company, string $sentence): string
    {
        return "Goedendag,\n\n".$sentence."\n\nMet vriendelijke groet,\n\n".$user->name."\n".$company;
    }
}
