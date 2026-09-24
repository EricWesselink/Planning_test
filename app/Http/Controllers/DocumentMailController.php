<?php

namespace App\Http\Controllers;

use App\Enums\DocumentMailStatus;
use App\Enums\DocumentMailType;
use App\Models\DocumentMail;
use App\Models\Project;
use App\Models\WorkTicket;
use App\Services\DocumentMailService;
use App\Services\PersonnelWeekOverviewService;
use App\Services\WeekplanningPdfService;
use App\Services\WorkTicketPdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DocumentMailController extends Controller
{
    public function weekplanning(Request $request, WeekplanningPdfService $weekplanning, DocumentMailService $mailer): RedirectResponse
    {
        Gate::authorize('view-planning');
        abort_unless($request->user()?->canDownloadPlanningWeekPdf() ?? false, 403);
        $fields = $this->fields($request);
        $mail = $mailer->send(
            $request->user(),
            DocumentMailType::Weekplanning,
            $fields['recipient'],
            $fields['cc'],
            $fields['subject'],
            $fields['body'],
            function () use ($weekplanning, $request): array {
                $document = $weekplanning->makePdf($request);

                return [
                    'binary' => $document['pdf']->output(),
                    'filename' => $document['filename'],
                ];
            },
        );

        return $this->afterSend($mail);
    }

    public function personnelWeek(Request $request, PersonnelWeekOverviewService $overview, DocumentMailService $mailer): RedirectResponse
    {
        Gate::authorize('view-personnel');
        abort_unless($request->user()?->canDownloadPlanningWeekPdf() ?? false, 403);
        $this->authorizeRequestedProject($request);
        $fields = $this->fields($request);
        $mail = $mailer->send(
            $request->user(),
            DocumentMailType::PersonnelWeek,
            $fields['recipient'],
            $fields['cc'],
            $fields['subject'],
            $fields['body'],
            function () use ($overview, $request): array {
                $document = $overview->makePdf($request);

                return [
                    'binary' => $document['pdf']->output(),
                    'filename' => $document['filename'],
                ];
            },
        );

        return $this->afterSend($mail);
    }

    public function workTicket(Request $request, WorkTicket $workTicket, WorkTicketPdfService $pdfs, DocumentMailService $mailer): RedirectResponse
    {
        $workTicket->load(['worker', 'project.customer', 'project.measurementForm']);
        Gate::authorize('view', $workTicket);
        $fields = $this->fields($request);
        $showPrices = Gate::allows('viewPrices', $workTicket);
        $includeMeasurement = $pdfs->wantsMeasurementForm($workTicket, $request);
        $document = $pdfs->makePdf($workTicket, $showPrices, $includeMeasurement);
        if ($document === null) {
            return back()->withErrors([
                'email' => 'Deze bon kan niet worden gemaild omdat de tekening alleen via afdrukken als PDF beschikbaar is.',
            ], 'document_mail')->withInput();
        }

        $type = $workTicket->isOpdrachtbon()
            ? DocumentMailType::Opdrachtbon
            : DocumentMailType::Werkbon;
        $mail = $mailer->send(
            $request->user(),
            $type,
            $fields['recipient'],
            $fields['cc'],
            $fields['subject'],
            $fields['body'],
            fn (): array => [
                'binary' => $document['pdf']->output(),
                'filename' => $document['filename'],
            ],
            $workTicket->project_id,
            $workTicket->id,
        );

        return $this->afterSend($mail);
    }

    public function projectIndex(Project $project): View
    {
        Gate::authorize('view', $project);
        $mails = DocumentMail::query()
            ->with('workTicket')
            ->where('project_id', $project->id)
            ->latest()
            ->limit(50)
            ->get();

        return view('projects.emails', [
            'project' => $project,
            'mails' => $mails,
        ]);
    }

    public function projectShow(Project $project, DocumentMail $documentMail): View
    {
        Gate::authorize('view', $project);
        abort_unless((int) $documentMail->project_id === (int) $project->id, 404);
        $documentMail->load('workTicket');

        return view('projects.email', [
            'project' => $project,
            'mail' => $documentMail,
        ]);
    }

    /**
     * @return array{recipient: string, cc: ?string, subject: string, body: string}
     */
    private function fields(Request $request): array
    {
        $validator = validator($request->all(), [
            'recipient' => ['required', 'email', 'max:255'],
            'cc' => ['nullable', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
        ], [
            'recipient.required' => 'Vul een e-mailadres in.',
            'recipient.email' => 'Vul een geldig e-mailadres in.',
            'cc.email' => 'Vul een geldig CC-adres in.',
            'subject.required' => 'Vul een onderwerp in.',
            'body.required' => 'Vul een bericht in.',
        ]);
        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray())
                ->errorBag('document_mail');
        }
        $data = $validator->validated();

        $cc = trim((string) ($data['cc'] ?? ''));

        return [
            'recipient' => $data['recipient'],
            'cc' => $cc !== '' ? $cc : null,
            'subject' => $data['subject'],
            'body' => $data['body'],
        ];
    }

    private function afterSend(DocumentMail $mail): RedirectResponse
    {
        if ($mail->status === DocumentMailStatus::Failed) {
            return back()
                ->withInput()
                ->withErrors(['email' => 'De e-mail kon niet worden verzonden. Probeer het later opnieuw.'], 'document_mail');
        }

        return back()->with('status', 'E-mail is verzonden naar '.$mail->recipient.'.');
    }

    private function authorizeRequestedProject(Request $request): void
    {
        if (! $request->filled('project_id')) {
            return;
        }

        Gate::authorize('view', Project::query()->findOrFail($request->integer('project_id')));
    }
}
