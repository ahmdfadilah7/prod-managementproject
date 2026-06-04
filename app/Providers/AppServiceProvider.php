<?php

namespace App\Providers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->syncHrisStorageDisk();
        $this->ensurePublicStorageSymlink();
    }

    /**
     * Pastikan disk hris_storage memakai root/URL dari managementpro (sumber .env).
     */
    protected function syncHrisStorageDisk(): void
    {
        if (! config('managementpro.hris_mode')) {
            return;
        }

        $root = trim((string) config('managementpro.hris_storage.root', ''));
        if ($root === '') {
            return;
        }

        config(['filesystems.disks.hris_storage.root' => $root]);

        $url = rtrim((string) config('managementpro.hris_storage.url'), '/');
        if ($url !== '') {
            $prefix = trim((string) config('managementpro.hris_storage.url_prefix', '/storage'), '/');
            config(['filesystems.disks.hris_storage.url' => $url.'/'.$prefix]);
        }
    }

    /**
     * Lampiran disk public diakses lewat public/storage → storage/app/public.
     */
    protected function ensurePublicStorageSymlink(): void
    {
        $link = public_path('storage');
        if (file_exists($link)) {
            return;
        }

        try {
            Artisan::call('storage:link');
        } catch (\Throwable $e) {
            if ($this->app->environment('local')) {
                logger()->warning('storage:link gagal dijalankan otomatis: '.$e->getMessage());
            }
        }
    }
}
