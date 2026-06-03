<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
  protected $fillable = [
    'companies_id',
    'branches_id',
    'nama_lengkap',
    'positions_id',
    'nomor_hp',
    'alamat_current',
  ];

  public function company(): BelongsTo
  {
    return $this->belongsTo(Company::class, 'companies_id');
  }

  public function branch(): BelongsTo
  {
    return $this->belongsTo(Branch::class, 'branches_id');
  }

  public function position(): BelongsTo
  {
    return $this->belongsTo(Position::class, 'positions_id');
  }
}
