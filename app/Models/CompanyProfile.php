<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CompanyProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'company_name',
        'logo',
        'description',
        'business_registration_number',
        'address',
        'district',
        'latitude',
        'longitude',
        'profile_completed',
    ];

    protected $casts = [
        'profile_completed' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    /**
     * Company profile belongs to one user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Service categories this company offers.
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(
            ServiceCategory::class,
            'company_services'
        )->withTimestamps();
    }
}
