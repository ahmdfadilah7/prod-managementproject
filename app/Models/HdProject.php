<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HdProject extends Model
{
    use SoftDeletes;

    protected $table = 'hd_projects';

    protected $fillable = [
        'companies_id',
        'hd_sub_categories_id',
        'project_number',
        'subject',
        'description',
        'reporter_id',
        'priority',
        'status',
        'start_date',
        'end_date',
        'position',
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

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'companies_id');
    }

    public function subCategory(): BelongsTo
    {
        return $this->belongsTo(HdSubCategory::class, 'hd_sub_categories_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'users_created');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'users_updated');
    }

    /** Satu project dapat ditugaskan ke banyak user. */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'hd_project_user', 'hd_project_id', 'user_id')
            ->withTimestamps();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(HdProjectTask::class, 'hd_projects_id');
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('companies_id', $companyId);
    }

    public function scopeForSubCategory($query, int $subCategoryId)
    {
        return $query->where('hd_sub_categories_id', $subCategoryId);
    }

    /** Sub-kategori & induk kategorinya masih ada. */
    public function scopeInActiveSubCategory($query)
    {
        return $query->whereHas('subCategory', fn ($q) => $q->whereHas('category'));
    }
}
