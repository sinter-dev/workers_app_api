<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgencyProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'agency_name',
        'logo',
        'description',
        'business_registration_number',
        'specialty',
        'address',
        'district',
        'latitude',
        'longitude',
        'profile_completed',
        'verification_status',
        'verification_rejection_reason',
        'verification_submitted_at',
        'verification_reviewed_at',
        'verification_reviewed_by',
    ];

    protected $casts = [
        'profile_completed' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'verification_submitted_at' => 'datetime',
        'verification_reviewed_at' => 'datetime',
    ];

    /**
     * Agency profile belongs to one user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Workers currently managed by this agency.
     */
    public function workers(): HasMany
    {
        return $this->hasMany(
            WorkerProfile::class,
            'agency_id',
            'user_id'
        );
    }
}
