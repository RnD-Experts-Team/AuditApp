<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\Entity;
use App\Models\Rating;
use App\Models\Store;

class CameraFormTablesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Seed Categories and store them in variables
        $lobby = Category::firstOrCreate(
            ['label' => 'Lobby']
        );

        $walkIn = Category::firstOrCreate(
            ['label' => 'Walk-in']
        );

        $driveThru = Category::firstOrCreate(
            ['label' => 'Drive Thru']
        );

        $landing = Category::firstOrCreate(
            ['label' => 'Landing']
        );

        $making = Category::firstOrCreate(
            ['label' => 'Making']
        );

        $mgmtSw = Category::firstOrCreate(
            ['label' => 'MGMT SW']
        );

        $staffAlignment = Category::firstOrCreate(
            ['label' => 'Staff Alignment']
        );

        // Seed Ratings
        $ratings = [
            ['label' => 'Pass'],
            ['label' => 'Fail'],
            ['label' => 'Not Done'],
            ['label' => 'Camera failure'],
            ['label' => 'Auto Fail'],
        ];

        foreach ($ratings as $rating) {
            Rating::firstOrCreate($rating);
        }

        // Seed Stores
        $stores = [
            ['store' => '03795-00001', 'group' => 1],
            ['store' => '03795-00002', 'group' => 1],
            ['store' => '03795-00003', 'group' => 1],
            ['store' => '03795-00004', 'group' => 1],
            ['store' => '03795-00005', 'group' => 1],
            ['store' => '03795-00006', 'group' => 1],
            ['store' => '03795-00007', 'group' => 1],
            ['store' => '03795-00008', 'group' => 1],
            ['store' => '03795-00009', 'group' => 1],
            ['store' => '03795-00010', 'group' => 1],
            ['store' => '03795-00011', 'group' => 2],
            ['store' => '03795-00012', 'group' => 2],
            ['store' => '03795-00013', 'group' => 2],
            ['store' => '03795-00014', 'group' => 2],
            ['store' => '03795-00015', 'group' => 2],
            ['store' => '03795-00016', 'group' => 2],
            ['store' => '03795-00017', 'group' => 2],
            ['store' => '03795-00018', 'group' => 2],
            ['store' => '03795-00019', 'group' => 2],
            ['store' => '03795-00020', 'group' => 2],
            ['store' => '03795-00021', 'group' => 2],
            ['store' => '03795-00022', 'group' => 3],
            ['store' => '03795-00023', 'group' => 3],
            ['store' => '03795-00024', 'group' => 3],
            ['store' => '03795-00025', 'group' => 3],
            ['store' => '03795-00026', 'group' => 3],
            ['store' => '03795-00027', 'group' => 3],
            ['store' => '03795-00028', 'group' => 3],
            ['store' => '03795-00029', 'group' => 3],
            ['store' => '03795-00030', 'group' => 3],
            ['store' => '03795-00031', 'group' => 3],

        ];

        foreach ($stores as $store) {
            Store::firstOrCreate(['store' => $store['store']], $store);
        }

        // Seed Entities using dynamic category references
        $entities = [
            // Safety & Security - Daily Main
            [
                'entity_label' => 'Emergency exits clear and accessible',
                'category_id' => $lobby->id,
                'date_range_type' => 'daily',
                'report_type' => 'main',
            ],
            [
                'entity_label' => 'Fire extinguishers inspected',
                'category_id' => $walkIn->id,
                'date_range_type' => 'daily',
                'report_type' => 'main',
            ],
            [
                'entity_label' => 'Security cameras operational',
                'category_id' => $driveThru->id,
                'date_range_type' => 'daily',
                'report_type' => 'main',
            ],

            // Safety & Security - Weekly Secondary
            [
                'entity_label' => 'First aid kit stocked',
                'category_id' => $landing->id,
                'date_range_type' => 'weekly',
                'report_type' => 'secondary',
            ],
            [
                'entity_label' => 'Safety signage visible',
                'category_id' => $making->id,
                'date_range_type' => 'weekly',
                'report_type' => 'secondary',
            ],

            // Cleanliness - Daily Main
            [
                'entity_label' => 'Floors swept and mopped',
                'category_id' => $mgmtSw->id,
                'date_range_type' => 'daily',
                'report_type' => 'main',
            ],
            [
                'entity_label' => 'Restrooms cleaned and sanitized',
                'category_id' => $staffAlignment->id,
                'date_range_type' => 'daily',
                'report_type' => 'main',
            ]
        ];

        foreach ($entities as $entity) {
            Entity::firstOrCreate(
                ['entity_label' => $entity['entity_label']],
                $entity
            );
        }

        $this->command->info('✅ Categories, Entities, Ratings, and Stores seeded successfully!');
    }
}
