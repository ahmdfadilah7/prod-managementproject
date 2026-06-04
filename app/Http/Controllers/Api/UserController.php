<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->toString();
        $defaultLimit = config('managementpro.hris_mode') ? 100 : 20;
        $limit = min(200, max(5, $request->integer('limit', $defaultLimit)));

        $query = User::query()->with(User::authEagerLoads());

        if (config('managementpro.hris_mode')) {
            $query->where('status', 'active');

            if ($search !== '') {
                $query->where(function ($inner) use ($search) {
                    $inner->where('email_kantor', 'like', "%{$search}%")
                        ->orWhere('email_pribadi', 'like', "%{$search}%")
                        ->orWhere('nik', 'like', "%{$search}%")
                        ->orWhereHas('employee', fn ($e) => $e->where('nama_lengkap', 'like', "%{$search}%"));
                });
            }
        } else {
            $query->where('is_active', true);

            if ($search !== '') {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('job_title', 'like', "%{$search}%");
                });
            }
        }

        $users = $query->orderBy('id')->limit($limit)->get();

        return response()->json(['data' => UserResource::collection($users)]);
    }
}
