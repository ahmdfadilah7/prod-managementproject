<?php

namespace App\Models;

use App\Services\HdProjectTaskAttachmentService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HdProjectTask extends Model
{
    use SoftDeletes;

    protected $table = 'hd_project_tasks';

    protected $fillable = [
        'hd_projects_id',
        'companies_id',
        'task_number',
        'subject',
        'description',
        'reporter_id',
        'assigned_to',
        'priority',
        'status',
        'position',
        'start_date',
        'end_date',
        'users_created',
        'users_updated',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'datetime',
            'end_date' => 'datetime',
            'position' => 'integer',
        ];
    }

    public function hdProject(): BelongsTo
    {
        return $this->belongsTo(HdProject::class, 'hd_projects_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'companies_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(HdProjectTaskAttachment::class, 'hd_project_tasks_id');
    }

    public function scopeForProject($query, int $projectId)
    {
        return $query->where('hd_projects_id', $projectId);
    }

    /** Task dengan rentang tanggal yang bersinggungan dengan periode [from, to]. */
    public function scopeOverlappingPeriod(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query
            ->whereNotNull('start_date')
            ->where('start_date', '<=', $to)
            ->where(function (Builder $q) use ($from) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $from);
            });
    }

    protected static function booted(): void
    {
        static::forceDeleting(function (HdProjectTask $task) {
            app(HdProjectTaskAttachmentService::class)->deleteAllForTask($task);
        });
    }
}
