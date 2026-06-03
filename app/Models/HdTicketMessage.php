<?php

namespace App\Models;

use App\Services\PublicAttachmentStorage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HdTicketMessage extends Model
{
  protected $table = 'hd_ticket_messages';

  protected $fillable = [
    'hd_tickets_id',
    'users_id',
    'message',
    'attachment',
  ];

  public function ticket(): BelongsTo
  {
    return $this->belongsTo(HdTicket::class, 'hd_tickets_id');
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class, 'users_id');
  }

  protected static function booted(): void
  {
    static::deleting(function (HdTicketMessage $message) {
      PublicAttachmentStorage::forHelpdeskTickets()->deletePath($message->attachment);
    });
  }
}
