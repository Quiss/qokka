<?php

namespace Tests\Unit;

use App\Listeners\RestartQueueWorkerAfterStaleMadelinePostgresConnection;
use App\Services\MadelineClientPool;
use App\Services\MadelineProtoClient;
use Tests\TestCase;

class RestartQueueWorkerAfterStaleMadelinePostgresConnectionTest extends TestCase
{
    public function test_the_stale_madeline_queue_worker_workaround_was_removed(): void
    {
        $this->assertFalse(class_exists(
            RestartQueueWorkerAfterStaleMadelinePostgresConnection::class,
        ));
    }

    public function test_horizon_has_no_madeline_client_service(): void
    {
        $this->assertFalse(class_exists(MadelineClientPool::class));
        $this->assertFalse(class_exists(MadelineProtoClient::class));
    }
}
