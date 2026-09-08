<?php

namespace App\Services;

use App\Contracts\SnagNotifier;
use App\Enums\SnagPhotoType;
use App\Enums\SnagPriority;
use App\Enums\SnagStatus;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\SnagHistory;
use App\Models\SnagItem;
use App\Models\SnagPhoto;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SnagService
{
    public function __construct(private SnagNotifier $notifier) {}

    public function create(Project $project, array $data, ?User $user, array $photos = []): SnagItem
    {
        return DB::transaction(function () use ($project, $data, $user, $photos) {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            $workerId = $data['assigned_worker_id'] ?? null;
            $notify = (bool) ($data['notify'] ?? false);

            $snag = SnagItem::query()->create([
                'project_id' => $project->id,
                'project_area_id' => $data['project_area_id'] ?? null,
                'document_id' => $data['document_id'] ?? $project->plattegrond()?->id,
                'drawing_page' => $data['drawing_page'] ?? 1,
                'x' => $data['x'] ?? null,
                'y' => $data['y'] ?? null,
                'number' => $this->nextNumber($project),
                'description' => $data['description'] ?? null,
                'assigned_worker_id' => $workerId,
                'priority' => $data['priority'] ?? SnagPriority::Normal,
                'due_date' => $data['due_date'] ?? null,
                'logged_on' => $data['logged_on'] ?? now()->toDateString(),
                'status' => $notify && $workerId ? SnagStatus::Assigned : ($workerId ? SnagStatus::Open : SnagStatus::Open),
                'created_by' => $user?->id,
            ]);

            $this->history($snag, 'created', null, $snag->status->value, $snag->assigned_worker_id, null, $user);

            foreach ($photos as $photo) {
                if ($photo instanceof UploadedFile) {
                    $this->storePhoto($snag, $photo, SnagPhotoType::Issue, $user);
                }
            }

            return $snag->load(['area', 'assignee', 'photos']);
        });
    }

    /**
     * @param  list<UploadedFile>  $photos
     */
    public function update(SnagItem $snag, array $data, ?User $user, array $photos = []): SnagItem
    {
        $oldStatus = $snag->status;
        $oldWorker = $snag->assigned_worker_id;

        $allowed = [
            'description', 'assigned_worker_id', 'priority', 'due_date', 'logged_on',
            'project_area_id', 'status', 'x', 'y', 'drawing_page', 'document_id',
        ];
        $payload = array_intersect_key($data, array_flip($allowed));
        if (! array_key_exists('status', $payload) || $payload['status'] === null || $payload['status'] === '') {
            unset($payload['status']);
        } elseif (is_string($payload['status'])) {
            $payload['status'] = SnagStatus::from($payload['status']);
        }
        $snag->fill($payload);
        $this->syncClosure($snag, $oldStatus, $user);
        $snag->save();

        if ($oldStatus !== $snag->status || $oldWorker !== $snag->assigned_worker_id) {
            $action = $oldWorker !== $snag->assigned_worker_id
                ? 'assigned'
                : ($snag->status === SnagStatus::Closed ? 'closed' : 'status');
            $note = $data['note'] ?? null;
            if ($snag->status === SnagStatus::Closed && $oldStatus !== SnagStatus::Closed && $user) {
                $note = $note ?: 'Afgehandeld door '.$user->name;
            }
            $this->history(
                $snag,
                $action,
                $oldStatus->value,
                $snag->status->value,
                $snag->assigned_worker_id,
                $note,
                $user
            );
        } elseif (! empty($data['note'])) {
            $this->history($snag, 'note', $oldStatus->value, $snag->status->value, $snag->assigned_worker_id, $data['note'], $user);
        }

        $photoType = SnagPhotoType::forStatus($snag->status);
        if (! empty($data['photo_type'])) {
            $photoType = $data['photo_type'] instanceof SnagPhotoType
                ? $data['photo_type']
                : SnagPhotoType::from((string) $data['photo_type']);
        }
        foreach ($photos as $photo) {
            if ($photo instanceof UploadedFile) {
                $this->storePhoto($snag, $photo, $photoType, $user);
            }
        }

        return $snag->refresh()->load(['area', 'assignee', 'photos']);
    }

    public function move(SnagItem $snag, float $x, float $y, int $page, ?int $documentId = null): SnagItem
    {
        $snag->x = $x;
        $snag->y = $y;
        $snag->drawing_page = $page;
        if ($documentId) {
            $snag->document_id = $documentId;
        }
        $snag->save();

        return $snag->refresh()->load(['area', 'assignee', 'photos']);
    }

    public function addNote(SnagItem $snag, string $note, ?User $user = null): SnagItem
    {
        $this->history($snag, 'note', $snag->status->value, $snag->status->value, $snag->assigned_worker_id, $note, $user);

        return $snag->refresh()->load(['area', 'assignee', 'photos']);
    }

    public function reportDone(SnagItem $snag, ?User $user, ?string $note, array $photos = []): SnagItem
    {
        if ($snag->status->isFinished()) {
            throw new \RuntimeException('Dit opleverpunt is al gereed gemeld of afgehandeld.');
        }

        foreach ($photos as $photo) {
            if ($photo instanceof UploadedFile) {
                $this->storePhoto($snag, $photo, SnagPhotoType::Completion, $user);
            }
        }

        $snag->status = SnagStatus::ReportedDone;
        $snag->completed_at = now();
        $snag->save();
        $this->history($snag, 'reported_done', SnagStatus::Assigned->value, SnagStatus::ReportedDone->value, $snag->assigned_worker_id, $note, $user);

        return $snag->refresh()->load(['area', 'assignee', 'photos']);
    }

    public function startProgress(SnagItem $snag, ?User $user = null): SnagItem
    {
        if ($snag->status === SnagStatus::Closed || $snag->status === SnagStatus::ReportedDone) {
            return $snag;
        }

        $old = $snag->status;
        $snag->status = SnagStatus::InProgress;
        $snag->save();
        $this->history($snag, 'status', $old->value, SnagStatus::InProgress->value, $snag->assigned_worker_id, null, $user);

        return $snag->refresh()->load(['area', 'assignee', 'photos']);
    }

    public function approve(SnagItem $snag, ?User $user): SnagItem
    {
        $snag->status = SnagStatus::Closed;
        $snag->approved_at = now();
        $snag->closed_at = $snag->approved_at;
        $snag->closed_by = $user?->id;
        $snag->save();
        $this->history(
            $snag,
            'closed',
            SnagStatus::ReportedDone->value,
            SnagStatus::Closed->value,
            $snag->assigned_worker_id,
            $user?->name ? 'Afgehandeld door '.$user->name : null,
            $user
        );

        return $snag->refresh()->load(['area', 'assignee', 'photos']);
    }

    public function reject(SnagItem $snag, ?User $user, ?string $note, array $photos = []): SnagItem
    {
        foreach ($photos as $photo) {
            if ($photo instanceof UploadedFile) {
                $this->storePhoto($snag, $photo, SnagPhotoType::Issue, $user);
            }
        }

        $snag->status = $snag->assigned_worker_id ? SnagStatus::Assigned : SnagStatus::Open;
        $snag->completed_at = null;
        $snag->approved_at = null;
        $snag->closed_at = null;
        $snag->closed_by = null;
        $snag->save();
        $this->history($snag, 'rejected', SnagStatus::ReportedDone->value, $snag->status->value, $snag->assigned_worker_id, $note, $user);

        return $snag->refresh()->load(['area', 'assignee', 'photos']);
    }

    public function delete(SnagItem $snag): void
    {
        $snag->loadMissing('photos');
        foreach ($snag->photos as $photo) {
            if (Storage::disk('local')->exists($photo->file_path)) {
                Storage::disk('local')->delete($photo->file_path);
            }
        }
        $snag->delete();
    }

    public function storePhoto(SnagItem $snag, UploadedFile $file, SnagPhotoType $type, ?User $user): SnagPhoto
    {
        $extension = strtolower((string) ($file->guessExtension() ?: $file->extension() ?: 'jpg'));
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $extension = 'jpg';
        }
        $path = $file->storeAs(
            'projects/'.$snag->project_id.'/snags',
            Str::uuid()->toString().'.'.$extension,
            'local'
        );

        $photo = SnagPhoto::query()->create([
            'snag_item_id' => $snag->id,
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'photo_type' => $type,
            'uploaded_by' => $user?->id,
        ]);

        $this->history($snag, 'photo', $snag->status->value, $snag->status->value, $snag->assigned_worker_id, $type->label(), $user);

        return $photo;
    }

    public function notifyAssignment(SnagItem $snag, Worker $worker): void
    {
        if ($snag->status === SnagStatus::Open) {
            $snag->status = SnagStatus::Assigned;
            $snag->save();
        }

        $this->notifier->assigned($snag->load(['project.customer', 'area', 'photos']), $worker, $snag->publicUrl());
    }

    public function notifyRework(SnagItem $snag, Worker $worker, ?string $note = null): void
    {
        $this->notifier->rework($snag->load(['project.customer', 'area', 'photos']), $worker, $snag->publicUrl(), $note);
    }

    public function nearestArea(Project $project, int $page, float $x, float $y): ?ProjectArea
    {
        $drawing = $project->plattegrond();
        $best = null;
        $bestDistance = 0.18;

        $areas = $project->relationLoaded('areas')
            ? $project->areas
            : $project->areas()->with(['markers', 'floor'])->get();

        foreach ($areas as $area) {
            $markers = $area->relationLoaded('markers') ? $area->markers : $area->markers;
            foreach ($markers as $marker) {
                if ($drawing && (int) $marker->project_document_id !== (int) $drawing->id) {
                    continue;
                }
                if ((int) $marker->page !== $page) {
                    continue;
                }
                $distance = hypot((float) $marker->x - $x, (float) $marker->y - $y);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $best = $area;
                }
            }
        }

        return $best;
    }

    public function nextNumber(Project $project): int
    {
        return (int) $project->snags()->max('number') + 1;
    }

    private function syncClosure(SnagItem $snag, SnagStatus $oldStatus, ?User $user): void
    {
        if ($snag->status === SnagStatus::Closed && $oldStatus !== SnagStatus::Closed) {
            $snag->closed_at = now();
            $snag->closed_by = $user?->id;
            $snag->approved_at = $snag->closed_at;
        } elseif ($oldStatus === SnagStatus::Closed && $snag->status !== SnagStatus::Closed) {
            $snag->closed_at = null;
            $snag->closed_by = null;
            $snag->approved_at = null;
        }
    }

    private function history(
        SnagItem $snag,
        string $action,
        ?string $old,
        ?string $new,
        ?int $workerId,
        ?string $note,
        ?User $user,
    ): void {
        SnagHistory::query()->create([
            'snag_item_id' => $snag->id,
            'action' => $action,
            'old_status' => $old,
            'new_status' => $new,
            'worker_id' => $workerId,
            'note' => $note,
            'created_by' => $user?->id,
        ]);
    }
}
