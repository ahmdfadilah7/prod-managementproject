<?php

namespace App\Services;

use App\Models\HdTicket;
use App\Models\HdTicketMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketMessageAttachmentService
{
    protected PublicAttachmentStorage $storage;

    public function __construct()
    {
        $this->storage = PublicAttachmentStorage::forHelpdeskTickets();
    }

    public function rules(): array
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

    public function store(HdTicket $ticket, \Illuminate\Http\UploadedFile $file): string
    {
        $directory = $this->storage->helpdeskTicketDirectory(
            $this->storage->usesFilamentHelpdeskLayout() ? null : $ticket->id
        );

        return $this->storage->storeUploadedFile($file, $directory);
    }

    public function delete(?string $path): void
    {
        $this->storage->deletePath($path);
    }

    public function metadata(HdTicketMessage $message): ?array
    {
        if (! $message->attachment) {
            return null;
        }

        $path = $message->attachment;
        $exists = $this->storage->existsOrRemote($path);
        $name = $this->displayName($path);
        $mime = $this->storage->mimeType($path);
        $size = $this->storage->size($path);

        return [
            'path' => $path,
            'name' => $name,
            'mime' => $mime,
            'size' => $size,
            'is_image' => $mime ? str_starts_with($mime, 'image/') : $this->guessImageFromName($name),
            'exists' => $exists,
            'public_url' => $this->storage->publicUrl($path),
            'download_url' => url("/api/v1/ticket-messages/{$message->hd_tickets_id}/{$message->id}/attachment"),
        ];
    }

    public function download(HdTicketMessage $message): StreamedResponse|RedirectResponse
    {
        $path = $message->attachment;
        if (! $path) {
            abort(404, 'Lampiran tidak ditemukan.');
        }

        $relative = $this->storage->normalizePath($path);
        $diskName = $this->storage->diskName();

        if ($this->storage->exists($path)) {
            return Storage::disk($diskName)->download($relative, $this->displayName($path));
        }

        if (Storage::disk(PublicAttachmentStorage::DISK_PUBLIC)->exists($relative)) {
            return Storage::disk(PublicAttachmentStorage::DISK_PUBLIC)
                ->download($relative, $this->displayName($path));
        }

        $publicUrl = $this->storage->publicUrl($path);
        if ($publicUrl) {
            return redirect()->away($publicUrl);
        }

        abort(404, 'Lampiran tidak ditemukan.');
    }

    protected function displayName(string $path): string
    {
        $base = basename(str_replace('\\', '/', $path));

        // Format lama ManagementPro: uuid_slug.ext
        if (str_contains($base, '_') && ! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}\./i', $base)) {
            return substr($base, strpos($base, '_') + 1);
        }

        return $base;
    }

    protected function guessImageFromName(string $name): bool
    {
        return (bool) preg_match('/\.(jpe?g|png|gif|webp|bmp|svg)$/i', $name);
    }
}
