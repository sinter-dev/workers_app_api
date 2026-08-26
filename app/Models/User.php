<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    /**
     * Attributes that can be mass assigned.
     */
    protected $fillable = [
        'full_name',
        'phone',
        'email',
        'password',
        'role',
        'profile_photo',
        'location',
        'latitude',
        'longitude',
        'is_verified',
        'account_status',
        'account_status_reason',
        'account_status_changed_at',
        'account_status_changed_by',
        'account_status_source',
        'deletion_requested_at',
        'deletion_scheduled_for',
        'deleted_at_app',
    ];
public function sentHiringRequests(): HasMany
{
    return $this->hasMany(
        HiringRequest::class,
        'homeowner_id'
    );
}

public function receivedHiringRequests(): HasMany
{
    return $this->hasMany(
        HiringRequest::class,
        'worker_id'
    );
}
    /**
     * Attributes hidden from JSON responses.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Attribute casting.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_verified' => 'boolean',
            'account_status_changed_at' => 'datetime',
            'deletion_requested_at' => 'datetime',
            'deletion_scheduled_for' => 'datetime',
            'deleted_at_app' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }


    /**
     * Firebase Cloud Messaging devices belonging to this user.
     */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(
            DeviceToken::class,
            'user_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Profiles
    |--------------------------------------------------------------------------
    */

    /**
     * Worker profile belonging to this user.
     */
    public function workerProfile()
    {
        return $this->hasOne(
            WorkerProfile::class,
            'user_id'
        );
    }

    /**
     * Homeowner profile belonging to this user.
     */
    public function homeownerProfile()
    {
        return $this->hasOne(
            HomeownerProfile::class,
            'user_id'
        );
    }

    /**
     * Company profile belonging to this user.
     */
    public function companyProfile()
    {
        return $this->hasOne(
            CompanyProfile::class,
            'user_id'
        );
    }

    /**
     * Agency profile belonging to this user.
     */
    public function agencyProfile()
    {
        return $this->hasOne(
            AgencyProfile::class,
            'user_id'
        );
    }

    /**
     * Workers managed by this agency (only meaningful when
     * role === 'agency').
     */
    public function managedWorkers()
    {
        return $this->hasMany(
            WorkerProfile::class,
            'agency_id'
        );
    }

    /**
     * Quotes this user (worker or company) has submitted on
     * service requests.
     */
    public function serviceQuotes()
    {
        return $this->hasMany(
            ServiceQuote::class,
            'provider_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Jobs
    |--------------------------------------------------------------------------
    */

    /**
     * Jobs posted by this homeowner.
     */
    public function jobsPosted()
    {
        return $this->hasMany(
            Job::class,
            'homeowner_id'
        );
    }

    /**
     * Jobs accepted by this worker.
     */
    public function acceptedJobs()
    {
        return $this->hasMany(
            Job::class,
            'accepted_worker_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Job Applications
    |--------------------------------------------------------------------------
    */

    /**
     * Job applications submitted by this worker.
     */
    public function applications()
    {
        return $this->hasMany(
            JobApplication::class,
            'worker_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Conversations
    |--------------------------------------------------------------------------
    */

    /**
     * Conversations belonging to this homeowner.
     */
    public function homeownerConversations()
    {
        return $this->hasMany(
            Conversation::class,
            'homeowner_id'
        );
    }

    /**
     * Conversations belonging to this worker.
     */
    public function workerConversations()
    {
        return $this->hasMany(
            Conversation::class,
            'worker_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Messages sent by this user.
     */
    public function sentMessages()
    {
        return $this->hasMany(
            Message::class,
            'sender_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reviews
    |--------------------------------------------------------------------------
    */

    /**
     * Reviews received by this worker.
     */
    public function reviews()
    {
        return $this->hasMany(
            Review::class,
            'worker_id'
        );
    }

    /**
     * Reviews submitted by this homeowner.
     */
    public function reviewsGiven()
    {
        return $this->hasMany(
            Review::class,
            'homeowner_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Saved Workers
    |--------------------------------------------------------------------------
    */

    /**
     * Workers saved by this homeowner.
     */
    public function savedWorkers()
    {
        return $this->hasMany(
            SavedWorker::class,
            'homeowner_id'
        );
    }

    /**
     * Homeowners who saved this worker.
     */
    public function savedByHomeowners()
    {
        return $this->hasMany(
            SavedWorker::class,
            'worker_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Payments
    |--------------------------------------------------------------------------
    */

    /**
     * Payments made by this homeowner.
     */
    public function paymentsMade()
    {
        return $this->hasMany(
            Payment::class,
            'homeowner_id'
        );
    }

    /**
     * Payments received by this worker.
     */
    public function paymentsReceived()
    {
        return $this->hasMany(
            Payment::class,
            'worker_id'
        );
    }
}
