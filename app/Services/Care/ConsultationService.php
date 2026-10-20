<?php

namespace App\Services\Care;

use App\Enums\ConsultationStatus;
use App\Enums\JobOfferStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\Consultation;
use App\Models\ConsultationOffer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConsultationService
{
    public function publish(User $client, array $data): Consultation
    {
        $active = Consultation::where('client_id', $client->id)
            ->whereIn('status', [ConsultationStatus::Searching, ConsultationStatus::Assigned])
            ->exists();
        if ($active) {
            throw new ApiException('Vous avez déjà une demande Allo Doctors en cours.', 409, 'consultation_active');
        }

        return Consultation::create([
            'client_id' => $client->id,
            'specialty' => $data['specialty'],
            'mode' => $data['mode'] ?? 'cabinet',
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

    public function complete(User $actor, Consultation $consultation): Consultation
    {
        if (! in_array($actor->id, [$consultation->client_id, $consultation->medecin_id], true)) {
            throw new ApiException('Demande introuvable.', 404, 'consultation_not_found');
        }
        if ($consultation->status !== ConsultationStatus::Assigned) {
            throw new ApiException('La consultation doit d’abord être attribuée.', 409, 'consultation_bad_status');
        }
        $consultation->forceFill(['status' => ConsultationStatus::Completed, 'completed_at' => now()])->save();

        return $consultation->fresh(['client', 'medecin', 'offers.medecin']);
    }

    public function cancel(User $actor, Consultation $consultation, ?string $reason = null): Consultation
    {
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

        return $consultation->fresh(['client', 'medecin', 'offers.medecin']);
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
    }
}
