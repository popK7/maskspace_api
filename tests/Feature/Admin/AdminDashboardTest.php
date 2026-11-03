<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\SubscriptionReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithAuth;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use InteractsWithAuth, RefreshDatabase;

    public function test_admin_can_view_dashboard_counts_and_subscription_queue(): void
    {
        $adminToken = $this->loginAs(User::factory()->admin()->create())['tokens']['access_token'];
        User::factory()->role(UserRole::Boutique)->withProfile()->create([
            'is_visible' => false,
            'activation_requested_at' => now(),
        ]);
        User::factory()->role(UserRole::Client)->create([
            'status' => UserStatus::Suspended,
        ]);
        $owner = User::factory()->role(UserRole::Restaurant)->withProfile()->create();
        $receipt = SubscriptionReceipt::create([
            'user_id' => $owner->id,
            'receipt_number' => 'MS-QUEUE-TEST1',
            'plan' => 'orders_15',
            'order_limit' => 15,
            'status' => 'pending',
        ]);

        $this->api($adminToken)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('data.accounts.total', 3)
            ->assertJsonPath('data.accounts.suspended', 1)
            ->assertJsonPath('data.accounts.pending_visibility', 1)
            ->assertJsonPath('data.subscriptions.pending', 1);

        $this->api($adminToken)
            ->getJson('/api/v1/admin/subscriptions?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.receipt_number', $receipt->receipt_number)
            ->assertJsonPath('data.0.user.email', $owner->email);
    }

    public function test_non_admin_cannot_access_dashboard_or_subscription_queue(): void
    {
        $token = $this->loginAs(User::factory()->create())['tokens']['access_token'];

        $this->api($token)->getJson('/api/v1/admin/dashboard')->assertForbidden();
        $this->api($token)->getJson('/api/v1/admin/subscriptions')->assertForbidden();
    }

    public function test_admin_finance_report_sums_paid_subscription_amounts_for_selected_period(): void
    {
        $adminToken = $this->loginAs(User::factory()->admin()->create())['tokens']['access_token'];
        $professional = User::factory()->role(UserRole::Boutique)->withProfile()->create();
        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        $insideDate = now()->subDay();

        SubscriptionReceipt::create([
            'user_id' => $professional->id,
            'receipt_number' => 'MS-REPORT-PAID1',
            'plan' => 'orders_10',
            'plan_name' => 'Essentiel',
            'order_limit' => 10,
            'status' => 'active',
            'activated_at' => $insideDate,
            'amount_paid_usd' => '19.95',
        ]);
        SubscriptionReceipt::create([
            'user_id' => $professional->id,
            'receipt_number' => 'MS-REPORT-PAID2',
            'plan' => 'orders_15',
            'plan_name' => 'Plus',
            'order_limit' => 15,
            'status' => 'exhausted',
            'activated_at' => $insideDate,
            'amount_paid_usd' => '5.55',
        ]);
        SubscriptionReceipt::create([
            'user_id' => $professional->id,
            'receipt_number' => 'MS-REPORT-OLD01',
            'plan' => 'orders_10',
            'status' => 'active',
            'activated_at' => now()->subMonths(2),
            'amount_paid_usd' => '80.00',
        ]);
        SubscriptionReceipt::create([
            'user_id' => $professional->id,
            'receipt_number' => 'MS-REPORT-LEGACY',
            'plan' => 'orders_10',
            'status' => 'active',
            'activated_at' => $insideDate,
        ]);
        SubscriptionReceipt::create([
            'user_id' => $professional->id,
            'receipt_number' => 'MS-REPORT-PEND1',
            'plan' => 'orders_10',
            'status' => 'pending',
            'amount_paid_usd' => '100.00',
        ]);

        $this->api($adminToken)
            ->getJson("/api/v1/admin/subscription-finance-report?from=$from&to=$to")
            ->assertOk()
            ->assertJsonPath('data.summary.total_usd', '25.50')
            ->assertJsonPath('data.summary.subscription_count', 2)
            ->assertJsonPath('data.summary.unrecorded_count', 1)
            ->assertJsonPath('data.daily.0.total_usd', '25.50')
            ->assertJsonFragment(['amount_paid_usd' => '19.95']);
    }

    public function test_non_admin_cannot_view_finance_report_and_invalid_period_is_rejected(): void
    {
        $token = $this->loginAs(User::factory()->create())['tokens']['access_token'];
        $adminToken = $this->loginAs(User::factory()->admin()->create())['tokens']['access_token'];

        $this->api($token)
            ->getJson('/api/v1/admin/subscription-finance-report?from=2026-11-01&to=2026-11-03')
            ->assertForbidden();
        $this->api($adminToken)
            ->getJson('/api/v1/admin/subscription-finance-report?from=2026-11-04&to=2026-11-03')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    public function test_presence_endpoint_marks_user_connected_and_admin_can_filter_by_presence_and_service_status(): void
    {
        $professional = User::factory()
            ->role(UserRole::Artisan)
            ->withProfile()
            ->create(['is_available' => false, 'last_seen_at' => now()->subMinutes(5)]);
        $professionalToken = $this->loginAs($professional)['tokens']['access_token'];
        $this->api($professionalToken)
            ->postJson('/api/v1/me/presence')
            ->assertOk();
        DB::table('artisan_jobs')->insert([
            'client_id' => User::factory()->create()->id,
            'artisan_id' => $professional->id,
            'trade' => 'plomberie',
            'status' => 'completed',
            'address' => 'Casablanca',
            'latitude' => 33.5731,
            'longitude' => -7.5898,
            'completed_at' => now()->subHour(),
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHour(),
        ]);

        $offlineProfessional = User::factory()
            ->role(UserRole::Artisan)
            ->withProfile()
            ->create(['is_available' => true, 'last_seen_at' => now()->subMinutes(10)]);
        $adminToken = $this->loginAs(User::factory()->admin()->create())['tokens']['access_token'];

        $this->api($adminToken)
            ->getJson('/api/v1/admin/users?connection=connected&availability=0')
            ->assertOk()
            ->assertJsonPath('data.0.id', $professional->id)
            ->assertJsonPath('data.0.is_connected', true)
            ->assertJsonPath('data.0.is_available', false)
            ->assertJsonPath('data.0.last_seen_at', fn ($value) => is_string($value))
            ->assertJsonPath('data.0.last_service_at', fn ($value) => is_string($value));

        $this->api($adminToken)
            ->getJson('/api/v1/admin/users?connection=disconnected')
            ->assertOk()
            ->assertJsonFragment(['id' => $offlineProfessional->id]);
    }
}
