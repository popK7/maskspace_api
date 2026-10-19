<?php

namespace App\Services\Chat;

use App\Enums\BookingStatus;
use App\Enums\HousingStatus;
use App\Enums\ConversationChannel;
use App\Enums\ConversationSubject;
use App\Enums\JobStatus;
use App\Enums\OrderStatus;
use App\Enums\RideStatus;
use App\Enums\TransferStatus;
use App\Exceptions\ApiException;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\HousingRequest;
use App\Models\Job;
use App\Models\LodgingBooking;
use App\Models\MoneyTransfer;
use App\Models\Order;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChatService
{
    /** @return Collection<int, Conversation> */
    public function list(User $user, ConversationSubject $type, int $subjectId): Collection
    {
        [$canonicalType, $canonicalId, $subject] = $this->canonical($type, $subjectId);
        $this->assertCanSee($user, $canonicalType, $subject);

        $channels = $this->channelsFor($user, $canonicalType, $subject);
        if ($channels === []) {
            abort(404);
        }

        return collect($channels)->map(function (ConversationChannel $channel) use ($canonicalType, $canonicalId, $user, $subject) {
            $conversation = Conversation::firstOrCreate([
                'subject_type' => $canonicalType->value,
                'subject_id' => $canonicalId,
                'channel' => $channel->value,
            ]);
            $conversation->load(['lastMessage.sender']);
            $this->present($conversation, $user, $subject);

            return $conversation;
        })->values();
    }

    public function show(User $user, Conversation $conversation): Conversation
    {
        $subject = $this->subjectOf($conversation);
        $this->assertParticipant($user, $conversation, $subject);
        $conversation->load(['messages' => fn ($q) => $q->orderBy('id')->limit(200), 'messages.sender']);
        $this->present($conversation, $user, $subject);

        return $conversation;
    }

    public function post(User $user, Conversation $conversation, string $body): ChatMessage
    {
        $subject = $this->subjectOf($conversation);
        $this->assertParticipant($user, $conversation, $subject);

        if (! $this->canWrite($conversation, $subject)) {
            throw new ApiException('Cette conversation est close.', 409, 'chat_closed');
        }

        $body = trim($body);
        if ($body === '') {
            throw new ApiException('Le message ne peut pas être vide.', 422, 'empty_message');
        }

        return DB::transaction(function () use ($conversation, $user, $body) {
            $message = $conversation->messages()->create([
                'sender_id' => $user->id,
                'body' => $body,
            ]);
            $conversation->forceFill(['last_message_at' => now()])->save();

            return $message->load('sender');
        });
    }

    /**
     * Une course rattachee a une intervention ou un transfert se discute
     * sur la demande parente (un seul fil, pas un doublon par course).
     *
     * @return array{0: ConversationSubject, 1: int, 2: Order|Job|Ride|MoneyTransfer|LodgingBooking|HousingRequest}
     */
    private function canonical(ConversationSubject $type, int $id): array
    {
        $subject = $this->load($type, $id);
        if ($type === ConversationSubject::Ride && $subject instanceof Ride && $subject->job_id) {
            $job = $subject->job ?? Job::with(['client', 'artisan', 'ride.driver', 'ride.passenger'])->findOrFail($subject->job_id);
            $job->setRelation('ride', $subject);

            return [ConversationSubject::Job, $job->id, $job];
        }
        if ($type === ConversationSubject::Ride && $subject instanceof Ride && $subject->transfer_id) {
            $transfer = $subject->transfer
                ?? MoneyTransfer::with(['client', 'changeur', 'ride.driver', 'ride.passenger'])->findOrFail($subject->transfer_id);
            $transfer->setRelation('ride', $subject);

            return [ConversationSubject::Transfer, $transfer->id, $transfer];
        }

        return [$type, $id, $subject];
    }

    private function load(ConversationSubject $type, int $id): Order|Job|Ride|MoneyTransfer|LodgingBooking|HousingRequest
    {
        return match ($type) {
            ConversationSubject::Order => Order::with(['shop.owner', 'customer', 'livreur'])->findOrFail($id),
            ConversationSubject::Job => Job::with(['client', 'artisan', 'ride.driver', 'ride.passenger'])->findOrFail($id),
            ConversationSubject::Ride => Ride::with(['passenger', 'driver', 'job.client', 'job.artisan', 'transfer.client', 'transfer.changeur'])->findOrFail($id),
            ConversationSubject::Transfer => MoneyTransfer::with(['client', 'changeur', 'ride.driver', 'ride.passenger'])->findOrFail($id),
            ConversationSubject::Booking => LodgingBooking::with(['client', 'host', 'lodging'])->findOrFail($id),
            ConversationSubject::Housing => HousingRequest::with(['client', 'demarcheur'])->findOrFail($id),
        };
    }

    private function subjectOf(Conversation $conversation): Order|Job|Ride|MoneyTransfer|LodgingBooking|HousingRequest
    {
        return $this->load($conversation->subject_type, $conversation->subject_id);
    }

    private function assertCanSee(User $user, ConversationSubject $type, Order|Job|Ride|MoneyTransfer|LodgingBooking|HousingRequest $subject): void
    {
        if (! $this->canSee($user, $type, $subject)) {
            abort(404);
        }
    }

    public function assertParticipant(User $user, Conversation $conversation, Order|Job|Ride|MoneyTransfer|LodgingBooking|HousingRequest|null $subject = null): void
    {
        $subject ??= $this->subjectOf($conversation);
        if (! $this->isParticipant($user, $conversation->channel, $subject)) {
            abort(404);
        }
    }

    private function canSee(User $user, ConversationSubject $type, Order|Job|Ride|MoneyTransfer|LodgingBooking|HousingRequest $subject): bool
    {
        return match (true) {
            $subject instanceof Order => $user->id === $subject->customer_id
                || $user->id === $subject->shop?->owner_id
                || $user->id === $subject->livreur_id,
            $subject instanceof Job => $user->id === $subject->client_id
                || $user->id === $subject->artisan_id
                || $user->id === $subject->ride?->driver_id,
            $subject instanceof MoneyTransfer => $user->id === $subject->client_id
                || $user->id === $subject->changeur_id
                || $user->id === $subject->ride?->driver_id,
            $subject instanceof Ride => $user->id === $subject->passenger_id
                || $user->id === $subject->driver_id
                || $user->id === $subject->job?->client_id
                || $user->id === $subject->job?->artisan_id
                || $user->id === $subject->transfer?->client_id
                || $user->id === $subject->transfer?->changeur_id,
            $subject instanceof LodgingBooking => $user->id === $subject->client_id
                || $user->id === $subject->host_id,
            $subject instanceof HousingRequest => $user->id === $subject->client_id
                || $user->id === $subject->demarcheur_id,
            default => false,
        };
    }

    /** @return list<ConversationChannel> */
    private function channelsFor(User $user, ConversationSubject $type, Order|Job|Ride|MoneyTransfer|LodgingBooking|HousingRequest $subject): array
    {
        return match (true) {
            $subject instanceof Order => $this->orderChannels($user, $subject),
            $subject instanceof Job => $this->jobChannels($user, $subject),
            $subject instanceof MoneyTransfer => $this->transferChannels($user, $subject),
            $subject instanceof Ride => $this->rideChannels($user, $subject),
            $subject instanceof LodgingBooking => $this->bookingChannels($user, $subject),
            $subject instanceof HousingRequest => $this->housingChannels($user, $subject),
            default => [],
        };
    }

    /** @return list<ConversationChannel> */
    private function orderChannels(User $user, Order $order): array
    {
        $ownerId = $order->shop?->owner_id;
        $out = [];
        if ($user->id === $order->customer_id || $user->id === $ownerId) {
            $out[] = ConversationChannel::CustomerShop;
        }
        if ($order->livreur_id) {
            if ($user->id === $ownerId || $user->id === $order->livreur_id) {
                $out[] = ConversationChannel::ShopCourier;
            }
            if ($user->id === $order->customer_id || $user->id === $order->livreur_id) {
                $out[] = ConversationChannel::CustomerCourier;
            }
        }

        return $out;
    }

    /** @return list<ConversationChannel> */
    private function jobChannels(User $user, Job $job): array
    {
        $out = [];
        if ($job->artisan_id && ($user->id === $job->client_id || $user->id === $job->artisan_id)) {
            $out[] = ConversationChannel::CustomerArtisan;
        }
        $driverId = $job->ride?->driver_id;
        if ($driverId) {
            if ($user->id === $job->artisan_id || $user->id === $driverId) {
                $out[] = ConversationChannel::ArtisanDriver;
            }
            if ($user->id === $job->client_id || $user->id === $driverId) {
                $out[] = ConversationChannel::CustomerDriver;
            }
        }

        return $out;
    }

    /** @return list<ConversationChannel> */
    private function transferChannels(User $user, MoneyTransfer $transfer): array
    {
        $out = [];
        if ($transfer->changeur_id && ($user->id === $transfer->client_id || $user->id === $transfer->changeur_id)) {
            $out[] = ConversationChannel::CustomerChangeur;
        }
        $driverId = $transfer->ride?->driver_id;
        if ($driverId) {
            if ($user->id === $transfer->changeur_id || $user->id === $driverId) {
                $out[] = ConversationChannel::ChangeurDriver;
            }
            if ($user->id === $transfer->client_id || $user->id === $driverId) {
                $out[] = ConversationChannel::CustomerDriver;
            }
        }

        return $out;
    }

    /** @return list<ConversationChannel> */
    /** @return list<ConversationChannel> */
    private function housingChannels(User $user, HousingRequest $request): array
    {
        if ($request->demarcheur_id && ($user->id === $request->client_id || $user->id === $request->demarcheur_id)) {
            return [ConversationChannel::CustomerDemarcheur];
        }

        return [];
    }

    /** @return list<ConversationChannel> */
    private function bookingChannels(User $user, LodgingBooking $booking): array
    {
        if ($user->id === $booking->client_id || $user->id === $booking->host_id) {
            return [ConversationChannel::CustomerHost];
        }

        return [];
    }

    /** @return list<ConversationChannel> */
    private function rideChannels(User $user, Ride $ride): array
    {
        if ($ride->driver_id && ($user->id === $ride->passenger_id || $user->id === $ride->driver_id)) {
            return [ConversationChannel::PassengerDriver];
        }

        return [];
    }

    private function isParticipant(User $user, ConversationChannel $channel, Order|Job|Ride|MoneyTransfer|LodgingBooking|HousingRequest $subject): bool
    {
        $ids = $this->participantIds($channel, $subject);

        return in_array($user->id, $ids, true);
    }

    /** @return list<int> */
    private function participantIds(ConversationChannel $channel, Order|Job|Ride|MoneyTransfer|LodgingBooking|HousingRequest $subject): array
    {
        return array_values(array_filter(match (true) {
            $subject instanceof Order => match ($channel) {
                ConversationChannel::CustomerShop => [$subject->customer_id, $subject->shop?->owner_id],
                ConversationChannel::ShopCourier => [$subject->shop?->owner_id, $subject->livreur_id],
                ConversationChannel::CustomerCourier => [$subject->customer_id, $subject->livreur_id],
                default => [],
            },
            $subject instanceof Job => match ($channel) {
                ConversationChannel::CustomerArtisan => [$subject->client_id, $subject->artisan_id],
                ConversationChannel::ArtisanDriver => [$subject->artisan_id, $subject->ride?->driver_id],
                ConversationChannel::CustomerDriver => [$subject->client_id, $subject->ride?->driver_id],
                default => [],
            },
            $subject instanceof MoneyTransfer => match ($channel) {
                ConversationChannel::CustomerChangeur => [$subject->client_id, $subject->changeur_id],
                ConversationChannel::ChangeurDriver => [$subject->changeur_id, $subject->ride?->driver_id],
                ConversationChannel::CustomerDriver => [$subject->client_id, $subject->ride?->driver_id],
                default => [],
            },
            $subject instanceof Ride => match ($channel) {
                ConversationChannel::PassengerDriver => [$subject->passenger_id, $subject->driver_id],
                default => [],
            },
            $subject instanceof LodgingBooking => match ($channel) {
                ConversationChannel::CustomerHost => [$subject->client_id, $subject->host_id],
                default => [],
            },
            $subject instanceof HousingRequest => match ($channel) {
                ConversationChannel::CustomerDemarcheur => [$subject->client_id, $subject->demarcheur_id],
                default => [],
            },
            default => [],
        }, fn ($id) => $id !== null));
    }

    private function canWrite(Conversation $conversation, Order|Job|Ride|MoneyTransfer|LodgingBooking|HousingRequest $subject): bool
    {
        return match (true) {
            $subject instanceof Order => ! in_array($subject->status, [OrderStatus::Rejected, OrderStatus::Cancelled], true),
            $subject instanceof Job => $subject->status !== JobStatus::Cancelled,
            $subject instanceof MoneyTransfer => $subject->status !== TransferStatus::Cancelled,
            $subject instanceof Ride => $subject->status !== RideStatus::Cancelled,
            $subject instanceof LodgingBooking => ! in_array($subject->status, [BookingStatus::Cancelled, BookingStatus::Rejected], true),
            $subject instanceof HousingRequest => $subject->status !== HousingStatus::Cancelled,
            default => false,
        };
    }

    private function present(Conversation $conversation, User $viewer, Order|Job|Ride|MoneyTransfer|LodgingBooking|HousingRequest $subject): void
    {
        $peer = $this->peerFor($viewer, $conversation->channel, $subject);
        $conversation->setAttribute('presented_title', $this->titleFor($viewer, $conversation->channel, $subject, $peer));
        $conversation->setAttribute('presented_peer', $peer ? [
            'id' => $peer->id,
            'name' => $peer->name,
        ] : null);
        $conversation->setAttribute('presented_can_write', $this->canWrite($conversation, $subject));
    }

    private function peerFor(User $viewer, ConversationChannel $channel, Order|Job|Ride|MoneyTransfer|LodgingBooking|HousingRequest $subject): ?User
    {
        return match (true) {
            $subject instanceof Order => match ($channel) {
                ConversationChannel::CustomerShop => $viewer->id === $subject->customer_id
                    ? $subject->shop?->owner
                    : $subject->customer,
                ConversationChannel::ShopCourier => $viewer->id === $subject->livreur_id
                    ? $subject->shop?->owner
                    : $subject->livreur,
                ConversationChannel::CustomerCourier => $viewer->id === $subject->customer_id
                    ? $subject->livreur
                    : $subject->customer,
                default => null,
            },
            $subject instanceof Job => match ($channel) {
                ConversationChannel::CustomerArtisan => $viewer->id === $subject->client_id
                    ? $subject->artisan
                    : $subject->client,
                ConversationChannel::ArtisanDriver => $viewer->id === $subject->artisan_id
                    ? $subject->ride?->driver
                    : $subject->artisan,
                ConversationChannel::CustomerDriver => $viewer->id === $subject->client_id
                    ? $subject->ride?->driver
                    : $subject->client,
                default => null,
            },
            $subject instanceof MoneyTransfer => match ($channel) {
                ConversationChannel::CustomerChangeur => $viewer->id === $subject->client_id
                    ? $subject->changeur
                    : $subject->client,
                ConversationChannel::ChangeurDriver => $viewer->id === $subject->changeur_id
                    ? $subject->ride?->driver
                    : $subject->changeur,
                ConversationChannel::CustomerDriver => $viewer->id === $subject->client_id
                    ? $subject->ride?->driver
                    : $subject->client,
                default => null,
            },
            $subject instanceof Ride => match ($channel) {
                ConversationChannel::PassengerDriver => $viewer->id === $subject->passenger_id
                    ? $subject->driver
                    : $subject->passenger,
                default => null,
            },
            $subject instanceof LodgingBooking => match ($channel) {
                ConversationChannel::CustomerHost => $viewer->id === $subject->client_id
                    ? $subject->host
                    : $subject->client,
                default => null,
            },
            $subject instanceof HousingRequest => match ($channel) {
                ConversationChannel::CustomerDemarcheur => $viewer->id === $subject->client_id
                    ? $subject->demarcheur
                    : $subject->client,
                default => null,
            },
            default => null,
        };
    }

    private function titleFor(User $viewer, ConversationChannel $channel, Order|Job|Ride|MoneyTransfer|LodgingBooking|HousingRequest $subject, ?User $peer): string
    {
        if ($subject instanceof Order) {
            return match ($channel) {
                ConversationChannel::CustomerShop => $viewer->id === $subject->customer_id
                    ? ($subject->shop?->name ?? 'Boutique')
                    : ($peer?->name ?? 'Client'),
                ConversationChannel::ShopCourier => $viewer->id === $subject->livreur_id
                    ? ($subject->shop?->name ?? 'Boutique')
                    : ($peer?->name ?? 'Livreur'),
                ConversationChannel::CustomerCourier => $peer?->name ?? ($viewer->id === $subject->customer_id ? 'Livreur' : 'Client'),
                default => $peer?->name ?? 'Discussion',
            };
        }

        return $peer?->name ?? 'Discussion';
    }
}
