<?php

namespace App\Services;

use App\Models\HdProjectTask;
use App\Models\HdProjectTaskAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HdProjectTaskAttachmentService
{
    protected PublicAttachmentStorage $storage;

    public function __construct()
    {
        $this->storage = PublicAttachmentStorage::forProjectTasks();
    }

    public function fileRules(): array
    {
        $mimes = config('managementpro.ticket_message_attachment.mimes', 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip,rar');
        $maxKb = (int) config('managementpro.ticket_message_attachment.max_kb', 10240);

        return [
            'nullable',
            'file',
            "max:{$maxKb}",
            "mimes:{$mimes}",
        ];
    }

    public function store(HdProjectTask $task, ?UploadedFile $file, int $userId, ?string $description = null): HdProjectTaskAttachment
    {
        $text = $description !== null ? trim($description) : '';

        if ($file) {
            $path = $this->storage->storeUploadedFile($file, "hd-project-tasks/{$task->id}");

            return HdProjectTaskAttachment::create([
                'hd_project_tasks_id' => $task->id,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'description' => $text !== '' ? $text : null,
                'users_created' => $userId,
            ]);
        }

        return HdProjectTaskAttachment::create([
            'hd_project_tasks_id' => $task->id,
            'path' => null,
            'original_name' => null,
            'description' => $text,
            'users_created' => $userId,
        ]);
    }

    public function updateDescription(HdProjectTaskAttachment $attachment, ?string $description): HdProjectTaskAttachment
    {
        $attachment->update([
            'description' => $description !== null && $description !== '' ? $description : null,
        ]);

        return $attachment->fresh();
    }

    public function delete(HdProjectTaskAttachment $attachment): void
    {
        $this->storage->deletePath($attachment->path);
        $attachment->delete();
    }

    /**
     * Hapus semua lampiran task (berkas di storage + baris DB) sebelum task dihapus permanen.
     */
    public function deleteAllForTask(HdProjectTask $task): void
    {
        $task->attachments()->get()->each(fn (HdProjectTaskAttachment $attachment) => $this->delete($attachment));

        $taskDir = 'hd-project-tasks/'.$task->id;
        if ($this->storage->disk()->exists($taskDir)) {
            $this->storage->disk()->deleteDirectory($taskDir);
        }
    }

    public function metadata(HdProjectTaskAttachment $attachment, int $projectId): array
    {
        $path = $attachment->path;
        $hasFile = $path !== null && $path !== '';
        $exists = $hasFile && $this->storage->exists($path);
        $name = $hasFile
            ? ($attachment->original_name ?: $this->displayName($path))
            : null;
        $mime = $exists ? $this->storage->mimeType($path) : null;
        $size = $exists ? $this->storage->size($path) : null;

        return [
            'id' => $attachment->id,
            'path' => $path,
            'has_file' => $hasFile && $exists,
            'name' => $name,
            'description' => $attachment->description,
            'mime' => $mime,
            'size' => $size,
            'is_image' => $hasFile && ($mime ? str_starts_with($mime, 'image/') : $this->guessImageFromName($name ?? '')),
            'exists' => $exists,
            'public_url' => $hasFile ? $this->storage->publicUrl($path) : null,
            'download_url' => $hasFile
                ? url("/api/v1/hris-projects/{$projectId}/tasks/{$attachment->hd_project_tasks_id}/attachments/{$attachment->id}")
                : null,
            'created_at' => $attachment->created_at?->toIso8601String(),
        ];
    }

    public function download(HdProjectTaskAttachment $attachment): StreamedResponse
    {
        if (! $attachment->path || ! $this->storage->exists($attachment->path)) {
            abort(404, 'Lampiran tidak ditemukan.');
        }

        $name = $attachment->original_name ?: $this->displayName($attachment->path);

        return Storage::disk(PublicAttachmentStorage::DISK_PUBLIC)->download($attachment->path, $name);
    }

    protected function displayName(string $path): string
    {
        $base = basename($path);
        if (str_contains($base, '_')) {
            return substr($base, strpos($base, '_') + 1);
        }

        return $base;
    }

    protected function guessImageFromName(string $name): bool
    {
        return (bool) preg_match('/\.(jpe?g|png|gif|webp|bmp|svg)$/i', $name);
    }
}
