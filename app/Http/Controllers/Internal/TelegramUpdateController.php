<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\IngestTelegramUpdateRequest;
use App\Jobs\IngestTelegramUpdateJob;
use Illuminate\Http\JsonResponse;

class TelegramUpdateController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(IngestTelegramUpdateRequest $request): JsonResponse
    {
        IngestTelegramUpdateJob::dispatch($request->validated())->onQueue('ingest');

        return response()->json(['accepted' => true], 202);
    }
}
