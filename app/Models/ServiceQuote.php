<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceQuote extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_request_id',
        'provider_id',
        'amount',
        'estimated_duration',
        'message',
        'status',
        'responded_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'responded_at' => 'datetime',
    ];

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }
}
