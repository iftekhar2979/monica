<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportJob extends Model
{
    use HasFactory;

    protected $table = 'import_jobs';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'account_id',
        'user_id',
        'vault_id',
        'filename',
        'file_path',
        'status',
        'total_rows',
        'processed_rows',
        'successful_rows',
        'failed_rows',
        'failure_message',
        'errors',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'errors' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }
}
