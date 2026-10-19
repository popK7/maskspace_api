<?php

namespace App\Http\Controllers\Api\V1\Artisan;

use App\Enums\JobOfferStatus;
use App\Enums\JobStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\JobResource;
use App\Http\Resources\RideResource;
use App\Models\Job;
use App\Models\JobOffer;
use App\Services\Dispatch\JobService;
use App\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class JobController extends Controller
{
    public function __construct(private readonly JobService $jobs) {}

    public function available(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing('profile');
        if (! $user->is_available) {
            return response()->json(['data' => [], 'meta' => ['is_available' => false]]);
        }

        $trades = $user->profile?->specialties ?? [];
        $offered = JobOffer::where('artisan_id', $user->id)->pluck('job_id');
        $radius = (float) config('job.radius_km', 15);

        $rows = Job::with('client')
            ->where('status', JobStatus::Searching)
            ->whereIn('trade', $trades ?: ['__none__'])
            ->whereNotIn('id', $offered)
            ->latest('id')->limit(50)->get()
            ->map(function (Job $job) use ($user, $radius) {
                $km = $user->hasLocation()
                    ? round(Geo::km($user->latitude, $user->longitude, $job->latitude, $job->longitude), 2)
                    : null;
                if ($km !== null && $km > $radius) {
                    return null;
                }

                return [
                    'id' => $job->id,
                    'trade' => $job->trade,
                    'note' => $job->note,
                    'neighborhood' => $job->neighborhood,
                    'city' => $job->city,
                    'client' => ['name' => $job->client?->name],
                    'distance_km' => $km,
                    'eta_minutes' => $km !== null ? Geo::etaMinutes($km) : null,
                    'created_at' => $job->created_at?->toIso8601String(),
                ];
            })
            ->filter()
            ->sortBy(fn ($a) => $a['distance_km'] ?? INF)
            ->values();

        return response()->json(['data' => $rows, 'meta' => ['is_available' => true]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $jobs = Job::with(['client', 'ride.driver.profile', 'ride.offeredDriver'])
            ->where('artisan_id', $request->user()->id)
            ->latest('id')
            ->paginate(min(50, (int) $request->integer('per_page', 20)));

        return JobResource::collection($jobs);
    }

    public function show(Request $request, Job $job): JobResource
    {
        $this->authorizeArtisan($request, $job);

        return new JobResource($job->load(['client', 'artisan.profile', 'ride.driver.profile', 'ride.offeredDriver']));
    }

    public function offer(Request $request, Job $job): JsonResponse
    {
        $data = $request->validate(['eta_minutes' => ['nullable', 'integer', 'between:1,180']]);
        $offer = $this->jobs->offer($request->user(), $job, $data['eta_minutes'] ?? null);

        return response()->json(['data' => [
            'id' => $offer->id,
            'status' => $offer->status->value,
            'eta_minutes' => $offer->eta_minutes,
            'distance_km' => $offer->distance_km,
            'job_id' => $offer->job_id,
        ]], 201);
    }

    public function withdraw(Request $request, Job $job): JsonResponse
    {
        $this->jobs->withdraw($request->user(), $job);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function enRoute(Request $request, Job $job): JobResource
    {
        $this->authorizeArtisan($request, $job);

        return new JobResource($this->jobs->go($request->user(), $job));
    }

    public function arrive(Request $request, Job $job): JobResource
    {
        $this->authorizeArtisan($request, $job);

        return new JobResource($this->jobs->arrive($request->user(), $job));
    }

    public function start(Request $request, Job $job): JobResource
    {
        $this->authorizeArtisan($request, $job);

        return new JobResource($this->jobs->start($request->user(), $job));
    }

    public function complete(Request $request, Job $job): JobResource
    {
        $this->authorizeArtisan($request, $job);

        return new JobResource($this->jobs->complete($request->user(), $job));
    }

    public function cancel(Request $request, Job $job): JobResource
    {
        $this->authorizeArtisan($request, $job);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return new JobResource($this->jobs->cancel($request->user(), $job, $data['reason'] ?? null));
    }

    public function requestRide(Request $request, Job $job): JsonResponse
    {
        $this->authorizeArtisan($request, $job);
        $user = $request->user();
        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'address' => ['nullable', 'string', 'max:255'],
            'driver_id' => ['nullable', 'integer'],
        ]);

        $lat = $data['latitude'] ?? $user->latitude;
        $lng = $data['longitude'] ?? $user->longitude;
        if ($lat === null || $lng === null) {
            throw new ApiException('Indiquez votre position pour qu’un transporteur vienne vous chercher.', 422, 'location_required');
        }

        $ride = $this->jobs->requestRide($user, $job, [
            'latitude' => (float) $lat,
            'longitude' => (float) $lng,
            'address' => $data['address'] ?? 'Ma position',
            'driver_id' => $data['driver_id'] ?? null,
        ]);

        $created = $ride->wasRecentlyCreated;

        return (new RideResource($ride->load(['passenger', 'offeredDriver.profile', 'job'])))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = [now()->startOfDay(), now()->endOfDay()];
        $completedToday = Job::where('artisan_id', $user->id)->where('status', JobStatus::Completed)
            ->whereBetween('completed_at', $today)->count();
        $lifetime = Job::where('artisan_id', $user->id)->where('status', JobStatus::Completed)->count();
        $rating = Job::where('artisan_id', $user->id)->whereNotNull('rating')->avg('rating');
        $active = Job::where('artisan_id', $user->id)->whereIn('status', JobStatus::underway())->count();
        $pendingOffers = JobOffer::where('artisan_id', $user->id)->where('status', JobOfferStatus::Pending)->count();

        return response()->json(['data' => [
            'is_available' => (bool) $user->is_available,
            'today_jobs' => $completedToday,
            'lifetime_jobs' => $lifetime,
            'rating' => $rating !== null ? round((float) $rating, 1) : null,
            'active_count' => $active,
            'pending_offers' => $pendingOffers,
        ]]);
    }

    private function authorizeArtisan(Request $request, Job $job): void
    {
        abort_unless($job->artisan_id === $request->user()->id, 404);
    }
}
