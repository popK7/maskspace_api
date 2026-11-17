<?php

namespace Tests\Feature\Verification;

use App\Enums\UserRole;
use App\Jobs\SendPushNotification;
use App\Models\ProviderDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class ProviderVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['kyc.enforce' => true]);
        Storage::fake(ProviderDocument::DISK);
    }

    public function test_unverified_provider_cannot_go_online_until_all_documents_are_approved(): void
    {
        Queue::fake();
        $medecin = $this->provider(UserRole::Medecin);
        $token = JWTAuth::fromUser($medecin);
        $admin = User::factory()->admin()->create();
        $adminToken = JWTAuth::fromUser($admin);

        $this->api($token)->getJson('/api/v1/me')
            ->assertJsonPath('data.verification_required', true);
        $this->api($token)->patchJson('/api/v1/medecin/availability', ['is_available' => true])
            ->assertForbidden()->assertJsonPath('code', 'verification_required');

        $this->api($token)->getJson('/api/v1/me/verification')
            ->assertOk()
            ->assertJsonPath('data.status', 'missing')
            ->assertJsonPath('data.documents.0.type', 'id_card')
            ->assertJsonPath('data.documents.1.type', 'medical_license');

        foreach (['id_card', 'medical_license'] as $type) {
            $this->api($token)->post("/api/v1/me/verification/documents/{$type}", [
                'file' => UploadedFile::fake()->create("{$type}.pdf", 200, 'application/pdf'),
            ], ['Accept' => 'application/json'])->assertCreated();
        }
        $this->api($token)->getJson('/api/v1/me/verification')->assertJsonPath('data.status', 'pending');

        $documents = ProviderDocument::where('user_id', $medecin->id)->get();
        $this->assertCount(2, $documents);
        Storage::disk(ProviderDocument::DISK)->assertExists($documents[0]->path);

        $this->api($adminToken)->getJson('/api/v1/admin/verification-documents')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.user.id', $medecin->id);
        $this->api($adminToken)->get("/api/v1/admin/verification-documents/{$documents[0]->id}/file")->assertOk();

        $this->api($adminToken)->patchJson("/api/v1/admin/verification-documents/{$documents[0]->id}", ['decision' => 'approve'])
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->api($token)->patchJson('/api/v1/medecin/availability', ['is_available' => true])
            ->assertForbidden();

        $this->api($adminToken)->patchJson("/api/v1/admin/verification-documents/{$documents[1]->id}", ['decision' => 'approve'])
            ->assertOk();

        $this->api($token)->getJson('/api/v1/me/verification')->assertJsonPath('data.status', 'verified');
        $this->api($token)->patchJson('/api/v1/medecin/availability', ['is_available' => true])
            ->assertOk()->assertJsonPath('data.is_available', true);
        Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->userId === $medecin->id && $job->title === 'Profil vérifié');
    }

    public function test_rejected_document_requires_a_reason_and_can_be_replaced(): void
    {
        Queue::fake();
        $artisan = $this->provider(UserRole::Artisan);
        $token = JWTAuth::fromUser($artisan);
        $adminToken = JWTAuth::fromUser(User::factory()->admin()->create());

        $this->api($token)->post('/api/v1/me/verification/documents/id_card', [
            'file' => UploadedFile::fake()->image('cni.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $document = ProviderDocument::firstOrFail();
        $firstPath = $document->path;

        $this->api($adminToken)->patchJson("/api/v1/admin/verification-documents/{$document->id}", ['decision' => 'reject'])
            ->assertStatus(422)->assertJsonPath('code', 'reason_required');
        $this->api($adminToken)->patchJson("/api/v1/admin/verification-documents/{$document->id}", [
            'decision' => 'reject', 'reason' => 'Photo floue',
        ])->assertOk();

        $this->api($token)->getJson('/api/v1/me/verification')
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.documents.0.rejection_reason', 'Photo floue');
        Queue::assertPushed(SendPushNotification::class, fn ($job) => $job->title === 'Pièce refusée');

        $this->api($token)->post('/api/v1/me/verification/documents/id_card', [
            'file' => UploadedFile::fake()->image('cni-nette.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->assertSame(1, ProviderDocument::count());
        Storage::disk(ProviderDocument::DISK)->assertMissing($firstPath);
    }

    public function test_uploads_are_validated_and_files_are_reserved_to_admins(): void
    {
        $artisan = $this->provider(UserRole::Artisan);
        $token = JWTAuth::fromUser($artisan);

        $this->api($token)->post('/api/v1/me/verification/documents/medical_license', [
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'document_not_required');

        $this->api($token)->post('/api/v1/me/verification/documents/id_card', [
            'file' => UploadedFile::fake()->create('script.php', 10, 'text/x-php'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->api($token)->post('/api/v1/me/verification/documents/id_card', [
            'file' => UploadedFile::fake()->image('cni.png'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $document = ProviderDocument::firstOrFail();

        $this->api($token)->get("/api/v1/admin/verification-documents/{$document->id}/file", ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->api($token)->getJson('/api/v1/admin/verification-documents')->assertForbidden();
    }

    public function test_roles_without_requirements_are_not_blocked(): void
    {
        $boutique = $this->provider(UserRole::Boutique);

        $this->api(JWTAuth::fromUser($boutique))->getJson('/api/v1/me/verification')
            ->assertOk()->assertJsonPath('data.status', 'not_required');
        $this->assertTrue($boutique->fresh()->roles->first()->verified_at === null);
        $boutique->fresh()->ensureVisible();
    }

    private function provider(UserRole $role): User
    {
        return User::factory()->role($role)->withProfile()->create([
            'is_visible' => true,
            'is_available' => false,
        ]);
    }
}
