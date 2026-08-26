<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkerProfile extends Model
{
    use HasFactory;

    protected $fillable = [

        'user_id',
        'agency_id',

        // Personal Information
        'age',
        'religion',
        'gender',
        'district',
        'work_type',
        'languages',

        // Verification
        'national_id_front_document',
        'national_id_back_document',
        'profile_photo',
        'profile_completed',

        // Profile
        'bio',
        'experience_years',
        'hourly_rate',
        'monthly_rate',
        'availability',

        // Statistics
        'rating',
        'total_reviews',
        'jobs_completed',

        // Verification Status
        'background_checked',
        'police_clearance',
        'medical_clearance',
        'identity_verified',
        'verification_status',
        'verification_rejection_reason',
        'verification_submitted_at',
        'verification_reviewed_at',
        'verification_reviewed_by',

        // Account Status
        'featured',
        'active',
    ];

    protected $casts = [

        'profile_completed' => 'boolean',
        'languages' => 'array',

        'background_checked' => 'boolean',
        'police_clearance' => 'boolean',
        'medical_clearance' => 'boolean',
        'identity_verified' => 'boolean',
        'verification_submitted_at' => 'datetime',
        'verification_reviewed_at' => 'datetime',

        'featured' => 'boolean',
        'active' => 'boolean',

        'hourly_rate' => 'decimal:2',
        'monthly_rate' => 'decimal:2',
        'rating' => 'decimal:2',
    ];

    /**
     * Worker belongs to one user.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The agency managing this worker, if any. Null means
     * the worker is independent.
     */
    public function agency()
    {
        return $this->belongsTo(User::class, 'agency_id');
    }

    /**
     * Gallery Images.
     */
    public function galleryImages()
    {
        return $this->hasMany(
            WorkerGalleryImage::class
        )->orderBy('position');
    }

    /**
     * Pivot records.
     */
    public function workerServices()
    {
        return $this->hasMany(
            WorkerService::class,
            'worker_profile_id'
        );
    }

    /**
     * Services offered by this worker.
     */
    public function services()
    {
        return $this->belongsToMany(
            ServiceCategory::class,
            'worker_services',
            'worker_profile_id',
            'service_category_id'
        )->withTimestamps();
    }

    /**
     * Reviews received by this worker.
     */
    public function reviews()
    {
        return $this->hasMany(
            Review::class,
            'worker_id',
            'user_id'
        );
    }
}
