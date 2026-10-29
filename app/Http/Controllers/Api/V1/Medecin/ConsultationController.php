<?php

namespace App\Http\Controllers\Api\V1\Medecin;

use App\Enums\ConsultationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ConsultationResource;
use App\Models\Consultation;
use App\Services\Care\ConsultationService;
use App\Services\Dispatch\LocationPing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConsultationController extends Controller
{
    public function __construct(private readonly ConsultationService $care) {}

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $specialties = $this->care->specialtiesOf($user);
        $open = $user->hasCompletedProfile() && $user->is_available && $user->is_visible
            ? $this->care->availableFor($user)->count()
            : 0;

        return response()->json(['data' => [
            'is_available' => (bool) $user->is_available,
            'specialties' => $specialties,
            'open_requests' => $open,
            'assigned' => Consultation::where('medecin_id', $user->id)->where('status', ConsultationStatus::Assigned)->count(),
        ]]);
    }

    public function earnings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'in:7,30,90'],
        ]);
        $days = (int) ($validated['days'] ?? 7);
        $to = now()->endOfDay();
        $from = now()->startOfDay()->subDays($days - 1);
        $consultations = Consultation::query()
            ->where('medecin_id', $request->user()->id)
            ->where('status', ConsultationStatus::Completed)
            ->where('price_status', 'accepted')
            ->whereNotNull('price_dh')
            ->whereBetween('completed_at', [$from, $to]);

        $summary = (clone $consultations)
            ->selectRaw('COALESCE(SUM(price_dh), 0) as confirmed_total_dh')
            ->selectRaw('COUNT(*) as completed_count')
            ->first();

        $daily = (clone $consultations)
            ->selectRaw('DATE(completed_at) as date')
            ->selectRaw('SUM(price_dh) as total_dh')
            ->selectRaw('COUNT(*) as consultations_count')
            ->groupByRaw('DATE(completed_at)')
            ->orderBy('date')
            ->get()
            ->map(fn (object $row): array => [
                'date' => $row->date,
                'total_dh' => (int) $row->total_dh,
                'consultations_count' => (int) $row->consultations_count,
            ]);

        $recent = (clone $consultations)
            ->latest('completed_at')
            ->limit(10)
            ->get(['id', 'specialty', 'mode', 'price_dh', 'completed_at'])
            ->map(fn (Consultation $consultation): array => [
                'id' => $consultation->id,
                'specialty' => $consultation->specialty->value,
                'mode' => $consultation->mode->value,
                'price_dh' => $consultation->price_dh,
                'completed_at' => $consultation->completed_at?->toIso8601String(),
            ]);

        $completedCount = (int) $summary->completed_count;
        $confirmedTotal = (int) $summary->confirmed_total_dh;

        return response()->json(['data' => [
            'days' => $days,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'confirmed_total_dh' => $confirmedTotal,
            'completed_count' => $completedCount,
            'average_dh' => $completedCount > 0
                ? (int) round($confirmedTotal / $completedCount)
                : 0,
            'daily' => $daily,
            'recent' => $recent,
        ]]);
    }

    public function available(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        if (! $user->hasCompletedProfile() || ! $user->is_available || ! $user->is_visible) {
            return ConsultationResource::collection(collect());
        }

        return ConsultationResource::collection($this->care->availableFor($user));
    }

    public function location(Request $request, LocationPing $ping): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $ping->record($request->user(), (float) $data['latitude'], (float) $data['longitude']);

        return response()->json(['data' => ['recorded' => true]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $rows = Consultation::with(['client', 'offers.medecin'])
            ->where('medecin_id', $request->user()->id)
            ->latest('id')
            ->paginate(30);

        return ConsultationResource::collection($rows);
    }

    public function offer(Request $request, Consultation $consultation): ConsultationResource
    {
        $data = $request->validate(['message' => ['required', 'string', 'min:8', 'max:500']]);
        $this->care->offer($request->user(), $consultation, $data['message']);

        return new ConsultationResource($consultation->fresh(['client', 'offers.medecin']));
    }

    public function price(Request $request, Consultation $consultation): ConsultationResource
    {
        $data = $request->validate([
            'price_dh' => ['required', 'integer', 'between:1,10000000'],
        ]);

        return new ConsultationResource($this->care->proposePrice(
            $request->user(),
            $consultation,
            (int) $data['price_dh'],
        ));
    }

    public function complete(Request $request, Consultation $consultation): ConsultationResource
    {
        return new ConsultationResource($this->care->complete($request->user(), $consultation));
    }

    public function cancel(Request $request, Consultation $consultation): ConsultationResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return new ConsultationResource($this->care->cancel($request->user(), $consultation, $data['reason'] ?? null));
    }
}
