<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Lampiran publik di disk local (storage/app/public) atau disk HRIS (storage HRIS website).
 */
class PublicAttachmentStorage
{
    public const DISK_PUBLIC = 'public';

    public const DISK_HRIS = 'hris_storage';

    /** Prefix folder lampiran yang boleh dibersihkan otomatis setelah hapus. */
    private const DEFAULT_PRUNABLE_PREFIXES = ['hd-project-tasks/', 'ticket-messages/'];

    private const HELPDESK_PRUNABLE_PREFIXES = [
        'helpdesk-ticket-messages/',
        'public/helpdesk-ticket-messages/',
        'ticket-messages/',
    ];

    public function __construct(
        protected string $diskName = self::DISK_PUBLIC,
        protected array $prunablePrefixes = self::DEFAULT_PRUNABLE_PREFIXES,
        protected ?string $storePathBase = null,
    ) {}

    /** Lampiran task project HRIS — hanya storage ManagementPro (disk public). */
    public static function forProjectTasks(): self
    {
        return new self(
            self::DISK_PUBLIC,
            ['hd-project-tasks/'],
            null
        );
    }

    public static function forHelpdeskTickets(): self
    {
        if (! config('managementpro.hris_mode')) {
            return new self(
                self::DISK_PUBLIC,
                self::HELPDESK_PRUNABLE_PREFIXES,
                'ticket-messages'
            );
        }

        return new self(
            self::DISK_HRIS,
            self::HELPDESK_PRUNABLE_PREFIXES,
            trim((string) config('managementpro.hris_storage.path', 'helpdesk-ticket-messages'), '/'),
        );
    }

    public function isProjectTaskStorage(): bool
    {
        return $this->diskName === self::DISK_PUBLIC
            && in_array('hd-project-tasks/', $this->prunablePrefixes, true)
            && $this->storePathBase === null;
    }

    public function usesFilamentHelpdeskLayout(): bool
    {
        return $this->diskName === self::DISK_HRIS && config('managementpro.hris_mode');
    }

    public static function hrisHelpdeskStorageEnabled(): bool
    {
        return (bool) config('managementpro.hris_mode')
            && filled(config('managementpro.hris_storage.url'));
    }

    /**
     * Pastikan upload lampiran tiket menulis ke storage HRIS, bukan ManagementPro.
     */
    public static function assertHrisStorageRootConfigured(): void
    {
        if (! config('managementpro.hris_mode')) {
            return;
        }

        $root = trim((string) config('managementpro.hris_storage.root', ''));
        if ($root === '') {
            abort(422, 'HRIS_STORAGE_ROOT belum diisi di .env. Isi path absolut ke folder storage/app/public aplikasi HRIS (website taptask), bukan folder ManagementPro.');
        }

        if (! is_dir($root)) {
            abort(422, "HRIS_STORAGE_ROOT tidak ditemukan: {$root}");
        }

        if (! is_writable($root)) {
            abort(422, "HRIS_STORAGE_ROOT tidak bisa ditulis (permission): {$root}");
        }

        $hrisReal = realpath($root);
        $localReal = realpath(storage_path('app/public'));
        if ($hrisReal && $localReal && $hrisReal === $localReal) {
            abort(422, 'HRIS_STORAGE_ROOT sama dengan storage ManagementPro. Arahkan ke storage/app/public aplikasi HRIS (mis. path project taptask).');
        }
    }

    public function diskName(): string
    {
        return $this->diskName;
    }

    public function disk()
    {
        return Storage::disk($this->diskName);
    }

    /**
     * Folder relatif di storage/app/public.
     * HRIS/Filament: helpdesk-ticket-messages (datar, tanpa subfolder ticket).
     */
    public function helpdeskTicketDirectory(?int $ticketId = null): string
    {
        if ($this->usesFilamentHelpdeskLayout() && $this->storePathBase) {
            return $this->storePathBase;
        }

        $suffix = trim((string) $ticketId, '/');

        if ($this->storePathBase !== null && $this->storePathBase !== '') {
            return $suffix !== '' ? $this->storePathBase.'/'.$suffix : $this->storePathBase;
        }

        return $suffix !== '' ? 'ticket-messages/'.$suffix : 'ticket-messages';
    }

