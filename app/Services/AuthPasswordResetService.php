<?php

namespace App\Services;

use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthPasswordResetService
{
    public function findUserByIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        if (User::usesHrisSchema()) {
            return User::query()
                ->where('nik', $identifier)
                ->orWhere('email_kantor', $identifier)
                ->orWhere('email_pribadi', $identifier)
                ->first();
        }

        return User::query()->where('email', $identifier)->first();
    }

    public function resolvePasswordResetEmail(User $user): ?string
    {
        if (User::usesHrisSchema()) {
            return $user->getAttributes()['email_kantor']
                ?? $user->getAttributes()['email_pribadi']
                ?? null;
        }

        return $user->getAttributes()['email'] ?? null;
    }

    public function findUserByResetEmail(string $email): ?User
    {
        if (User::usesHrisSchema()) {
            return User::query()
                ->where('email_kantor', $email)
                ->orWhere('email_pribadi', $email)
                ->first();
        }

        return User::query()->where('email', $email)->first();
    }

    /**
     * @return array{sent: bool, message: string, status: int}
     */
    public function requestReset(string $identifier): array
    {
        $user = $this->findUserByIdentifier($identifier);

        if (! $user) {
            return $this->genericSuccessResponse();
        }

        if (! $user->is_active) {
            return $this->genericSuccessResponse();
        }

        $email = $this->resolvePasswordResetEmail($user);

        if (! $email) {
            return [
                'sent' => false,
                'message' => 'Akun ditemukan tetapi belum memiliki email terdaftar. Hubungi administrator.',
                'status' => 422,
            ];
        }

        $plainToken = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => Hash::make($plainToken),
                'created_at' => now(),
            ]
        );

        $resetUrl = rtrim((string) config('managementpro.frontend_url'), '/')
            .'/reset-password?token='.urlencode($plainToken)
            .'&email='.urlencode($email);

        $this->dispatchResetEmail($email, $user->name, $resetUrl);

        return $this->genericSuccessResponse();
    }

    public function resetPassword(string $email, string $token, string $password): void
    {
        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (! $record || ! Hash::check($token, $record->token)) {
            throw ValidationException::withMessages([
                'token' => ['Token reset password tidak valid atau sudah kedaluwarsa.'],
            ]);
        }

        $expireMinutes = (int) config('auth.passwords.users.expire', 60);
        $createdAt = $record->created_at ? Carbon::parse($record->created_at) : null;

        if (! $createdAt || $createdAt->addMinutes($expireMinutes)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            throw ValidationException::withMessages([
                'token' => ['Token reset password sudah kedaluwarsa. Silakan minta link baru.'],
            ]);
        }

        $user = $this->findUserByResetEmail($email);

        if (! $user || ! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Akun tidak ditemukan.'],
            ]);
        }

        $user->password = $password;
        $user->save();

        DB::table('password_reset_tokens')->where('email', $email)->delete();
    }

    protected function dispatchResetEmail(string $email, string $userName, string $resetUrl): void
    {
        if (config('managementpro.password_reset.send_mail')) {
            Mail::to($email)->send(new ResetPasswordMail($resetUrl, $userName));

            return;
        }

        Log::channel(config('managementpro.password_reset.log_channel', 'stack'))
            ->info('Password reset email (pengiriman dinonaktifkan — set PASSWORD_RESET_SEND_MAIL=true setelah SMTP siap)', [
                'to' => $email,
                'reset_url' => $resetUrl,
            ]);
    }

    /**
     * @return array{sent: bool, message: string, status: int}
     */
    protected function genericSuccessResponse(): array
    {
        return [
            'sent' => true,
            'message' => 'Jika akun terdaftar dan memiliki email, instruksi reset password telah dikirim.',
            'status' => 200,
        ];
    }
}
