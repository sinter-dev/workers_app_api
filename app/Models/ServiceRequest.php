<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'homeowner_id',
        'service_category_id',
        'provider_id',
        'title',
        'description',
        'address',
        'district',
        'latitude',
        'longitude',
        'preferred_date',
        'preferred_time',
        'status',
        'booked_at',
        'started_at',
        'completion_requested_at',
        'completed_at',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'cancellation_note',
    ];

    protected $casts = [
        'preferred_date' => 'date',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'booked_at' => 'datetime',
        'started_at' => 'datetime',
        'completion_requested_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function homeowner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'homeowner_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(ServiceQuote::class);
    }
}
