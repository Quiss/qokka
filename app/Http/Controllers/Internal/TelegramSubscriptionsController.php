<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\TelegramSubscriptionsRequest;
use App\Models\TelegramAccount;
use App\TelegramAccountStatus;
use Illuminate\Http\JsonResponse;

class TelegramSubscriptionsController extends Controller
{
    public function __invoke(TelegramSubscriptionsRequest $request): JsonResponse
    {
        $account = TelegramAccount::query()
            ->where('uuid', $request->validated('telegram_account_uuid'))
            ->firstOrFail();
        $account->update([
            'status' => TelegramAccountStatus::Connected,
            'last_seen_at' => now(),
            'last_error' => null,
        ]);

        return response()->json([
            'peer_ids' => $account->assignedSourceChannels()
                ->where('is_active', true)
                ->whereNotNull('telegram_peer_id')
                ->orderBy('id')
                ->pluck('telegram_peer_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->values()
                ->all(),
        ]);
    }
}
