<?php

namespace App\Services\Verification;

use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\ProviderDocument;
use App\Models\User;
use App\Services\Notifications\PushNotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Pieces justificatives des prestataires : depot, revue par un admin et statut par role.
 */
class ProviderVerificationService
{
    public function __construct(private readonly PushNotificationService $push) {}

    /** @return list<string> */
    public static function requiredTypes(UserRole|string|null $role): array
    {
        $role = $role instanceof UserRole ? $role->value : $role;

        return array_values(config("kyc.required.{$role}", []));
    }

    public static function requiresVerification(UserRole|string|null $role): bool
    {
        return (bool) config('kyc.enforce', true) && self::requiredTypes($role) !== [];
    }

    public static function isVerified(User $user, UserRole|string|null $role = null): bool
    {
        $role ??= $user->role;
        if (self::requiredTypes($role) === []) {
            return true;
        }

        return DB::table('user_roles')
            ->where('user_id', $user->id)
            ->where('role', $role instanceof UserRole ? $role->value : $role)
            ->whereNotNull('verified_at')
            ->exists();
    }

    /**
     * Statut de verification du role courant, tel qu'affiche dans l'application.
     *
     * @return array<string, mixed>
     */
    public function overview(User $user): array
    {
        $role = $user->role;
        $required = self::requiredTypes($role);
        $documents = ProviderDocument::where('user_id', $user->id)
            ->where('role', $role->value)
            ->get()
            ->keyBy('type');
        $verifiedAt = DB::table('user_roles')
            ->where('user_id', $user->id)
            ->where('role', $role->value)
            ->value('verified_at');

        $status = match (true) {
            $required === [] => 'not_required',
            $verifiedAt !== null => 'verified',
            $documents->contains('status', ProviderDocument::REJECTED) => 'rejected',
            count(array_intersect($required, $documents->keys()->all())) < count($required) => 'missing',
            default => 'pending',
        };

        return [
            'role' => $role->value,
            'status' => $status,
            'enforced' => (bool) config('kyc.enforce', true),
            'verified_at' => $verifiedAt === null ? null : Carbon::parse($verifiedAt)->toIso8601String(),
            'accepted_formats' => config('kyc.mimes'),
            'max_kb' => (int) config('kyc.max_kb'),
            'documents' => array_map(function (string $type) use ($documents) {
                $document = $documents->get($type);

                return [
                    'type' => $type,
                    'label' => config("kyc.types.{$type}", $type),
                    'status' => $document?->status ?? 'missing',
                    'rejection_reason' => $document?->rejection_reason,
                    'uploaded_at' => $document?->updated_at?->toIso8601String(),
                ];
            }, $required),
        ];
    }

    /** Depose (ou remplace) une piece pour le role courant. */
    public function upload(User $user, string $type, UploadedFile $file): ProviderDocument
    {
        $role = $user->role;
        if (! in_array($type, self::requiredTypes($role), true)) {
            throw new ApiException('Cette pièce n’est pas demandée pour votre activité.', 422, 'document_not_required');
        }

        $existing = ProviderDocument::where('user_id', $user->id)
            ->where('role', $role->value)
            ->where('type', $type)
            ->first();
        if ($existing?->status === ProviderDocument::APPROVED) {
            throw new ApiException('Cette pièce a déjà été validée.', 409, 'document_already_approved');
        }

        $oldPath = $existing?->path;
        $path = $file->store("kyc/{$user->id}", ProviderDocument::DISK);
        if ($path === false) {
            throw new ApiException('Le fichier n’a pas pu être enregistré.', 500, 'storage_failed');
        }

        $document = DB::transaction(function () use ($user, $role, $type, $file, $path, $existing) {
            $document = $existing ?? new ProviderDocument(['user_id' => $user->id, 'role' => $role->value, 'type' => $type]);
            $document->fill([
                'path' => $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime' => $file->getMimeType(),
                'size' => (int) $file->getSize(),
            ]);
            $document->forceFill([
                'status' => ProviderDocument::PENDING,
                'rejection_reason' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ])->save();

            return $document;
        });

        if ($oldPath !== null && $oldPath !== $path) {
            Storage::disk(ProviderDocument::DISK)->delete($oldPath);
        }

        return $document;
    }

    /** Decision d'un admin sur une piece ; valide le role quand toutes les pieces sont approuvees. */
    public function review(User $admin, ProviderDocument $document, bool $approve, ?string $reason): ProviderDocument
    {
        if (! $approve && blank($reason)) {
            throw new ApiException('Indiquez le motif du refus.', 422, 'reason_required');
        }

        [$document, $verified] = DB::transaction(function () use ($admin, $document, $approve, $reason) {
            $document = ProviderDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $document->forceFill([
                'status' => $approve ? ProviderDocument::APPROVED : ProviderDocument::REJECTED,
                'rejection_reason' => $approve ? null : $reason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            $required = self::requiredTypes($document->role);
            $approved = ProviderDocument::where('user_id', $document->user_id)
                ->where('role', $document->role)
                ->whereIn('type', $required)
                ->where('status', ProviderDocument::APPROVED)
                ->count();
            $verified = $required !== [] && $approved === count($required);

            DB::table('user_roles')
                ->where('user_id', $document->user_id)
                ->where('role', $document->role)
                ->update(['verified_at' => $verified ? now() : null, 'updated_at' => now()]);

            return [$document, $verified];
        });

        $label = config("kyc.types.{$document->type}", $document->type);
        $owner = $document->user;
        if (! $approve) {
            $this->push->sendToUser($owner, 'Pièce refusée', "{$label} : {$reason}", ['type' => 'verification']);
        } elseif ($verified) {
            $this->push->sendToUser($owner, 'Profil vérifié', 'Vos pièces sont validées : vous pouvez passer en ligne.', ['type' => 'verification']);
        }

        return $document;
    }
}
