<?php

namespace App\Services\Housing;

use App\Enums\HousingStatus;
use App\Enums\JobOfferStatus;
use App\Enums\RentalBilling;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\HousingOffer;
use App\Models\HousingRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class HousingService
{
    public function publish(User $client, array $data): HousingRequest
    {
        return HousingRequest::create([
            'client_id' => $client->id,
            'kind' => $data['kind'],
            'zone' => $data['zone'],
            'zone_name' => $data['zone_name'] ?? $this->legacyZoneLabel($data['zone']),
            'zone_city' => $data['zone_city'] ?? null,
            'zone_latitude' => $data['zone_latitude'] ?? null,
            'zone_longitude' => $data['zone_longitude'] ?? null,
            'billing' => $data['billing'] ?? RentalBilling::Night->value,
            'budget_cents' => $data['budget_cents'] ?? null,
            'guests' => $data['guests'] ?? 1,
            'note' => $data['note'] ?? null,
            'status' => HousingStatus::Searching,
        ]);
    }

    public function offer(User $demarcheur, HousingRequest $request, string $message): HousingOffer
    {
        $this->assertCovers($demarcheur, $request);

        return DB::transaction(function () use ($demarcheur, $request, $message) {
            $request = HousingRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== HousingStatus::Searching) {
                throw new ApiException('Cette demande n’est plus ouverte.', 409, 'housing_not_searching');
            }

            $existing = HousingOffer::where('housing_request_id', $request->id)
                ->where('demarcheur_id', $demarcheur->id)
                ->first();
            if ($existing && $existing->status === JobOfferStatus::Pending) {
                $existing->forceFill(['message' => $message])->save();

                return $existing->fresh('demarcheur');
            }
            if ($existing) {
                throw new ApiException('Vous ne pouvez plus répondre à cette demande.', 409, 'offer_closed');
            }

            return HousingOffer::create([
                'housing_request_id' => $request->id,
                'demarcheur_id' => $demarcheur->id,
                'message' => $message,
                'status' => JobOfferStatus::Pending,
            ])->fresh('demarcheur');
        });
    }

    public function choose(User $client, HousingRequest $request, int $offerId): HousingRequest
    {
        return DB::transaction(function () use ($client, $request, $offerId) {
            $request = HousingRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->client_id !== $client->id) {
                throw new ApiException('Demande introuvable.', 404, 'housing_not_found');
            }
            if ($request->status !== HousingStatus::Searching) {
                throw new ApiException('Cette demande n’est plus ouverte.', 409, 'housing_not_searching');
            }

            $offer = HousingOffer::where('housing_request_id', $request->id)->whereKey($offerId)->lockForUpdate()->firstOrFail();
            if ($offer->status !== JobOfferStatus::Pending) {
                throw new ApiException('Cette réponse n’est plus valable.', 409, 'offer_not_pending');
            }

            $request->forceFill([
                'demarcheur_id' => $offer->demarcheur_id,
                'status' => HousingStatus::Assigned,
                'assigned_at' => now(),
            ])->save();
            $offer->forceFill(['status' => JobOfferStatus::Chosen])->save();
            HousingOffer::where('housing_request_id', $request->id)
                ->where('id', '!=', $offer->id)
                ->where('status', JobOfferStatus::Pending)
                ->update(['status' => JobOfferStatus::Declined->value]);

            return $request->fresh(['client', 'demarcheur', 'offers.demarcheur']);
        });
    }

    public function complete(User $actor, HousingRequest $request): HousingRequest
    {
        if (! in_array($actor->id, [$request->client_id, $request->demarcheur_id], true)) {
            throw new ApiException('Demande introuvable.', 404, 'housing_not_found');
        }
        if ($request->status !== HousingStatus::Assigned) {
            throw new ApiException('La recherche doit d’abord être attribuée.', 409, 'housing_bad_status');
        }
        $request->forceFill(['status' => HousingStatus::Completed, 'completed_at' => now()])->save();

        return $request->fresh(['client', 'demarcheur', 'offers.demarcheur']);
    }

    public function cancel(User $actor, HousingRequest $request, ?string $reason = null): HousingRequest
    {
        if (! in_array($actor->id, [$request->client_id, $request->demarcheur_id], true)) {
            throw new ApiException('Demande introuvable.', 404, 'housing_not_found');
        }
        if ($request->status->isFinal()) {
            throw new ApiException('Cette recherche est déjà close.', 409, 'housing_closed');
        }
        $request->forceFill([
            'status' => HousingStatus::Cancelled,
            'cancel_reason' => $reason,
            'cancelled_at' => now(),
        ])->save();

        return $request->fresh(['client', 'demarcheur', 'offers.demarcheur']);
    }

    /** @return list<string> */
    public function zonesOf(User $demarcheur): array
    {
        $demarcheur->loadMissing('profile');

        $profile = $demarcheur->profileForRole(UserRole::Demarcheur)->first();
        $legacyZones = $profile?->specialties ?? [];
        $currentZones = collect($profile?->housing_zones ?? [])
            ->pluck('id')
            ->all();

        return array_values(array_unique(array_merge($legacyZones, $currentZones)));
    }

    private function assertCovers(User $demarcheur, HousingRequest $request): void
    {
        if (! $demarcheur->hasRole(UserRole::Demarcheur)) {
            throw new ApiException('Compte démarcheur requis.', 403, 'forbidden_role');
        }
        $demarcheur->ensureVisible();
        if (! $demarcheur->is_available) {
            throw new ApiException('Passez en service pour répondre.', 409, 'demarcheur_offline');
        }
        if (! in_array($request->zone, $this->zonesOf($demarcheur), true)) {
            throw new ApiException('Cette zone n’est pas dans votre secteur.', 403, 'zone_not_covered');
        }
    }

    private function legacyZoneLabel(string $zone): string
    {
        return match ($zone) {
            'gironde' => 'La Gironde',
            'maarif' => 'Maârif',
            'gauthier' => 'Gauthier',
            'racine' => 'Racine',
            'anfa' => 'Anfa',
            'ain_diab' => 'Aïn Diab',
            'habous' => 'Habous',
            'bourgogne' => 'Bourgogne',
            'oasis' => 'Oasis',
            'sidi_maarouf' => 'Sidi Maârouf',
            default => $zone,
        };
    }
}
