<?php

namespace Database\Seeders;

use App\Models\VehicleCapability;
use Illuminate\Database\Seeder;

class VehicleCapabilitySeeder extends Seeder
{
    /**
     * Seed initial vehicle capabilities.
     */
    public function run(): void
    {
        $capabilities = [
            ['name' => 'Emergency Response', 'slug' => 'emergency_response', 'description' => 'Vehicle equipped and authorized for emergency dispatch.'],
            ['name' => 'Patient Transport', 'slug' => 'patient_transport', 'description' => 'Configured for safe patient transport.'],
            ['name' => 'Wheelchair Accessible', 'slug' => 'wheelchair_accessible', 'description' => 'Equipped with wheelchair ramp or lift.'],
            ['name' => 'Stretcher Equipped', 'slug' => 'stretcher_equipped', 'description' => 'Equipped with medical stretcher.'],
            ['name' => 'Medical Supply Transport', 'slug' => 'medical_supply_transport', 'description' => 'Suitable for transporting medical supplies.'],
            ['name' => 'Laboratory Specimen Transport', 'slug' => 'laboratory_specimen_transport', 'description' => 'Suitable for secure temperature-sensitive specimen transport.'],
            ['name' => 'Equipment Transport', 'slug' => 'equipment_transport', 'description' => 'Suitable for medical equipment transfers.'],
            ['name' => 'Staff Transport', 'slug' => 'staff_transport', 'description' => 'Configured for hospital staff shuttle and transport.'],
            ['name' => 'Cargo Transport', 'slug' => 'cargo_transport', 'description' => 'General cargo and heavy logistics transport.'],
            ['name' => 'Oxygen Equipment Available', 'slug' => 'oxygen_equipment_available', 'description' => 'On-board medical oxygen supply available.'],
            ['name' => 'Document Courier', 'slug' => 'document_courier', 'description' => 'Suitable for secure hospital document and records courier work.'],
        ];

        foreach ($capabilities as $cap) {
            VehicleCapability::updateOrCreate(
                ['slug' => $cap['slug']],
                $cap
            );
        }
    }
}
