<?php

namespace Tests\Feature\Reviews;

use App\Enums\UserRole;
use App\Models\BeautyOffer;
use App\Models\BeautyRequest;
use App\Models\Consultation;
use App\Models\ConsultationOffer;
use App\Models\HousingOffer;
use App\Models\HousingRequest;
use App\Models\User;
use App\Services\Notifications\PushNotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

/** Notation client -> medecin, pro beaute, demarcheur. */
class ProviderReviewTest extends TestCase
{
    use InteractsWithAuth, InteractsWithShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy(PushNotificationService::class);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function verticals(): array
    {
        return [
            'consultation' => [[
                'path' => 'consultations',
                'model' => Consultation::class,
                'provider' => 'medecin_id',
                'role' => UserRole::Medecin,
                'attrs' => ['specialty' => 'generaliste'],
                'offer' => ConsultationOffer::class,
                'offer_fk' => 'consultation_id',
                'offer_key' => 'medecin',
                'dashboard' => '/api/v1/medecin/dashboard',
            ]],
            'beauty' => [[
                'path' => 'beauty-requests',
                'model' => BeautyRequest::class,
                'provider' => 'pro_id',
                'role' => UserRole::Beaute,
                'attrs' => ['specialty' => 'coiffeur'],
                'offer' => BeautyOffer::class,
                'offer_fk' => 'beauty_request_id',
                'offer_key' => 'pro',
                'dashboard' => '/api/v1/beaute/dashboard',
            ]],
            'housing' => [[
                'path' => 'housing-requests',
                'model' => HousingRequest::class,
                'provider' => 'demarcheur_id',
                'role' => UserRole::Demarcheur,
                'attrs' => ['kind' => 'appartement', 'zone' => 'gironde'],
                'offer' => HousingOffer::class,
                'offer_fk' => 'housing_request_id',
                'offer_key' => 'demarcheur',
                'dashboard' => '/api/v1/demarcheur/dashboard',
            ]],
        ];
    }

    /** @param  array<string, mixed>  $v */
    #[DataProvider('verticals')]
    public function test_client_rates_completed_request_once(array $v): void
    {
        $client = $this->makeClient();
        $provider = $this->provider($v);
        $request = $this->request($v, $client, $provider, 'completed');
        $token = $this->tokenFor($client);

        $this->api($token)
            ->postJson("/api/v1/{$v['path']}/{$request->id}/review", ['rating' => 5, 'comment' => 'Très bien'])
            ->assertOk()
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.review', 'Très bien');

        $this->api($token)
            ->postJson("/api/v1/{$v['path']}/{$request->id}/review", ['rating' => 1])
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_reviewed');
    }

    /** @param  array<string, mixed>  $v */
    #[DataProvider('verticals')]
    public function test_request_cannot_be_rated_before_completion_or_by_others(array $v): void
    {
        $client = $this->makeClient();
        $provider = $this->provider($v);
        $assigned = $this->request($v, $client, $provider, 'assigned');
        $completed = $this->request($v, $client, $provider, 'completed');

        $this->api($this->tokenFor($client))
            ->postJson("/api/v1/{$v['path']}/{$assigned->id}/review", ['rating' => 4])
            ->assertStatus(409)
            ->assertJsonPath('code', 'not_completed');

        $this->api($this->tokenFor($this->makeClient()))
            ->postJson("/api/v1/{$v['path']}/{$completed->id}/review", ['rating' => 4])
            ->assertNotFound();
    }

    /** @param  array<string, mixed>  $v */
    #[DataProvider('verticals')]
    public function test_offers_and_dashboard_show_provider_rating(array $v): void
    {
        $provider = $this->provider($v);
        $this->request($v, $this->makeClient(), $provider, 'completed')->forceFill(['rating' => 5])->save();
        $this->request($v, $this->makeClient(), $provider, 'completed')->forceFill(['rating' => 2])->save();

        $client = $this->makeClient();
        $open = $this->request($v, $client, null, 'searching');
        $v['offer']::create([
            $v['offer_fk'] => $open->id,
            $v['provider'] => $provider->id,
            'message' => 'Disponible',
            'status' => 'pending',
        ]);

        $this->api($this->tokenFor($client))
            ->getJson("/api/v1/{$v['path']}/{$open->id}")
            ->assertOk()
            ->assertJsonPath("data.offers.0.{$v['offer_key']}.id", $provider->id)
            ->assertJsonPath("data.offers.0.{$v['offer_key']}.rating", 3.5)
            ->assertJsonPath("data.offers.0.{$v['offer_key']}.rating_count", 2);

        $this->api($this->tokenFor($provider))
            ->getJson($v['dashboard'])
            ->assertOk()
            ->assertJsonPath('data.rating', 3.5)
            ->assertJsonPath('data.rating_count', 2);
    }

    /** @param  array<string, mixed>  $v */
    private function provider(array $v): User
    {
        return User::factory()->role($v['role'])->withProfile(['specialties' => ['generaliste']])->create(['is_available' => true]);
    }

    /** @param  array<string, mixed>  $v */
    private function request(array $v, User $client, ?User $provider, string $status): Model
    {
        /** @var Model $model */
        $model = new $v['model'];
        $model->forceFill(array_merge($v['attrs'], [
            'client_id' => $client->id,
            $v['provider'] => $provider?->id,
            'status' => $status,
            'completed_at' => $status === 'completed' ? now() : null,
        ]))->save();

        return $model;
    }
}
