<?php

namespace App\Services;

use App\Actions\AssignTelegramCollector;
use App\Models\SourceChannel;
use App\Models\TelegramAccount;
use App\TelegramAccountStatus;
use App\TelegramSourceAccessStatus;
use Throwable;

class TelegramSourceVerifier
{
    public function __construct(
        private readonly MadelineClientPool $clientPool,
        private readonly AssignTelegramCollector $assignTelegramCollector,
    ) {}

    public function verify(SourceChannel $sourceChannel): SourceChannel
    {
        $resolved = null;

        TelegramAccount::query()
            ->where('is_active', true)
            ->whereIn('status', [TelegramAccountStatus::Authorized, TelegramAccountStatus::Connected])
            ->orderBy('id')
            ->each(function (TelegramAccount $account) use ($sourceChannel, &$resolved): void {
                try {
                    $info = $this->clientPool
                        ->forAccount($account)
                        ->getInfo($sourceChannel->telegramReference());
                    $sourceChannel->telegramAccounts()->syncWithoutDetaching([
                        $account->id => [
                            'access_status' => TelegramSourceAccessStatus::Available->value,
                            'last_checked_at' => now(),
                            'last_error' => null,
                        ],
                    ]);
                    $resolved ??= $this->normalizedPeer($info);
                } catch (Throwable $exception) {
                    $this->clientPool->forget($account);
                    $sourceChannel->telegramAccounts()->syncWithoutDetaching([
                        $account->id => [
                            'access_status' => TelegramSourceAccessStatus::Unavailable->value,
                            'last_checked_at' => now(),
                            'last_error' => $exception->getMessage(),
                        ],
                    ]);
                }
            });

        if (is_array($resolved)) {
            $sourceChannel->update(array_filter([
                'telegram_peer_id' => $resolved['peer_id'],
                'username' => $resolved['username'],
                'title' => $resolved['title'],
            ], static fn (mixed $value): bool => filled($value)));
        }

        $this->assignTelegramCollector->handle($sourceChannel->fresh());

        return $sourceChannel->fresh(['telegramAccounts', 'collectorTelegramAccount']);
    }

    /**
     * @param  array<string, mixed>  $info
     * @return array{peer_id: int|null, username: string|null, title: string|null}
     */
    private function normalizedPeer(array $info): array
    {
        $chat = is_array($info['Chat'] ?? null) ? $info['Chat'] : [];
        $user = is_array($info['User'] ?? null) ? $info['User'] : [];

        return [
            'peer_id' => isset($info['bot_api_id']) ? (int) $info['bot_api_id'] : null,
            'username' => $chat['username'] ?? $user['username'] ?? null,
            'title' => $chat['title'] ?? (trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')) ?: null),
        ];
    }
}
