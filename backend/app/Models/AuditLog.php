<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id', 'action', 'subject_type', 'subject_id', 'changes', 'ip',
    ];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
