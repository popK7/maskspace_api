<?php

namespace App\Services\Beauty;

use App\Enums\BeautyStatus;
use App\Enums\JobOfferStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\BeautyOffer;
use App\Models\BeautyRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BeautyService
{
    public function publish(User $client, array $data): BeautyRequest
    {
        return BeautyRequest::create([
            'client_id' => $client->id,
            'specialty' => $data['specialty'],
            'mode' => $data['mode'] ?? 'salon',
            'note' => $data['note'] ?? null,
            'status' => BeautyStatus::Searching,
        ]);
    }

    public function offer(User $pro, BeautyRequest $request, string $message): BeautyOffer
    {
        $this->assertCovers($pro, $request);

        return DB::transaction(function () use ($pro, $request, $message) {
            $request = BeautyRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== BeautyStatus::Searching) {
                throw new ApiException('Cette demande n’est plus ouverte.', 409, 'beauty_not_searching');
            }

            $existing = BeautyOffer::where('beauty_request_id', $request->id)
                ->where('pro_id', $pro->id)
                ->first();
            if ($existing && $existing->status === JobOfferStatus::Pending) {
                $existing->forceFill(['message' => $message])->save();

                return $existing->fresh('pro');
            }
            if ($existing) {
                throw new ApiException('Vous ne pouvez plus répondre à cette demande.', 409, 'offer_closed');
            }

            return BeautyOffer::create([
                'beauty_request_id' => $request->id,
                'pro_id' => $pro->id,
                'message' => $message,
                'status' => JobOfferStatus::Pending,
            ])->fresh('pro');
        });
    }

    public function choose(User $client, BeautyRequest $request, int $offerId): BeautyRequest
    {
        return DB::transaction(function () use ($client, $request, $offerId) {
            $request = BeautyRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->client_id !== $client->id) {
                throw new ApiException('Demande introuvable.', 404, 'beauty_not_found');
            }
            if ($request->status !== BeautyStatus::Searching) {
                throw new ApiException('Cette demande n’est plus ouverte.', 409, 'beauty_not_searching');
            }

            $offer = BeautyOffer::where('beauty_request_id', $request->id)->whereKey($offerId)->lockForUpdate()->firstOrFail();
            if ($offer->status !== JobOfferStatus::Pending) {
                throw new ApiException('Cette réponse n’est plus valable.', 409, 'offer_not_pending');
            }

            $request->forceFill([
                'pro_id' => $offer->pro_id,
                'status' => BeautyStatus::Assigned,
                'assigned_at' => now(),
            ])->save();
            $offer->forceFill(['status' => JobOfferStatus::Chosen])->save();
            BeautyOffer::where('beauty_request_id', $request->id)
                ->where('id', '!=', $offer->id)
                ->where('status', JobOfferStatus::Pending)
                ->update(['status' => JobOfferStatus::Declined->value]);

            return $request->fresh(['client', 'pro', 'offers.pro']);
        });
    }

    public function complete(User $actor, BeautyRequest $request): BeautyRequest
    {
        if (! in_array($actor->id, [$request->client_id, $request->pro_id], true)) {
            throw new ApiException('Demande introuvable.', 404, 'beauty_not_found');
        }
        if ($request->status !== BeautyStatus::Assigned) {
            throw new ApiException('Le rendez-vous doit d’abord être attribué.', 409, 'beauty_bad_status');
        }
        $request->forceFill(['status' => BeautyStatus::Completed, 'completed_at' => now()])->save();

        return $request->fresh(['client', 'pro', 'offers.pro']);
    }

    public function cancel(User $actor, BeautyRequest $request, ?string $reason = null): BeautyRequest
    {
        if (! in_array($actor->id, [$request->client_id, $request->pro_id], true)) {
            throw new ApiException('Demande introuvable.', 404, 'beauty_not_found');
        }
        if ($request->status->isFinal()) {
            throw new ApiException('Cette demande est déjà close.', 409, 'beauty_closed');
        }
        $request->forceFill([
            'status' => BeautyStatus::Cancelled,
            'cancel_reason' => $reason,
            'cancelled_at' => now(),
        ])->save();

        return $request->fresh(['client', 'pro', 'offers.pro']);
    }

    /** @return list<string> */
    public function specialtiesOf(User $pro): array
    {
        $pro->loadMissing('profile');

        return array_values($pro->profile?->specialties ?? []);
    }

    private function assertCovers(User $pro, BeautyRequest $request): void
    {
        if ($pro->role !== UserRole::Beaute) {
            throw new ApiException('Compte Soins et beauté requis.', 403, 'forbidden_role');
        }
        $pro->ensureVisible();
        if (! $pro->is_available) {
            throw new ApiException('Passez en service pour répondre.', 409, 'beaute_offline');
        }
        if (! in_array($request->specialty->value, $this->specialtiesOf($pro), true)) {
            throw new ApiException('Cette prestation n’est pas la vôtre.', 403, 'specialty_not_covered');
        }
    }
}
