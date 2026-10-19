<?php

namespace App\Http\Controllers\Api\V1\Job;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\JobResource;
use App\Models\Job;
use App\Services\Dispatch\JobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Interventions du client. */
class JobController extends Controller
{
    public function __construct(private readonly JobService $jobs) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'trade' => ['required', 'string', Rule::in(UserRole::Artisan->allowedSpecialties())],
            'address' => ['required', 'string', 'max:255'],
            'neighborhood' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:80'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $job = $this->jobs->publish($request->user(), $data);

        return (new JobResource($job->load(['client', 'offers'])))->response()->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $jobs = Job::with(['artisan.profile', 'ride.driver.profile'])
            ->where('client_id', $request->user()->id)
            ->latest('id')
            ->paginate(min(50, (int) $request->integer('per_page', 20)));

        return JobResource::collection($jobs);
    }

    public function show(Request $request, Job $job): JobResource
    {
        $this->authorizeClient($request, $job);

        return new JobResource($job->load(['client', 'artisan.profile', 'offers.artisan.profile', 'ride.driver.profile']));
    }

    public function choose(Request $request, Job $job): JobResource
    {
        $this->authorizeClient($request, $job);
        $data = $request->validate(['offer_id' => ['required', 'integer']]);

        return new JobResource($this->jobs->choose($request->user(), $job, (int) $data['offer_id']));
    }

    public function cancel(Request $request, Job $job): JobResource
    {
        $this->authorizeClient($request, $job);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return new JobResource($this->jobs->cancel($request->user(), $job, $data['reason'] ?? null));
    }

    public function review(Request $request, Job $job): JobResource
    {
        $this->authorizeClient($request, $job);
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        return new JobResource($this->jobs->review($request->user(), $job, (int) $data['rating'], $data['comment'] ?? null));
    }

    public function tracking(Request $request, Job $job): JsonResponse
    {
        $this->authorizeClient($request, $job);
        $job->load(['artisan.profile', 'ride.driver.profile']);

        return response()->json(['data' => $this->jobs->tracking($job)]);
    }

    private function authorizeClient(Request $request, Job $job): void
    {
        abort_unless($job->client_id === $request->user()->id, 404);
    }
}
