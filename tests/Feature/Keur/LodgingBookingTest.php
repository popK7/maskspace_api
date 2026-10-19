<?php

namespace Tests\Feature\Keur;

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Models\Lodging;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class LodgingBookingTest extends TestCase
{
    use InteractsWithAuth, InteractsWithShop, RefreshDatabase;

    public function test_catalog_lists_only_active_lodgings_with_photos(): void
    {
        $host = $this->makeHost();
        $visible = $this->makeLodging($host);
        $hidden = $this->makeLodging($host, ['title' => 'Brouillon', 'is_active' => false]);
        $hidden->photos()->delete();

        $this->api()->getJson('/api/v1/lodgings')->assertOk()
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.kind', 'appartement')
            ->assertJsonCount(1, 'data.0.photos');

        $this->api()->getJson("/api/v1/lodgings/{$hidden->id}")->assertNotFound();
    }

    public function test_client_books_then_host_accepts_and_they_can_chat(): void
    {
        $hostUser = $this->makeHost();
        $host = $this->tokenFor($hostUser);
        $client = $this->tokenFor($this->makeClient());
        $lodging = $this->makeLodging($hostUser);

        $bookingId = $this->api($client)->postJson('/api/v1/bookings', [
            'lodging_id' => $lodging->id,
            'check_in' => now()->addDays(2)->toDateString(),
            'check_out' => now()->addDays(5)->toDateString(),
            'guests' => 2,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.nights', 3)
            ->assertJsonPath('data.total_cents', 45000 * 3)
            ->json('data.id');

        $this->api($host)->postJson("/api/v1/hote/bookings/{$bookingId}/accept")->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $threads = $this->api($client)->getJson("/api/v1/chats?subject_type=booking&subject_id={$bookingId}")
            ->assertOk()->assertJsonCount(1, 'data')->json('data');
        $this->assertSame('customer_host', $threads[0]['channel']);

        $this->api($client)->postJson("/api/v1/chats/{$threads[0]['id']}/messages", ['body' => 'On arrive vers 15h'])
            ->assertCreated();
    }

    public function test_overlapping_dates_are_rejected(): void
    {
        $host = $this->makeHost();
        $client = $this->tokenFor($this->makeClient());
        $other = $this->tokenFor(User::factory()->create(['email' => 'other@example.com']));
        $lodging = $this->makeLodging($host);

        $this->api($client)->postJson('/api/v1/bookings', [
            'lodging_id' => $lodging->id,
            'check_in' => '2030-03-10',
            'check_out' => '2030-03-14',
            'guests' => 1,
        ])->assertCreated();

        $this->api($other)->postJson('/api/v1/bookings', [
            'lodging_id' => $lodging->id,
            'check_in' => '2030-03-12',
            'check_out' => '2030-03-16',
            'guests' => 1,
        ])->assertStatus(409)->assertJsonPath('code', 'dates_taken');
    }

    public function test_host_must_add_a_photo_before_publishing(): void
    {
        Storage::fake('public');
        $host = $this->tokenFor($this->makeHost());

        $id = $this->api($host)->postJson('/api/v1/hote/lodgings', [
            'kind' => 'chambre',
            'title' => 'Chambre test',
            'address' => 'Maârif',
            'latitude' => 33.58,
            'longitude' => -7.62,
            'price_cents' => 15000,
        ])->assertCreated()->assertJsonPath('data.is_active', false)->json('data.id');

        $this->api($host)->patchJson("/api/v1/hote/lodgings/{$id}/availability", ['is_active' => true])
            ->assertStatus(422)->assertJsonPath('code', 'photos_required');

        $this->api($host)->post("/api/v1/hote/lodgings/{$id}/photos", [
            'image' => UploadedFile::fake()->image('salon.jpg', 800, 600),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_monthly_rental_counts_whole_months(): void
    {
        $host = $this->makeHost();
        $client = $this->tokenFor($this->makeClient());
        $lodging = $this->makeLodging($host, [
            'billing' => 'month',
            'price_cents' => 800000,
            'title' => 'Studio au mois',
        ]);

        $this->api($client)->postJson('/api/v1/bookings', [
            'lodging_id' => $lodging->id,
            'check_in' => '2030-04-01',
            'check_out' => '2030-04-15',
            'guests' => 1,
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_dates');

        $this->api($client)->postJson('/api/v1/bookings', [
            'lodging_id' => $lodging->id,
            'check_in' => '2030-04-01',
            'check_out' => '2030-07-01',
            'guests' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.billing', 'month')
            ->assertJsonPath('data.nights', 3)
            ->assertJsonPath('data.total_cents', 800000 * 3);
    }

    public function test_client_can_cancel_a_pending_booking(): void
    {
        $host = $this->makeHost();
        $client = $this->tokenFor($this->makeClient());
        $lodging = $this->makeLodging($host);
        $id = $this->api($client)->postJson('/api/v1/bookings', [
            'lodging_id' => $lodging->id,
            'check_in' => now()->addWeek()->toDateString(),
            'check_out' => now()->addWeek()->addDays(2)->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->api($client)->postJson("/api/v1/bookings/{$id}/cancel")->assertOk()
            ->assertJsonPath('data.status', BookingStatus::Cancelled->value);
    }

    protected function makeHost(array $attrs = []): User
    {
        return User::factory()->role(UserRole::Hote)->withProfile()->create($attrs);
    }

    protected function makeLodging(User $host, array $overrides = []): Lodging
    {
        $lodging = Lodging::create(array_merge([
            'host_id' => $host->id,
            'kind' => 'appartement',
            'title' => 'Appartement Maârif',
            'address' => 'Rue Ibnou Katir, Casablanca',
            'neighborhood' => 'Maârif',
            'city' => 'Casablanca',
            'latitude' => 33.5896,
            'longitude' => -7.6033,
            'guests' => 4,
            'bedrooms' => 2,
            'beds' => 2,
            'bathrooms' => 1,
            'price_cents' => 45000,
            'amenities' => ['wifi', 'clim'],
            'is_active' => true,
        ], $overrides));

        if (($overrides['is_active'] ?? true) !== false) {
            $lodging->photos()->create([
                'path' => 'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?w=800',
                'position' => 1,
            ]);
        }

        return $lodging->fresh('photos');
    }
}
