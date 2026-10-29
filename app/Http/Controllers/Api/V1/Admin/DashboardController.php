<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\SubscriptionReceipt;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $accounts = User::query()->where('role', '!=', 'admin');
        $professionals = (clone $accounts)->whereHas('roles', fn ($query) => $query
            ->whereNotIn('role', [UserRole::Client->value, UserRole::Admin->value])
            ->whereNotNull('profile_completed_at'));
        $connectedCutoff = now()->subMinutes(2);

        return response()->json(['data' => [
            'accounts' => [
                'total' => (clone $accounts)->count(),
                'active' => (clone $accounts)->where('status', UserStatus::Active)->count(),
                'suspended' => (clone $accounts)->where('status', UserStatus::Suspended)->count(),
                'connected' => (clone $accounts)->where('last_seen_at', '>=', $connectedCutoff)->count(),
                'in_service' => (clone $professionals)->where('is_available', true)->count(),
                'pending_visibility' => (clone $accounts)
                    ->where('is_visible', false)
                    ->whereNotNull('activation_requested_at')
                    ->count(),
            ],
            'subscriptions' => [
                'pending' => SubscriptionReceipt::query()->where('status', 'pending')->count(),
                'active' => SubscriptionReceipt::query()->where('status', 'active')->count(),
            ],
        ]]);
    }
}
