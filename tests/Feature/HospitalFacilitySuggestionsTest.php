<?php

use App\Models\HospitalFacility;
use App\Models\Reservation;
use App\Models\User;

function facilitySuggestionUser(string $role = 'fleet_manager'): User
{
    return User::factory()->create([
        'role' => $role,
        'department' => 'Emergency Department',
    ]);
}

function facilitySuggestionRecord(array $attributes = []): HospitalFacility
{
    return HospitalFacility::query()->create(array_merge([
        'name' => fake()->unique()->company().' Medical Center',
        'address' => fake()->streetAddress(),
        'city' => 'Caloocan City',
        'province' => 'Metro Manila',
        'latitude' => 14.7546,
        'longitude' => 121.0837,
        'type' => 'Hospital',
        'status' => true,
    ], $attributes));
}

function facilityReservationPayload(array $attributes = []): array
{
    return array_merge([
        'reservation_number' => fake()->unique()->bothify('FAC-RSV-####'),
        'patient_name' => null,
        'request_type' => 'administrative_trip',
        'pickup_location' => 'Manual Pickup Location',
        'destination' => 'Manual Destination Location',
        'schedule_date' => now()->addDay()->toDateString(),
        'schedule_time' => '09:00',
        'priority' => 'routine',
        'status' => 'Pending',
        'contact_number' => '09170000000',
    ], $attributes);
}

it('returns active matching facilities using the reservation frontend contract', function () {
    $matchingFacility = facilitySuggestionRecord([
        'name' => 'Dr. Jose N. Rodriguez Memorial Hospital and Sanitarium',
        'address' => 'Saint Joseph Avenue, Tala',
    ]);
    facilitySuggestionRecord(['name' => 'Unrelated General Hospital']);
    facilitySuggestionRecord([
        'name' => 'Inactive Tala Hospital',
        'status' => false,
    ]);

    $response = $this->actingAs(facilitySuggestionUser())
        ->getJson(route('reservation.hospital-facilities', ['search' => 'Tala']));

    $response->assertSuccessful()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'facilities')
        ->assertJsonPath('facilities.0.id', $matchingFacility->id)
        ->assertJsonStructure([
            'success',
            'facilities' => [[
                'id',
                'name',
                'address',
                'city',
                'province',
                'latitude',
                'longitude',
                'type',
            ]],
        ]);
});

it('returns a successful empty facility collection for an unmatched search', function () {
    facilitySuggestionRecord(['name' => 'Caloocan Medical Center']);

    $this->actingAs(facilitySuggestionUser())
        ->getJson(route('reservation.hospital-facilities', ['search' => 'No Such Facility']))
        ->assertSuccessful()
        ->assertJson([
            'success' => true,
            'facilities' => [],
        ]);
});

it('uses the same facility source for pickup and destination selections', function () {
    $pickup = facilitySuggestionRecord(['name' => 'Tala Community Hospital']);
    $destination = facilitySuggestionRecord(['name' => 'General Medical Center']);

    $response = $this->actingAs(facilitySuggestionUser('department_head'))
        ->postJson(route('reservation.store'), facilityReservationPayload([
            'pickup_location' => 'Typed Pickup',
            'pickup_hospital_id' => $pickup->id,
            'destination' => 'Typed Destination',
            'destination_hospital_id' => $destination->id,
        ]));

    $response->assertCreated()
        ->assertJsonPath('reservation.pickup_location', $pickup->name)
        ->assertJsonPath('reservation.pickup_hospital_id', $pickup->id)
        ->assertJsonPath('reservation.destination', $destination->name)
        ->assertJsonPath('reservation.destination_hospital_id', $destination->id);

    $reservation = Reservation::query()->findOrFail($response->json('reservation.id'));

    expect($reservation->pickupHospital->is($pickup))->toBeTrue()
        ->and($reservation->destinationHospital->is($destination))->toBeTrue();
});

it('keeps manual pickup and destination text valid without facility selections', function () {
    $response = $this->actingAs(facilitySuggestionUser('department_head'))
        ->postJson(route('reservation.store'), facilityReservationPayload());

    $response->assertCreated()
        ->assertJsonPath('reservation.pickup_location', 'Manual Pickup Location')
        ->assertJsonPath('reservation.pickup_hospital_id', null)
        ->assertJsonPath('reservation.destination', 'Manual Destination Location')
        ->assertJsonPath('reservation.destination_hospital_id', null);
});

it('wires add and edit pickup and destination inputs to the shared facility helper', function () {
    $addSource = file_get_contents(public_path('assets/js/reservation/reservation-add.js'));
    $editSource = file_get_contents(public_path('assets/js/reservation/reservation-edit.js'));

    expect($addSource)
        ->toContain('/reservation/hospital-facilities?search=')
        ->toContain('data.facilities')
        ->toContain('"reservationPickup"')
        ->toContain('"reservationDestination"')
        ->and($editSource)
        ->toContain('"editReservationPickup"')
        ->toContain('"editReservationDestination"');
});
