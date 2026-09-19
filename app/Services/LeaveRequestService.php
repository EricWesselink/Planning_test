<?php

namespace App\Services;

use App\Enums\AvailabilityKind;
use App\Enums\AvailabilitySlot;
use App\Enums\LeaveRequestStatus;
use App\Enums\UserRole;
use App\Mail\LeaveRequestApprovedMail;
use App\Mail\LeaveRequestPeriodChangedMail;
use App\Mail\LeaveRequestQuestionMail;
use App\Mail\LeaveRequestRejectedMail;
use App\Mail\LeaveRequestReplyMail;
use App\Mail\LeaveRequestSubmittedMail;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestMessage;
use App\Models\User;
use App\Models\WorkerAssignment;
use App\Support\PlanningHours;
use Carbon\Carbon;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class LeaveRequestService
{
    public function submit(User $vakman, string $startsOn, string $endsOn, ?string $note): LeaveRequest
    {
        $request = LeaveRequest::query()->create([
            'user_id' => $vakman->id,
            'worker_id' => $vakman->scheduledWorkerId(),
            'crew_member_id' => $vakman->scheduledCrewMemberId(),
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'note' => $note,
            'status' => LeaveRequestStatus::Pending,
            'submitted_at' => now(),
        ]);

        $this->notifyAdmins($request);

        return $request;
    }

    public function withdraw(LeaveRequest $request): void
    {
        $request->update([
            'status' => LeaveRequestStatus::Withdrawn,
            'reviewed_at' => now(),
        ]);
    }

    public function postMessage(LeaveRequest $request, User $author, string $body): LeaveRequestMessage
    {
        $message = DB::transaction(function () use ($request, $author, $body): LeaveRequestMessage {
            $locked = $this->lockPending($request);

            return $locked->messages()->create([
                'user_id' => $author->id,
                'body' => $body,
                'is_system' => false,
            ]);
        });

        $this->notifyMessageRecipients($request->fresh(['user', 'worker']) ?? $request, $author, $message);

        return $message;
    }

    public function adjustPeriod(LeaveRequest $request, User $reviewer, string $startsOn, string $endsOn): LeaveRequest
    {
        $updated = DB::transaction(function () use ($request, $reviewer, $startsOn, $endsOn): LeaveRequest {
            $locked = $this->lockPending($request);
            $fromStart = $locked->starts_on->toDateString();
            $fromEnd = $locked->ends_on->toDateString();

            if ($fromStart === $startsOn && $fromEnd === $endsOn) {
                throw ValidationException::withMessages([
                    'starts_on' => 'De periode is ongewijzigd.',
                ]);
            }

            $locked->update([
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'original_starts_on' => $locked->original_starts_on?->toDateString() ?? $fromStart,
                'original_ends_on' => $locked->original_ends_on?->toDateString() ?? $fromEnd,
                'period_adjusted_by' => $reviewer->id,
                'period_adjusted_at' => now(),
            ]);

            $locked->messages()->create([
                'user_id' => $reviewer->id,
                'body' => $reviewer->name.' heeft de periode gewijzigd van '.$this->shortPeriodPhrase($fromStart, $fromEnd).' naar '.$this->shortPeriodPhrase($startsOn, $endsOn).'.',
                'is_system' => true,
            ]);

            return $locked->fresh(['user', 'worker', 'periodAdjuster', 'messages.user']) ?? $locked;
        });

        $this->notifyPeriodChanged($updated, $reviewer);

        return $updated;
    }

    public function approve(LeaveRequest $request, User $reviewer): LeaveRequest
    {
        return DB::transaction(function () use ($request, $reviewer): LeaveRequest {
            $locked = $this->lockPending($request);

            $availability = $locked->worker()->firstOrFail()->availabilities()->create([
                'crew_member_id' => $locked->crew_member_id,
                'start_date' => $locked->starts_on,
                'end_date' => $locked->ends_on,
                'kind' => AvailabilityKind::DayOff,
                'slot' => AvailabilitySlot::Full,
                'hours' => PlanningHours::WORKDAY_HOURS,
            ]);

            $locked->update([
                'status' => LeaveRequestStatus::Approved,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'worker_availability_id' => $availability->id,
            ]);

            return $locked->fresh(['user', 'worker', 'availability']) ?? $locked;
        });
    }

    public function reject(LeaveRequest $request, User $reviewer, ?string $reason): LeaveRequest
    {
        return DB::transaction(function () use ($request, $reviewer, $reason): LeaveRequest {
            $locked = $this->lockPending($request);

            $locked->update([
                'status' => LeaveRequestStatus::Rejected,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            return $locked->fresh(['user', 'worker']) ?? $locked;
        });
    }

    public function notifyApproved(LeaveRequest $request): void
    {
        $email = $request->user?->email;
        if (! is_string($email) || $email === '') {
            return;
        }

        Mail::to($email)->send(new LeaveRequestApprovedMail($request));
    }

    public function notifyRejected(LeaveRequest $request): void
    {
        $email = $request->user?->email;
        if (! is_string($email) || $email === '') {
            return;
        }

        Mail::to($email)->send(new LeaveRequestRejectedMail($request));
    }

    /**
     * @return list<array{date: string, date_label: string, project: string, work: string, time: ?string}>
     */
    public function planningConflicts(LeaveRequest $request): array
    {
        $assignments = WorkerAssignment::query()
            ->with(['project', 'workItem'])
            ->where('worker_id', $request->worker_id)
            ->whereDate('start_date', '<=', $request->ends_on)
            ->whereDate('end_date', '>=', $request->starts_on)
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        $conflicts = [];
        foreach ($assignments as $assignment) {
            $day = $request->starts_on->copy()->startOfDay();
            $last = $request->ends_on->copy()->startOfDay();
            while ($day->lte($last)) {
                if ($assignment->coversDate($day)) {
                    $start = PlanningHours::formatTime($assignment->start_time);
                    $end = PlanningHours::formatTime($assignment->end_time);
                    $time = $start === PlanningHours::DAY_START && $end === PlanningHours::DAY_END
                        ? 'Hele dag'
                        : $start.'–'.$end;
                    $conflicts[] = [
                        'date' => $day->toDateString(),
                        'date_label' => $day->translatedFormat('d-m-Y'),
                        'project' => $assignment->project?->displayTitle() ?? $assignment->project?->name ?? 'Onbekend werk',
                        'work' => $assignment->workItem?->name ?? 'Ingepland',
                        'time' => $time,
                    ];
                }
                $day->addDay();
            }
        }

        return $conflicts;
    }

    private function lockPending(LeaveRequest $request): LeaveRequest
    {
        $locked = LeaveRequest::query()
            ->whereKey($request->id)
            ->where('status', LeaveRequestStatus::Pending)
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            throw ValidationException::withMessages([
                'status' => 'Deze aanvraag is al behandeld.',
            ]);
        }

        return $locked;
    }

    private function notifyAdmins(LeaveRequest $request): void
    {
        $request->loadMissing(['user', 'worker']);

        foreach ($this->activeAdmins() as $admin) {
            Mail::to($admin->email)->send(new LeaveRequestSubmittedMail($request));
        }
    }

    private function notifyMessageRecipients(LeaveRequest $request, User $sender, LeaveRequestMessage $message): void
    {
        $request->loadMissing(['user', 'worker']);
        $message->loadMissing('user');

        if ((int) $request->user_id === (int) $sender->id) {
            foreach ($this->activeAdmins() as $admin) {
                if ((int) $admin->id === (int) $sender->id) {
                    continue;
                }

                Mail::to($admin->email)->send(new LeaveRequestReplyMail($request, $message));
            }

            return;
        }

        $this->sendMailToUser($request->user, $sender, new LeaveRequestQuestionMail($request, $message));
    }

    private function notifyPeriodChanged(LeaveRequest $request, User $actor): void
    {
        $request->loadMissing(['user', 'worker']);
        $this->sendMailToUser($request->user, $actor, new LeaveRequestPeriodChangedMail($request));
    }

    private function sendMailToUser(?User $recipient, User $sender, Mailable $mail): void
    {
        if ($recipient === null || (int) $recipient->id === (int) $sender->id) {
            return;
        }

        $email = $recipient->email;
        if (! is_string($email) || $email === '') {
            return;
        }

        Mail::to($email)->send($mail);
    }

    /**
     * @return Collection<int, User>
     */
    private function activeAdmins(): Collection
    {
        return User::query()
            ->where('role', UserRole::Admin)
            ->where('active', true)
            ->whereNotNull('email')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $admin): bool => is_string($admin->email) && $admin->email !== '');
    }

    private function shortPeriodPhrase(string $start, string $end): string
    {
        $from = Carbon::parse($start);
        $to = Carbon::parse($end);

        if ($from->isSameDay($to)) {
            return $from->format('d-m-Y');
        }

        return $from->format('d-m-Y').' t/m '.$to->format('d-m-Y');
    }
}
