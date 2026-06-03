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
        $this->ensurePublicStorageSymlink();
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
