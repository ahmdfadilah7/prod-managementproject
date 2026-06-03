<?php

namespace App\Http\Resources;

use App\Support\AppDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'priority' => $this->priority,
            'position' => $this->position,
            'story_points' => $this->story_points,
            'estimated_hours' => $this->estimated_hours,
            'logged_hours' => $this->logged_hours,
            'due_date' => AppDateTime::toDateString($this->due_date),
            'due_at' => $this->due_date
                ? AppDateTime::toIso(AppDateTime::parseDueInput(AppDateTime::toDateString($this->due_date)))
                : null,
            'completed_at' => AppDateTime::toIso($this->completed_at),
            'assignee' => new UserResource($this->whenLoaded('assignee')),
            'creator' => new UserResource($this->whenLoaded('creator')),
            'labels' => LabelResource::collection($this->whenLoaded('labels')),
            'comments' => CommentResource::collection($this->whenLoaded('comments')),
            'comments_count' => $this->whenCounted('comments'),
            'created_at' => AppDateTime::toIso($this->created_at),
            'updated_at' => AppDateTime::toIso($this->updated_at),
        ];
    }
}
