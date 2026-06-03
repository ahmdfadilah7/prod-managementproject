<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class HdTicket extends Model
{
  use SoftDeletes;

  protected $table = 'hd_tickets';

  protected $fillable = [
    'companies_id',
    'ticket_number',
    'subject',
    'description',
    'reporter_id',
    'hd_categories_id',
    'hd_sub_categories_id',
    'priority',
    'status',
    'assigned_to',
    'sla_deadline',
    'resolved_at',
    'users_created',
    'users_updated',
    'users_deleted',
  ];

  protected function casts(): array
  {
    return [
      'sla_deadline' => 'datetime',
      'resolved_at' => 'datetime',
    ];
  }

  public function category(): BelongsTo
  {
    return $this->belongsTo(HdCategory::class, 'hd_categories_id');
  }

  public function subCategory(): BelongsTo
  {
    return $this->belongsTo(HdSubCategory::class, 'hd_sub_categories_id');
  }

  public function reporter(): BelongsTo
  {
    return $this->belongsTo(User::class, 'reporter_id');
  }

  public function assignee(): BelongsTo
  {
    return $this->belongsTo(User::class, 'assigned_to');
  }

  public function messages(): HasMany
  {
    return $this->hasMany(HdTicketMessage::class, 'hd_tickets_id');
  }

  public function latestMessage(): HasOne
  {
    return $this->hasOne(HdTicketMessage::class, 'hd_tickets_id')->latestOfMany();
  }

  public function scopeForCategory($query, int $categoryId)
  {
    return $query->where('hd_categories_id', $categoryId);
  }

  /** Hanya tiket yang kategori (hd_categories) belum di-soft-delete. */
  public function scopeInActiveCategory($query)
  {
    return $query->whereHas('category');
  }
}
