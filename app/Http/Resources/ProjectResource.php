<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'color' => $this->color,
            'status' => $this->status,
            'priority' => $this->priority,
            'progress' => $this->progress,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'owner_id' => $this->owner_id,
            'owner' => new UserResource($this->whenLoaded('owner')),
            'members' => UserResource::collection($this->whenLoaded('members')),
            'is_mine' => $request->user()?->id === $this->owner_id,
            'my_role' => $this->when(
                $request->user() && $this->relationLoaded('members'),
                function () use ($request) {
                    if ($this->owner_id === $request->user()->id) {
                        return 'owner';
                    }

                    $member = $this->members->firstWhere('id', $request->user()->id);

                    return $member?->pivot?->role;
                }
            ),
            'tasks_count' => $this->whenCounted('tasks'),
            'completed_tasks_count' => $this->when(
                isset($this->completed_tasks_count),
                $this->completed_tasks_count
            ),
            'my_tasks_by_status' => $this->my_tasks_by_status ?? null,
            'my_tasks_count' => $this->my_tasks_count ?? 0,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
