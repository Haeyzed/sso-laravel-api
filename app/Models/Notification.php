<?php

namespace App\Models;

use App\Enums\NotificationStatusEnum;
use App\Traits\HasDateFilter;
use App\Traits\HasSqid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Notification extends Model
{
    use HasSqid, HasDateFilter, HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string> $fillable
     */
    protected $fillable = [
        'user_id',
        'device_token',
        'title',
        'body',
        'data',
        'response',
        'status',
        'sent_at'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string> $hidden
     */
    protected $casts = [
        'data' => 'array',
        'response' => 'array',
        'sent_at' => 'datetime',
        'status' => NotificationStatusEnum::class,
    ];

    /**
     * Get the user that owns the Notification.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
