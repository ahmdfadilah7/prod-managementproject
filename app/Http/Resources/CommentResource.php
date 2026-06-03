<?php

namespace App\Http\Resources;

use App\Support\AppDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'user' => new UserResource($this->whenLoaded('user')),
            'created_at' => AppDateTime::toIso($this->created_at),
        ];
    }
}
