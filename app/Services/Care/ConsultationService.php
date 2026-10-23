<?php

namespace App\Services\Care;

use App\Enums\ConsultationStatus;
use App\Enums\JobOfferStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\Consultation;
use App\Models\ConsultationOffer;
use App\Models\User;
use App\Support\Geo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConsultationService
{
    public function publish(User $client, array $data): Consultation
    {
        return Consultation::create([
            'client_id' => $client->id,
            'specialty' => $data['specialty'],
            'mode' => $data['mode'] ?? 'cabinet',
            'region' => $data['region'] ?? null,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'note' => $data['note'] ?? null,
            'status' => ConsultationStatus::Searching,
        ]);
    }

    public function offer(User $medecin, Consultation $consultation, string $message): ConsultationOffer
    {
        $this->assertCovers($medecin, $consultation);

        return DB::transaction(function () use ($medecin, $consultation, $message) {
            $consultation = Consultation::whereKey($consultation->id)->lockForUpdate()->firstOrFail();
            if ($consultation->status !== ConsultationStatus::Searching) {
                throw new ApiException('Cette demande n’est plus ouverte.', 409, 'consultation_not_searching');
            }

            $existing = ConsultationOffer::where('consultation_id', $consultation->id)
                ->where('medecin_id', $medecin->id)
                ->first();
            if ($existing && $existing->status === JobOfferStatus::Pending) {
                $existing->forceFill(['message' => $message])->save();

                return $existing->fresh('medecin');
            }
            if ($existing) {
                throw new ApiException('Vous ne pouvez plus répondre à cette demande.', 409, 'offer_closed');
            }

            return ConsultationOffer::create([
                'consultation_id' => $consultation->id,
                'medecin_id' => $medecin->id,
                'message' => $message,
                'status' => JobOfferStatus::Pending,
            ])->fresh('medecin');
        });
    }

    /** @return Collection<int, Consultation> */
    public function availableFor(User $medecin): Collection
    {
        if (! $medecin->hasFreshLocation()) {
            return collect();
        }

        $radius = (float) config('job.radius_km', 15);
        $latitudeDelta = $radius / 111;
        $longitudeDelta = $radius / max(1, 111 * cos(deg2rad($medecin->latitude)));
        $rows = Consultation::with(['client', 'offers.medecin'])
            ->where('status', ConsultationStatus::Searching)
            ->whereIn('specialty', $this->specialtiesOf($medecin))
            ->whereBetween('latitude', [
                $medecin->latitude - $latitudeDelta,
                $medecin->latitude + $latitudeDelta,
            ])
            ->whereBetween('longitude', [
                $medecin->longitude - $longitudeDelta,
                $medecin->longitude + $longitudeDelta,
            ])
            ->get();

        return $rows
            ->filter(fn (Consultation $row) => Geo::inServiceArea($medecin, $row->latitude, $row->longitude))
            ->sortBy(fn (Consultation $row) => Geo::km(
                $medecin->latitude,
                $medecin->longitude,
                $row->latitude,
                $row->longitude,
            ))
            ->take(30)
            ->values();
    }

    public function choose(User $client, Consultation $consultation, int $offerId): Consultation
    {
        return DB::transaction(function () use ($client, $consultation, $offerId) {
            $consultation = Consultation::whereKey($consultation->id)->lockForUpdate()->firstOrFail();
            if ($consultation->client_id !== $client->id) {
                throw new ApiException('Demande introuvable.', 404, 'consultation_not_found');
            }
            if ($consultation->status !== ConsultationStatus::Searching) {
                throw new ApiException('Cette demande n’est plus ouverte.', 409, 'consultation_not_searching');
            }

            $offer = ConsultationOffer::where('consultation_id', $consultation->id)->whereKey($offerId)->lockForUpdate()->firstOrFail();
            if ($offer->status !== JobOfferStatus::Pending) {
                throw new ApiException('Cette réponse n’est plus valable.', 409, 'offer_not_pending');
            }

            $consultation->forceFill([
                'medecin_id' => $offer->medecin_id,
                'status' => ConsultationStatus::Assigned,
                'assigned_at' => now(),
            ])->save();
            $offer->forceFill(['status' => JobOfferStatus::Chosen])->save();
            ConsultationOffer::where('consultation_id', $consultation->id)
                ->where('id', '!=', $offer->id)
                ->where('status', JobOfferStatus::Pending)
                ->update(['status' => JobOfferStatus::Declined->value]);

            return $consultation->fresh(['client', 'medecin', 'offers.medecin']);
        });
    }

    public function proposePrice(User $medecin, Consultation $consultation, int $priceDh): Consultation
    {
        return DB::transaction(function () use ($medecin, $consultation, $priceDh) {
            $consultation = Consultation::whereKey($consultation->id)->lockForUpdate()->firstOrFail();
            if ($medecin->id !== $consultation->medecin_id) {
                throw new ApiException('Demande introuvable.', 404, 'consultation_not_found');
            }
            if ($consultation->status !== ConsultationStatus::Assigned) {
                throw new ApiException('La consultation doit d’abord être attribuée.', 409, 'consultation_bad_status');
            }
            if ($consultation->price_status === 'accepted') {
                throw new ApiException('Le prix a déjà été confirmé par le patient.', 409, 'price_already_accepted');
            }
            $consultation->forceFill([
                'price_dh' => $priceDh,
                'price_status' => 'pending',
                'price_proposed_at' => now(),
            ])->save();

            return $consultation->fresh(['client', 'medecin', 'offers.medecin']);
        });
    }

    public function complete(User $actor, Consultation $consultation): Consultation
    {
        return DB::transaction(function () use ($actor, $consultation) {
            $consultation = Consultation::whereKey($consultation->id)->lockForUpdate()->firstOrFail();
            if ($actor->id !== $consultation->client_id) {
                throw new ApiException('Seul le patient peut confirmer le prix et clôturer la consultation.', 403, 'price_confirmation_required');
            }
            if ($consultation->status !== ConsultationStatus::Assigned) {
                throw new ApiException('La consultation doit d’abord être attribuée.', 409, 'consultation_bad_status');
            }
            if ($consultation->price_status !== 'pending' || $consultation->price_dh === null) {
                throw new ApiException('Attendez la proposition de prix du médecin avant de clôturer la consultation.', 409, 'price_not_submitted');
            }
            $consultation->forceFill([
                'status' => ConsultationStatus::Completed,
                'completed_at' => now(),
                'price_status' => 'accepted',
                'price_accepted_at' => now(),
            ])->save();

            return $consultation->fresh(['client', 'medecin', 'offers.medecin']);
        });
    }

    public function cancel(User $actor, Consultation $consultation, ?string $reason = null): Consultation
    {
        return DB::transaction(function () use ($actor, $consultation, $reason) {
            $consultation = Consultation::whereKey($consultation->id)->lockForUpdate()->firstOrFail();
            if (! in_array($actor->id, [$consultation->client_id, $consultation->medecin_id], true)) {
                throw new ApiException('Demande introuvable.', 404, 'consultation_not_found');
            }
            if ($consultation->status->isFinal()) {
                throw new ApiException('Cette demande est déjà close.', 409, 'consultation_closed');
            }
            $consultation->forceFill([
                'status' => ConsultationStatus::Cancelled,
                'cancel_reason' => $reason,
                'cancelled_at' => now(),
            ])->save();
            ConsultationOffer::where('consultation_id', $consultation->id)
                ->where('status', JobOfferStatus::Pending)
                ->update(['status' => JobOfferStatus::Declined->value]);

            return $consultation->fresh(['client', 'medecin', 'offers.medecin']);
        });
    }

    /** @return list<string> */
    public function specialtiesOf(User $medecin): array
    {
        $medecin->loadMissing('profile');

        return array_values($medecin->profile?->specialties ?? []);
    }

    private function assertCovers(User $medecin, Consultation $consultation): void
    {
        if ($medecin->role !== UserRole::Medecin) {
            throw new ApiException('Compte médecin requis.', 403, 'forbidden_role');
        }
        $medecin->ensureVisible();
        if (! $medecin->is_available) {
            throw new ApiException('Passez en service pour répondre.', 409, 'medecin_offline');
        }
        if (! in_array($consultation->specialty->value, $this->specialtiesOf($medecin), true)) {
            throw new ApiException('Cette spécialité n’est pas la vôtre.', 403, 'specialty_not_covered');
        }
        if ($consultation->latitude === null || $consultation->longitude === null
            || ! Geo::inServiceArea($medecin, $consultation->latitude, $consultation->longitude)) {
            throw new ApiException('Cette demande est hors de votre zone de service.', 403, 'consultation_outside_service_area');
        }
    }
}
