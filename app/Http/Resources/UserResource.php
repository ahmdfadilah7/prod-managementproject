<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'nik' => $this->when(User::usesHrisSchema(), $this->resource->getAttribute('nik')),
            'email_kantor' => $this->when(User::usesHrisSchema(), $this->resource->getAttribute('email_kantor')),
            'email_pribadi' => $this->when(User::usesHrisSchema(), $this->resource->getAttribute('email_pribadi')),
            'nomor_hp' => $this->when(
                User::usesHrisSchema() && $this->relationLoaded('employee'),
                fn () => $this->employee?->nomor_hp
            ),
            'alamat_current' => $this->when(
                User::usesHrisSchema() && $this->relationLoaded('employee'),
                fn () => $this->employee?->alamat_current
            ),
            'avatar' => $this->avatar,
            'job_title' => $this->job_title,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'last_login_at' => $this->formatDateTimeForUser($this->last_login_at),
            'initials' => collect(explode(' ', $this->name))
                ->map(fn ($w) => strtoupper(substr($w, 0, 1)))
                ->take(2)
                ->join(''),
            'roles' => $this->when(
                User::usesHrisSchema(),
                fn () => $this->getHrisRolesForApi(),
                RoleResource::collection($this->whenLoaded('roles'))
            ),
            'primary_role' => $this->when(
                User::usesHrisSchema(),
                fn () => ($roles = $this->getHrisRolesForApi())[0] ?? null,
                $this->when(
                    $this->relationLoaded('roles'),
                    fn () => $this->primaryRole() ? new RoleResource($this->primaryRole()) : null
                )
            ),
            'permissions' => $this->when(
                $this->shouldExposePermissions($request),
                fn () => $this->getPermissionSlugs()
            ),
            'timezone' => $this->displayTimezone(),
            'created_at' => $this->formatDateTimeForUser($this->created_at),
        ];
    }

    private function formatDateTimeForUser($dateTime): ?string
    {
        if (! $dateTime) {
            return null;
        }

        return $dateTime->copy()->utc()->timezone($this->resource->displayTimezone())->toIso8601String();
    }

    private function shouldExposePermissions(Request $request): bool
    {
        $viewer = $request->user();

        if ($viewer?->id === $this->id) {
            return true;
        }

        if ($viewer?->hasPermission('users.view')) {
            return true;
        }

        $action = $request->route()?->getActionName() ?? '';

        return str_ends_with($action, '@login')
            || str_ends_with($action, '@register');
    }
}
