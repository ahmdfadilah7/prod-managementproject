<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (config('managementpro.hris_mode')) {
            $validated = $request->validate([
                'email_kantor' => ['nullable', 'email', 'max:255'],
                'email_pribadi' => ['nullable', 'email', 'max:255'],
                'avatar' => ['nullable', 'string', 'max:255'],
                'nomor_hp' => ['nullable', 'string', 'max:20'],
                'alamat_current' => ['nullable', 'string', 'max:2000'],
                'current_password' => ['nullable', 'required_with:password', 'string'],
                'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            ]);

            if (! empty($validated['password'])) {
                if (! Hash::check($validated['current_password'] ?? '', $user->password)) {
                    throw ValidationException::withMessages([
                        'current_password' => ['Password saat ini tidak sesuai.'],
                    ]);
                }
                $user->password = $validated['password'];
            }

            $user->fill(collect($validated)->only(['email_kantor', 'email_pribadi', 'avatar'])->filter()->all());
            $user->save();

            if ($user->employee && ($validated['nomor_hp'] ?? $validated['alamat_current'] ?? null)) {
                $user->employee->update(collect($validated)->only(['nomor_hp', 'alamat_current'])->filter()->all());
            }
        } else {
            $validated = $request->validate([
                'name' => ['sometimes', 'string', 'max:255'],
                'email' => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
                'job_title' => ['nullable', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'avatar' => ['nullable', 'string', 'max:255'],
                'current_password' => ['nullable', 'required_with:password', 'string'],
                'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            ]);

            if (! empty($validated['password'])) {
                if (! Hash::check($validated['current_password'] ?? '', $user->password)) {
                    throw ValidationException::withMessages([
                        'current_password' => ['Password saat ini tidak sesuai.'],
                    ]);
                }
                $user->password = $validated['password'];
            }

            $user->fill(collect($validated)->only(['name', 'email', 'job_title', 'phone', 'avatar'])->filter()->all());
            $user->save();
        }

        return response()->json([
            'user' => new UserResource($user->fresh(User::authEagerLoads())),
            'message' => 'Profil berhasil diperbarui.',
        ]);
    }
}
