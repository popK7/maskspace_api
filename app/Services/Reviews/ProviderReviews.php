<?php

namespace App\Services\Reviews;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Notifications\PushNotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Notation d'un prestataire par le client sur une demande terminee
 * (consultation, soins/beaute, logement). Les demandes portent `client_id`,
 * une colonne prestataire, `status` (enum avec la valeur "completed"),
 * `rating` et `review`.
 */
class ProviderReviews
{
    public function __construct(private readonly PushNotificationService $notifications) {}

    /**
     * @template T of Model
     *
     * @param  T  $subject
     * @return T
     */
    public function review(
        Model $subject,
        string $providerColumn,
        User $client,
        int $rating,
        ?string $comment,
        string $notFoundMessage,
        string $notificationBody,
    ): Model {
        $updated = DB::transaction(function () use ($subject, $providerColumn, $client, $rating, $comment, $notFoundMessage) {
            /** @var Model $locked */
            $locked = $subject->newQuery()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
            if ((int) $locked->client_id !== $client->id) {
                throw new ApiException($notFoundMessage, 404, 'not_found');
            }
            if ($locked->status->value !== 'completed' || $locked->{$providerColumn} === null) {
                throw new ApiException('Vous pourrez noter une fois la prestation terminée.', 409, 'not_completed');
            }
            if ($locked->rating !== null) {
                throw new ApiException('Vous avez déjà donné votre avis.', 409, 'already_reviewed');
            }

            $locked->forceFill(['rating' => $rating, 'review' => $comment])->save();

            return $locked;
        });

        $this->notifications->sendToUser(
            User::find($updated->{$providerColumn}),
            'Nouvel avis',
            "{$notificationBody} {$rating}/5.",
            ['type' => 'review', 'rating' => $rating],
        );

        return $updated;
    }

    /**
     * Note moyenne par prestataire.
     *
     * @param  class-string<Model>  $modelClass
     * @param  list<int>  $providerIds
     * @return array<int, array{avg: float, count: int}>
     */
    public static function averages(string $modelClass, string $providerColumn, array $providerIds): array
    {
        if ($providerIds === []) {
            return [];
        }

        return $modelClass::query()
            ->whereIn($providerColumn, $providerIds)
            ->whereNotNull('rating')
            ->selectRaw("{$providerColumn} as provider_id, AVG(rating) as avg, COUNT(*) as n")
            ->groupBy($providerColumn)
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->provider_id => ['avg' => round((float) $r->avg, 1), 'count' => (int) $r->n]])
            ->all();
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array{rating: float|null, rating_count: int}
     */
    public static function summaryFor(string $modelClass, string $providerColumn, int $providerId): array
    {
        $r = self::averages($modelClass, $providerColumn, [$providerId])[$providerId] ?? null;

        return ['rating' => $r['avg'] ?? null, 'rating_count' => $r['count'] ?? 0];
    }
}
