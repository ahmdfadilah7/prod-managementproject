<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

class AppDateTime
{
    /** Penyimpanan & logika aplikasi (Laravel config app.timezone). */
    public const STORAGE_TZ = 'UTC';

    protected static ?string $displayTimezone = null;

    public static function setDisplayTimezone(?string $timezone): void
    {
        if ($timezone !== null && $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            self::$displayTimezone = $timezone;

            return;
        }

        self::$displayTimezone = null;
    }

    /**
     * Zona waktu tampilan: cabang karyawan (user) atau fallback config.
     */
    public static function displayTimezone(): string
    {
        return self::$displayTimezone
            ?? (string) config('managementpro.fallback_display_timezone', 'UTC');
    }

    public static function now(): Carbon
    {
        return Carbon::now(self::STORAGE_TZ);
    }

    /**
     * Konversi ke UTC untuk disimpan di database.
     */
    public static function toStorage(?CarbonInterface $dateTime): ?Carbon
    {
        if (! $dateTime) {
            return null;
        }

        return $dateTime->copy()->utc();
    }

    /**
     * Format ISO untuk API / frontend (dari UTC → zona tampilan user).
     */
    public static function toIso(?CarbonInterface $dateTime): ?string
    {
        if (! $dateTime) {
            return null;
        }

        return $dateTime->copy()->utc()->timezone(self::displayTimezone())->toIso8601String();
    }

    public static function toDateString(?CarbonInterface $dateTime): ?string
    {
        if (! $dateTime) {
            return null;
        }

        return $dateTime->copy()->utc()->timezone(self::displayTimezone())->format('Y-m-d');
    }

    /**
     * Untuk input HTML datetime-local (wall clock di zona cabang user).
     */
    public static function toDatetimeLocalValue(?CarbonInterface $dateTime): ?string
    {
        if (! $dateTime) {
            return null;
        }

        return $dateTime->copy()->utc()->timezone(self::displayTimezone())->format('Y-m-d\TH:i');
    }

    /**
     * Parse nilai date (Y-m-d) atau datetime-local / ISO → Carbon UTC untuk DB.
     * Input tanpa offset dianggap wall clock di zona tampilan user.
     */
    public static function parseDueInput(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = trim($value);
        $displayTz = self::displayTimezone();

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return Carbon::parse($value.' 23:59:59', $displayTz)->utc();
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
            return Carbon::parse($value, $displayTz)->utc();
        }

        return Carbon::parse($value)->utc();
    }
}
