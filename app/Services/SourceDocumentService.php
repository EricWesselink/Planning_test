<?php

namespace App\Services;

use App\Enums\ImportDocumentType;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SourceDocumentService
{
    /**
     * @return list<string>
     */
    public static function sourceTypes(): array
    {
        return [
            ImportDocumentType::Calculatie->value,
            ImportDocumentType::Meetstaat->value,
            ImportDocumentType::Materialenstaat->value,
            ImportDocumentType::Plattegrond->value,
            ImportDocumentType::Snijmaten->value,
            ImportDocumentType::Afmetingen->value,
        ];
    }

    public function current(Project $project, string $type): ?ProjectDocument
    {
        $project->loadMissing('documents');

        return $project->documents
            ->where('document_type', $type)
            ->where('is_current', true)
            ->sortByDesc('revision')
            ->first()
            ?? $project->documents->where('document_type', $type)->sortByDesc('id')->first();
    }

    public function storeUploaded(
        Project $project,
        UploadedFile $file,
        string $type,
        User $user,
        string $parseStatus = 'none',
        ?array $parsedJson = null,
    ): ProjectDocument {
        $extension = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $path = $file->storeAs(
            'projects/'.$project->id.'/'.$type,
            Str::uuid()->toString().'.'.$extension,
            'local'
        );

        return $this->createRevision(
            $project,
            $type,
            $user,
            $path,
            $file->getClientOriginalName(),
            $file->getMimeType(),
            $file->getSize() ?: null,
            $parseStatus,
            $parsedJson,
        );
    }

    public function storeFromPath(
        Project $project,
        string $absolutePath,
        string $type,
        string $originalName,
        User $user,
        string $parseStatus = 'none',
        ?array $parsedJson = null,
    ): ProjectDocument {
        $filename = Str::uuid()->toString().'.'.(pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'pdf');
        $stored = 'projects/'.$project->id.'/'.$type.'/'.$filename;
        Storage::disk('local')->put($stored, (string) file_get_contents($absolutePath));

        return $this->createRevision(
            $project,
            $type,
            $user,
            $stored,
            $originalName,
            $this->mimeFromPath($absolutePath),
            is_file($absolutePath) ? (filesize($absolutePath) ?: null) : null,
            $parseStatus,
            $parsedJson,
        );
    }

    public function markCurrent(ProjectDocument $document): void
    {
        ProjectDocument::query()
            ->where('project_id', $document->project_id)
            ->where('document_type', $document->document_type)
            ->where('id', '!=', $document->id)
            ->update(['is_current' => false]);

        $document->forceFill(['is_current' => true])->save();
    }

    /**
     * @return list<array{
     *     type: string,
     *     label: string,
     *     version_word: string,
     *     revision: ?int,
     *     date: ?string,
     *     filename: ?string,
     *     user: ?string,
     *     current: ?ProjectDocument,
     *     history: list<ProjectDocument>
     * }>
     */
    public function catalog(Project $project): array
    {
        $project->loadMissing(['documents.uploader']);
        $rows = [];
        foreach (self::sourceTypes() as $type) {
            $docs = $project->documents
                ->where('document_type', $type)
                ->sortByDesc('revision')
                ->sortByDesc('id')
                ->values();
            $current = $docs->firstWhere('is_current', true) ?? $docs->first();
            $history = $docs->reject(fn (ProjectDocument $doc): bool => $current !== null && (int) $doc->id === (int) $current->id)->values();
            $rows[] = [
                'type' => $type,
                'label' => $this->typeLabel($type),
                'version_word' => $type === ImportDocumentType::Plattegrond->value ? 'revisie' : 'versie',
                'revision' => $current?->revision,
                'date' => $current?->created_at?->format('d-m-Y'),
                'filename' => $current?->original_filename,
                'user' => $current?->uploader?->name,
                'current' => $current,
                'history' => $history->all(),
            ];
        }

        return $rows;
    }

    public function typeLabel(string $type): string
    {
        return match ($type) {
            ImportDocumentType::Calculatie->value => 'Excel calculatie',
            ImportDocumentType::Meetstaat->value => 'Meetstaat',
            ImportDocumentType::Materialenstaat->value => 'Materialenstaat',
            ImportDocumentType::Plattegrond->value => 'Plattegrond',
            ImportDocumentType::Snijmaten->value => 'Snijmaten',
            ImportDocumentType::Afmetingen->value => 'Afmetingen',
            'opdrachtlijst' => 'Opdrachtlijst',
            default => ImportDocumentType::tryFrom($type)?->label() ?? ucfirst($type),
        };
    }

    /**
     * @param  array<string, mixed>|null  $parsedJson
     */
    private function createRevision(
        Project $project,
        string $type,
        User $user,
        string $path,
        string $originalName,
        ?string $mimeType,
        ?int $fileSize,
        string $parseStatus,
        ?array $parsedJson,
    ): ProjectDocument {
        $next = (int) ProjectDocument::query()
            ->where('project_id', $project->id)
            ->where('document_type', $type)
            ->max('revision') + 1;

        ProjectDocument::query()
            ->where('project_id', $project->id)
            ->where('document_type', $type)
            ->where('is_current', true)
            ->update(['is_current' => false]);

        $document = ProjectDocument::query()->create([
            'project_id' => $project->id,
            'document_type' => $type,
            'revision' => max(1, $next),
            'is_current' => true,
            'original_filename' => $originalName,
            'file_path' => $path,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'parse_status' => $parseStatus,
            'parsed_json' => $parsedJson,
            'uploaded_by' => $user->id,
        ]);
        $project->unsetRelation('documents');

        return $document;
    }

    private function mimeFromPath(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'application/pdf',
            'csv', 'txt' => 'text/csv',
            'xlsx', 'xlsm' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }
}
