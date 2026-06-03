<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthPasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected AuthPasswordResetService $passwordResetService,
    ) {}
    public function register(Request $request): JsonResponse
    {
        if (config('managementpro.hris_mode')) {
            abort(403, 'Registrasi dinonaktifkan. Gunakan akun HRIS yang sudah ada.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'job_title' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'job_title' => $validated['job_title'] ?? null,
            'is_active' => true,
        ]);

        $user->assignRole('member', true);

        $token = $user->createToken('auth')->plainTextToken;

        return response()->json([
            'user' => new UserResource($user->load('roles')),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $hrisMode = config('managementpro.hris_mode');
        $credentialKey = $hrisMode ? 'nik' : 'email';

        $validated = $request->validate(array_merge(
            $hrisMode
                ? [
                    'nik' => ['required', 'string', 'max:10'],
                    'password' => ['required', 'string'],
                ]
                : [
                    'email' => ['required', 'email'],
                    'password' => ['required', 'string'],
                ],
            [
                'remember' => ['sometimes', 'boolean'],
            ]
        ));

        $remember = $request->boolean('remember');

        if ($hrisMode) {
            $user = User::with(User::authEagerLoads())
                ->where('nik', $validated['nik'])
                ->first();
        } else {
            $user = User::with('roles')->where('email', $validated['email'])->first();
        }

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                $credentialKey => ['Kredensial tidak valid.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                $credentialKey => ['Akun Anda dinonaktifkan. Hubungi administrator.'],
            ]);
        }

        $user->update(['last_login_at' => now()]);

        $expiresAt = $remember
            ? now()->addDays((int) config('managementpro.auth.remember_token_days', 30))
            : now()->addHours((int) config('managementpro.auth.session_token_hours', 8));

        $accessToken = $user->createToken('auth', ['*'], $expiresAt);

        return response()->json([
            'user' => new UserResource($user->fresh(User::authEagerLoads())),
            'token' => $accessToken->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'remember' => $remember,
            'hris_mode' => config('managementpro.hris_mode'),
        ]);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
        ]);

        $result = $this->passwordResetService->requestReset($validated['identifier']);

        return response()->json([
            'message' => $result['message'],
        ], $result['status']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $this->passwordResetService->resetPassword(
            $validated['email'],
            $validated['token'],
            $validated['password'],
        );

        return response()->json([
            'message' => 'Password berhasil diubah. Silakan masuk dengan password baru.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()->load(User::authEagerLoads())),
            'hris_mode' => config('managementpro.hris_mode'),
        ]);
    }
}
