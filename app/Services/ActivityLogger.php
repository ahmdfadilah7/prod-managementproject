<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Project;
use App\Models\User;

class ActivityLogger
{
    public static function log(
        Project $project,
        User $user,
        string $type,
        string $description,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?array $properties = null,
    ): Activity {
        return Activity::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'type' => $type,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'properties' => $properties,
            'description' => $description,
        ]);
    }
}
