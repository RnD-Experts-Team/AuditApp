<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Rating;
use App\Models\Store;
use App\Models\Entity;
use Illuminate\Database\Seeder;

class InitialDataSeeder extends Seeder
{
    public function run(): void
    {
        // Seed Categories
        $categories = [
            ['label' => 'Lobby'],
            ['label' => 'Walk-in'],
            ['label' => 'Drive-Thru'],
            ['label' => 'Landing'],
            ['label' => 'Making'],
            ['label' => 'MGMT-SW'],
            ['label' => 'Staff-Alignment'],
        ];
        Category::insert($categories);

        // Seed Ratings
        $ratings = [
            ['label' => 'Pass'],
            ['label' => 'Fail'],
            ['label' => 'Not Done'],
            ['label' => 'Camera failure'],
            ['label' => 'Auto Fail'],
            ['label' => 'Urgent'],
        ];
        Rating::insert($ratings);

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
        Store::insert($stores);

        // Seed Entities (must be after categories due to foreign key)
        $lobby = Category::where('label', 'Lobby')->first()->id;
        $walkIn = Category::where('label', 'Walk-in')->first()->id;
        $driveThru = Category::where('label', 'Drive-Thru')->first()->id;
        $landing = Category::where('label', 'Landing')->first()->id;
        $making = Category::where('label', 'Making')->first()->id;
        $mgmt_sw = Category::where('label', 'MGMT-SW')->first()->id;
        $staffAlignment = Category::where('label', 'Staff-Alignment')->first()->id;

        $entities = [
            //lobby
            ['entity_label' => 'Lobby Speed of Service', 'category_id' => $lobby, 'date_range_type' => 'daily', 'report_type' =>'main'],
            ['entity_label' => 'Lobby Cleanliness', 'category_id' => $lobby, 'date_range_type' => 'daily', 'report_type' =>'main'],
            ['entity_label' => 'Pepsi Stock & Expiry', 'category_id' => $lobby, 'date_range_type' => 'weekly', 'report_type' =>'main'],

            //Walk-in
            ['entity_label' => 'Floor Storage Violations', 'category_id' => $walkIn, 'date_range_type' => 'daily', 'report_type' =>'main'],
            ['entity_label' => 'Premade Product Storage', 'category_id' => $walkIn, 'date_range_type' => 'daily', 'report_type' =>'main'],
            ['entity_label' => 'Product Expiry Dates', 'category_id' => $walkIn, 'date_range_type' => 'daily', 'report_type' =>'main'],
            ['entity_label' => 'FIFO Rotation', 'category_id' => $walkIn, 'date_range_type' => 'weekly', 'report_type' =>'main'],

            //driveThru
            ['entity_label' => 'Drive Thru Speed of Service', 'category_id' => $driveThru, 'date_range_type' => 'daily', 'report_type' =>'main'],

            //Landing
            ['entity_label' => 'Pizza Quality', 'category_id' => $landing, 'date_range_type' => 'daily', 'report_type' =>'main'],
            ['entity_label' => 'Parking Lot Condition', 'category_id' => $landing, 'date_range_type' => 'weekly', 'report_type' =>'main'],
            ['entity_label' => 'Exterior Trash Management', 'category_id' => $landing, 'date_range_type' => 'weekly', 'report_type' =>'main'],

            //Making
            ['entity_label' => 'Product Quality', 'category_id' => $making, 'date_range_type' => 'daily', 'report_type' =>'main'],
            ['entity_label' => 'Station Stock & Cleanliness', 'category_id' => $making, 'date_range_type' => 'daily', 'report_type' =>'main'],

            //Staff Alignment
            ['entity_label' => 'Personal Belongings Policy', 'category_id' => $walkIn, 'date_range_type' => 'daily', 'report_type' =>'main'],
            ['entity_label' => 'Uniform Compliance', 'category_id' => $walkIn, 'date_range_type' => 'daily', 'report_type' =>'main'],
            ['entity_label' => 'Behavior', 'category_id' => $walkIn, 'date_range_type' => 'daily', 'report_type' =>'main'],
            ['entity_label' => 'Sink Setup & Maintenance', 'category_id' => $walkIn, 'date_range_type' => 'daily', 'report_type' =>'main'],

            //secondary
            ['entity_label' => 'Guest Service', 'category_id' => null, 'date_range_type' => 'daily', 'report_type' =>'secondary'],
            ['entity_label' => 'Upselling', 'category_id' => null, 'date_range_type' => 'daily', 'report_type' =>'secondary'],
            ['entity_label' => 'Grab & Go Station', 'category_id' => null, 'date_range_type' => 'daily', 'report_type' =>'secondary'],
            ['entity_label' => 'Guest Service & Headset Use', 'category_id' => null, 'date_range_type' => 'daily', 'report_type' =>'secondary'],
            ['entity_label' => 'Drive Thru Availability', 'category_id' => null, 'date_range_type' => 'daily', 'report_type' =>'secondary'],
            ['entity_label' => 'Store Music', 'category_id' => null, 'date_range_type' => 'daily', 'report_type' =>'secondary'],

            ['entity_label' => 'Digital Menu Boards (DMBs)', 'category_id' => null, 'date_range_type' => 'weekly', 'report_type' =>'secondary'],
            ['entity_label' => 'Window Signage', 'category_id' => null, 'date_range_type' => 'weekly', 'report_type' =>'secondary'],
        ];


        Entity::insert($entities);
    }
}
