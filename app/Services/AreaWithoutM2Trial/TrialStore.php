<?php

namespace App\Services\AreaWithoutM2Trial;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Isolated disk store for the area-without-m² experiment. Not a calculation.
 */
class TrialStore
{
    public const DIRECTORY = 'area-without-m2-trials';

    /**
     * Persist the uploaded PDF under a new id before any analysis.
     *
     * @return array{
     *     id: string,
     *     original_filename: string,
     *     relative_path: string,
     *     absolute_path: string,
     *     file_size: int,
     *     file_hash: string
     * }
     */
    public function storeDrawing(UploadedFile $file): array
    {
        $id = (string) Str::uuid();
        $original = $file->getClientOriginalName();
        $directory = self::DIRECTORY.'/'.$id;
        Storage::disk('local')->putFileAs($directory, $file, 'drawing.pdf');
        $relative = $directory.'/drawing.pdf';
        $absolute = Storage::disk('local')->path($relative);
        if (! is_file($absolute) || filesize($absolute) < 1) {
            $this->forget($id);

            throw new RuntimeException('De PDF kon niet worden opgeslagen.');
        }

        return [
            'id' => $id,
            'original_filename' => $original,
            'relative_path' => $relative,
            'absolute_path' => $absolute,
            'file_size' => (int) filesize($absolute),
            'file_hash' => (string) hash_file('sha256', $absolute),
        ];
    }

    /**
     * @param  array{id: string, original_filename: string, absolute_path: string, file_size: int, file_hash: string, received_upload?: array<string, mixed>}  $stored
     * @param  array<string, mixed>  $result
     */
    public function saveResult(array $stored, array $result): void
    {
        $id = $stored['id'];
        $payload = [
            'id' => $id,
            'filename' => $stored['original_filename'],
            'original_filename' => $stored['original_filename'],
            'analyzed_path' => $stored['absolute_path'],
            'file_size' => $stored['file_size'],
            'file_hash' => $stored['file_hash'],
            'received_upload' => is_array($stored['received_upload'] ?? null) ? $stored['received_upload'] : [],
            'rooms' => $result['rooms'] ?? [],
            'warnings' => $result['warnings'] ?? [],
            'source_type' => $result['source_type'] ?? 'text',
            'source_label' => $result['source_label'] ?? 'Brontype: PDF met tekstlaag',
            'ocr_engine' => $result['ocr_engine'] ?? null,
            'ocr_mean_confidence' => $result['ocr_mean_confidence'] ?? null,
            'recognized_room_names' => $result['recognized_room_names'] ?? [],
            'recognized_dimension_values' => $result['recognized_dimension_values'] ?? [],
            'calculated_room_labels' => $result['calculated_room_labels'] ?? [],
            'unavailable_room_labels' => $result['unavailable_room_labels'] ?? [],
            'timings' => is_array($result['timings'] ?? null) ? $result['timings'] : [],
            'timing_labels' => is_array($result['timing_labels'] ?? null) ? $result['timing_labels'] : [],
            'created_at' => now()->toIso8601String(),
        ];
        Storage::disk('local')->put(
            self::DIRECTORY.'/'.$id.'/result.json',
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );

        $preview = $result['preview_path'] ?? null;
        if (is_string($preview) && is_file($preview)) {
            Storage::disk('local')->put(self::DIRECTORY.'/'.$id.'/preview.png', (string) file_get_contents($preview));
            @unlink($preview);
        }
    }

    public function forget(string $id): void
    {
        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $id)) {
            return;
        }

        Storage::disk('local')->deleteDirectory(self::DIRECTORY.'/'.$id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $id)) {
            return null;
        }

        $raw = Storage::disk('local')->get(self::DIRECTORY.'/'.$id.'/result.json');
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        return [
            'id' => $id,
            'filename' => (string) ($decoded['filename'] ?? ''),
            'original_filename' => (string) ($decoded['original_filename'] ?? $decoded['filename'] ?? ''),
            'analyzed_path' => (string) ($decoded['analyzed_path'] ?? ''),
            'file_size' => (int) ($decoded['file_size'] ?? 0),
            'file_hash' => (string) ($decoded['file_hash'] ?? ''),
            'received_upload' => is_array($decoded['received_upload'] ?? null) ? $decoded['received_upload'] : [],
            'rooms' => is_array($decoded['rooms'] ?? null) ? $decoded['rooms'] : [],
            'warnings' => is_array($decoded['warnings'] ?? null) ? $decoded['warnings'] : [],
            'source_type' => (string) ($decoded['source_type'] ?? 'text'),
            'source_label' => (string) ($decoded['source_label'] ?? 'Brontype: PDF met tekstlaag'),
            'ocr_engine' => isset($decoded['ocr_engine']) ? (string) $decoded['ocr_engine'] : null,
            'ocr_mean_confidence' => isset($decoded['ocr_mean_confidence']) ? $decoded['ocr_mean_confidence'] : null,
            'recognized_room_names' => is_array($decoded['recognized_room_names'] ?? null) ? $decoded['recognized_room_names'] : [],
            'recognized_dimension_values' => is_array($decoded['recognized_dimension_values'] ?? null) ? $decoded['recognized_dimension_values'] : [],
            'calculated_room_labels' => is_array($decoded['calculated_room_labels'] ?? null) ? $decoded['calculated_room_labels'] : [],
            'unavailable_room_labels' => is_array($decoded['unavailable_room_labels'] ?? null) ? $decoded['unavailable_room_labels'] : [],
            'timings' => is_array($decoded['timings'] ?? null) ? $decoded['timings'] : [],
            'timing_labels' => is_array($decoded['timing_labels'] ?? null) ? $decoded['timing_labels'] : [],
            'created_at' => isset($decoded['created_at']) ? (string) $decoded['created_at'] : null,
            'has_preview' => Storage::disk('local')->exists(self::DIRECTORY.'/'.$id.'/preview.png'),
        ];
    }

    public function previewPath(string $id): ?string
    {
        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $id)) {
            return null;
        }

        $path = self::DIRECTORY.'/'.$id.'/preview.png';
        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        return $path;
    }
}
