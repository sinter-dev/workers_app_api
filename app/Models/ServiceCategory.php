<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class ServiceCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'icon',
        'image',
        'description',
        'active',
        'transaction_type',
        'parent_id',
        'display_order',
    ];

    protected $casts = [
        'active' => 'boolean',
        'display_order' => 'integer',
    ];

    public function workerServices(): HasMany
    {
        return $this->hasMany(WorkerService::class);
    }

    /**
     * The marketplace category (top-level group) this service
     * belongs under. Null means this row is itself a top-level
     * group, not a selectable service.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'parent_id');
    }

    /**
     * Leaf service categories grouped under this one (only
     * meaningful when this row is a top-level group).
     */
    public function children(): HasMany
    {
        return $this->hasMany(ServiceCategory::class, 'parent_id');
    }

    /**
     * Whether this category is currently referenced anywhere
     * and therefore not safe to delete. Covers every place in
     * the schema that points at a service category.
     */
    public function isInUse(): bool
    {
        if ($this->children()->exists()) {
            return true;
        }

        return DB::table('worker_services')->where('service_category_id', $this->id)->exists()
            || DB::table('company_services')->where('service_category_id', $this->id)->exists()
            || DB::table('job_service_category')->where('service_category_id', $this->id)->exists()
            || DB::table('work_wanted_services')->where('service_category_id', $this->id)->exists()
            || DB::table('service_requests')->where('service_category_id', $this->id)->exists();
    }
}
