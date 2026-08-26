<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

class ServiceCategorySeeder extends Seeder
{
    /**
     * Seed the application's service categories.
     *
     * This seeder is safe to run repeatedly (it uses
     * updateOrCreate keyed on slug), so it runs automatically
     * on every deploy. Existing categories are never removed,
     * and their transaction_type is never changed here — only
     * parent_id (grouping) is applied to them, which is purely
     * organizational and does not affect existing behavior.
     */
    public function run(): void
    {
        // -----------------------------------------------------
        // Groups — organizational only, not directly selectable
        // as a service. Every existing and new leaf category
        // below belongs under one of these.
        // -----------------------------------------------------

        $groups = [
            'domestic-household' => 'Domestic & Household',
            'drivers' => 'Drivers',
            'food-hospitality' => 'Food & Hospitality',
            'outdoor-property-care' => 'Outdoor & Property Care',
            'home-repairs-maintenance' => 'Home Repairs & Maintenance',
            'cleaning-services' => 'Cleaning Services',
        ];

        $groupIds = [];

        foreach ($groups as $slug => $name) {
            $group = ServiceCategory::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'icon' => null,
                    'description' => null,
                    'active' => true,
                    'transaction_type' => 'employment',
                    'parent_id' => null,
                ]
            );

            $groupIds[$slug] = $group->id;
        }

        // -----------------------------------------------------
        // Existing categories (already live) — grouped only.
        // transaction_type deliberately left as 'employment',
        // matching what they already are. Nothing about how
        // these behave today changes.
        // -----------------------------------------------------

        $existing = [
            ['slug' => 'house-cleaning', 'parent' => 'domestic-household'],
            ['slug' => 'laundry', 'parent' => 'domestic-household'],
            ['slug' => 'babysitting', 'parent' => 'domestic-household'],
            ['slug' => 'cooking', 'parent' => 'food-hospitality'],
            ['slug' => 'housekeeping', 'parent' => 'domestic-household'],
            ['slug' => 'elderly-care', 'parent' => 'domestic-household'],
            ['slug' => 'gardening', 'parent' => 'outdoor-property-care'],
            ['slug' => 'security-guard', 'parent' => 'outdoor-property-care'],
            ['slug' => 'driver', 'parent' => 'drivers'],
            ['slug' => 'office-cleaning', 'parent' => 'domestic-household'],
            ['slug' => 'hotel-housekeeping', 'parent' => 'domestic-household'],
            ['slug' => 'caregiver', 'parent' => 'domestic-household'],
            ['slug' => 'nanny', 'parent' => 'domestic-household'],
            ['slug' => 'farm-work', 'parent' => 'outdoor-property-care'],
            ['slug' => 'pet-care', 'parent' => 'domestic-household'],
        ];

        foreach ($existing as $row) {
            ServiceCategory::query()
                ->where('slug', $row['slug'])
                ->update([
                    'parent_id' => $groupIds[$row['parent']],
                ]);
        }

        // -----------------------------------------------------
        // New categories — the "book a service" side. These are
        // things you book for a task, not hire as ongoing staff.
        // Both individual workers (e.g. an independent plumber)
        // and companies (e.g. a plumbing company) can offer
        // these — the category doesn't care which.
        // -----------------------------------------------------

        $newOnDemand = [
            [
                'name' => 'Home Cleaning Service',
                'slug' => 'home-cleaning-service',
                'icon' => 'cleaning_services',
                'description' => 'A one-time or occasional home clean, booked as needed rather than an ongoing hire.',
                'parent' => 'cleaning-services',
            ],
            [
                'name' => 'Plumbing',
                'slug' => 'plumbing',
                'icon' => 'plumbing',
                'description' => 'Fixing leaks, pipes, taps, toilets and other plumbing problems.',
                'parent' => 'home-repairs-maintenance',
            ],
            [
                'name' => 'Electrical',
                'slug' => 'electrical',
                'icon' => 'electrical_services',
                'description' => 'Wiring, sockets, lighting and other electrical repairs.',
                'parent' => 'home-repairs-maintenance',
            ],
            [
                'name' => 'Carpentry',
                'slug' => 'carpentry',
                'icon' => 'carpenter',
                'description' => 'Furniture repair, woodwork and general carpentry.',
                'parent' => 'home-repairs-maintenance',
            ],
            [
                'name' => 'Painting',
                'slug' => 'painting',
                'icon' => 'format_paint',
                'description' => 'Painting walls, gates, and other surfaces.',
                'parent' => 'home-repairs-maintenance',
            ],
            [
                'name' => 'Pest Control',
                'slug' => 'pest-control',
                'icon' => 'pest_control',
                'description' => 'Removing insects, rodents and other pests from a home.',
                'parent' => 'home-repairs-maintenance',
            ],
            [
                'name' => 'Handyman Services',
                'slug' => 'handyman-services',
                'icon' => 'handyman',
                'description' => 'General small repairs and odd jobs around the home.',
                'parent' => 'home-repairs-maintenance',
            ],
        ];

        foreach ($newOnDemand as $category) {
            ServiceCategory::query()->updateOrCreate(
                ['slug' => $category['slug']],
                [
                    'name' => $category['name'],
                    'icon' => $category['icon'],
                    'description' => $category['description'],
                    'active' => true,
                    'transaction_type' => 'on_demand_service',
                    'parent_id' => $groupIds[$category['parent']],
                ]
            );
        }
    }
}