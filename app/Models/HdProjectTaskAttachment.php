<?php

namespace App\Models;

use App\Services\PublicAttachmentStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HdProjectTaskAttachment extends Model
{
    protected $table = 'hd_project_task_attachments';

    protected $fillable = [
        'hd_project_tasks_id',
        'path',
        'original_name',
        'description',
        'users_created',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(HdProjectTask::class, 'hd_project_tasks_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'users_created');
    }

    protected static function booted(): void
    {
        static::deleting(function (HdProjectTaskAttachment $attachment) {
            if ($attachment->path) {
                PublicAttachmentStorage::forProjectTasks()->deletePath($attachment->path);
            }
        });
    }
}
