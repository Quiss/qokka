<?php

namespace App\Actions;

use App\Contracts\MadelineClient;

class FindDeletedTelegramChannelParticipants
{
    private const int PageSize = 200;

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(MadelineClient $client, int|string $channel): array
    {
        $deletedParticipants = [];
        $offset = 0;

        do {
            $response = $client->getChannelParticipants(
                $channel,
                $offset,
                self::PageSize,
            );
            $participants = is_array($response['participants'] ?? null)
                ? $response['participants']
                : [];
            $users = is_array($response['users'] ?? null)
                ? $response['users']
                : [];
            $participantIds = [];

            foreach ($participants as $participant) {
                if (! is_array($participant)) {
                    continue;
                }

                $participantId = $this->participantUserId($participant);

                if ($participantId !== null) {
                    $participantIds[$participantId] = true;
                }
            }

            foreach ($users as $user) {
                if (! is_array($user)) {
                    continue;
                }

                $userId = (int) ($user['id'] ?? 0);

                if (
                    $userId > 0
                    && isset($participantIds[$userId])
                    && ($user['deleted'] ?? false) === true
                ) {
                    $deletedParticipants[$userId] = $user;
                }
            }

            $received = count($participants);
            $offset += $received;
            $total = max($offset, (int) ($response['count'] ?? $offset));
        } while ($received > 0 && $offset < $total);

        return array_values($deletedParticipants);
    }

    /** @param array<string, mixed> $participant */
    private function participantUserId(array $participant): ?int
    {
        $userId = (int) ($participant['user_id'] ?? 0);

        if ($userId > 0) {
            return $userId;
        }

        $peer = $participant['peer'] ?? null;

        if (is_array($peer)) {
            $userId = (int) ($peer['user_id'] ?? 0);

            return $userId > 0 ? $userId : null;
        }

        if (is_int($peer) && $peer > 0) {
            return $peer;
        }

        return null;
    }
}