    public function storeUploadedFile(UploadedFile $file, string $directory): string
    {
        $directory = trim(str_replace('\\', '/', $directory), '/');

        if (str_starts_with($directory, 'hd-project-tasks/')) {
            if ($this->diskName !== self::DISK_PUBLIC) {
                abort(500, 'Lampiran task project hanya disimpan di storage ManagementPro, bukan storage HRIS.');
            }
        } elseif ($this->diskName === self::DISK_HRIS) {
            self::assertHrisStorageRootConfigured();
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');

        if ($this->usesFilamentHelpdeskLayout()) {
            // Sama seperti Filament: helpdesk-ticket-messages/01KT6XDAE2SERD2R2R7M1ME5CR.jpg
            $filename = (string) Str::ulid().'.'.$ext;

            return $file->storeAs($directory, $filename, $this->diskName);
        }

        $base = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'file';
        $filename = Str::uuid().'_'.Str::limit($base, 80, '').'.'.$ext;

        return $file->storeAs($directory, $filename, $this->diskName);
    }

    public function exists(?string $path): bool
    {
        if (! $path) {
            return false;
        }

        $relative = $this->normalizePath($path);

        if ($this->disk()->exists($relative)) {
            return true;
        }

        if ($this->diskName === self::DISK_PUBLIC && Storage::disk(self::DISK_PUBLIC)->exists($relative)) {
            return true;
        }

        return false;
    }

    /** Anggap ada di HRIS remote jika path terisi dan URL HRIS dikonfigurasi. */
    public function existsOrRemote(?string $path): bool
    {
        if (! $path) {
            return false;
        }

        if ($this->exists($path)) {
            return true;
        }

        return $this->diskName === self::DISK_HRIS && self::hrisHelpdeskStorageEnabled();
    }

    public function mimeType(string $path): ?string
    {
        $relative = $this->normalizePath($path);

        if ($this->disk()->exists($relative)) {
            return (string) $this->disk()->mimeType($relative);
        }

        if ($this->diskName !== self::DISK_PUBLIC && Storage::disk(self::DISK_PUBLIC)->exists($relative)) {
            return (string) Storage::disk(self::DISK_PUBLIC)->mimeType($relative);
        }

        return null;
    }

    public function size(string $path): ?int
    {
        $relative = $this->normalizePath($path);

        if ($this->disk()->exists($relative)) {
            return $this->disk()->size($relative);
        }

        if ($this->diskName !== self::DISK_PUBLIC && Storage::disk(self::DISK_PUBLIC)->exists($relative)) {
            return Storage::disk(self::DISK_PUBLIC)->size($relative);
        }

        return null;
    }

    public function publicUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $relative = $this->normalizePath($path);

        if ($this->disk()->exists($relative)) {
            return $this->disk()->url($relative);
        }

        if ($this->diskName !== self::DISK_PUBLIC && Storage::disk(self::DISK_PUBLIC)->exists($relative)) {
            return Storage::disk(self::DISK_PUBLIC)->url($relative);
        }

        if ($this->diskName === self::DISK_HRIS && self::hrisHelpdeskStorageEnabled()) {
            return $this->buildHrisPublicUrl($relative);
        }

        return null;
    }

    public function buildHrisPublicUrl(string $relativePath): ?string
    {
        $base = rtrim((string) config('managementpro.hris_storage.url'), '/');
        if ($base === '') {
            return null;
        }

        $prefix = (string) config('managementpro.hris_storage.url_prefix', '/storage');
        $prefix = '/'.trim($prefix, '/');

        return $base.$prefix.'/'.ltrim(str_replace('\\', '/', $relativePath), '/');
    }

    /**
     * Normalisasi path dari DB ke path relatif di storage/app/public HRIS.
     */
    public function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        if (str_contains($path, 'storage/app/public/')) {
            $path = substr($path, strpos($path, 'storage/app/public/') + strlen('storage/app/public/'));
        }

        $path = ltrim($path, '/');

        // Konfigurasi lama ManagementPro: public/helpdesk-ticket-messages/...
        if (str_starts_with($path, 'public/helpdesk-ticket-messages/')) {
            return 'helpdesk-ticket-messages/'.substr($path, strlen('public/helpdesk-ticket-messages/'));
        }

        // Path lama per-ticket: ticket-messages/{id}/file → cari di folder Filament jika ada basename
        if ($this->storePathBase && str_starts_with($path, 'ticket-messages/')) {
            $rest = substr($path, strlen('ticket-messages/'));
            if (str_contains($rest, '/')) {
                return $this->storePathBase.'/'.basename($rest);
            }
        }

        return $path;
    }

    /**
     * Hapus berkas dari storage; folder kosong ikut dibersihkan.
     */
    public function deletePath(?string $path): void
    {
        if (! $path) {
            return;
        }

        $relative = $this->normalizePath($path);

        // Task project: hanya hapus dari disk ManagementPro, jangan sentuh HRIS
        if (str_starts_with($relative, 'hd-project-tasks/')) {
            $this->deleteFromDisk(self::DISK_PUBLIC, $relative);

            return;
        }

        $disk = $this->disk();

        if ($disk->exists($relative)) {
            $disk->delete($relative);
            $this->pruneEmptyDirectories(dirname($relative));

            return;
        }

        if ($this->diskName !== self::DISK_PUBLIC) {
            $publicDisk = Storage::disk(self::DISK_PUBLIC);
            if ($publicDisk->exists($relative)) {
                $publicDisk->delete($relative);
                $this->pruneEmptyDirectories(dirname($relative), self::DISK_PUBLIC);
            }
        }
    }

    protected function deleteFromDisk(string $diskName, string $relative): void
    {
        $disk = Storage::disk($diskName);
        if ($disk->exists($relative)) {
            $disk->delete($relative);
            $this->pruneEmptyDirectories(dirname($relative), $diskName);
        }
    }

    protected function pruneEmptyDirectories(string $directory, ?string $diskName = null): void
    {
        $directory = trim(str_replace('\\', '/', $directory), '/');
        if ($directory === '' || $directory === '.') {
            return;
        }

        if (! $this->isPrunableDirectory($directory)) {
            return;
        }

        $disk = Storage::disk($diskName ?? $this->diskName);
        if (! $disk->exists($directory)) {
            return;
        }

        $fullPath = $disk->path($directory);
        if (! is_dir($fullPath) || ! $this->directoryIsEmpty($fullPath)) {
            return;
        }

        $disk->deleteDirectory($directory);

        $parent = dirname($directory);
        if ($parent !== '.' && $parent !== $directory) {
            $this->pruneEmptyDirectories($parent, $diskName);
        }
    }

    protected function isPrunableDirectory(string $directory): bool
    {
        foreach ($this->prunablePrefixes as $prefix) {
            if (str_starts_with($directory, $prefix) || $directory === rtrim($prefix, '/')) {
                return true;
            }
        }

        return false;
    }

    protected function directoryIsEmpty(string $fullPath): bool
    {
        $items = @scandir($fullPath);
        if ($items === false) {
            return false;
        }

        return count(array_diff($items, ['.', '..'])) === 0;
    }
}
