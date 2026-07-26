<?php

namespace Tests\Unit;

use Amp\Http\Client\HttpClient;
use App\Contracts\MadelineClient;
use App\Models\TelegramAccount;
use App\Services\MadelineClientFactory;
use App\Services\MadelineClientPool;
use App\Telegram\ChannelSourceEventHandler;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MadelineClientPoolTest extends TestCase
{
    public function test_it_reuses_one_client_per_telegram_account(): void
    {
        $firstClient = $this->fakeClient();
        $secondClient = $this->fakeClient();
        $factory = $this->fakeFactory([$firstClient, $secondClient]);
        $pool = new MadelineClientPool($factory);
        $firstAccount = new TelegramAccount(['uuid' => 'account-one']);
        $secondAccount = new TelegramAccount(['uuid' => 'account-two']);

        $this->assertSame($firstClient, $pool->forAccount($firstAccount));
        $this->assertSame($firstClient, $pool->forAccount($firstAccount));
        $this->assertSame($secondClient, $pool->forAccount($secondAccount));
        $this->assertSame(2, $factory->calls);
    }

    public function test_forget_recreates_a_client_after_a_connection_failure(): void
    {
        $firstClient = $this->fakeClient();
        $replacementClient = $this->fakeClient();
        $factory = $this->fakeFactory([$firstClient, $replacementClient]);
        $pool = new MadelineClientPool($factory);
        $account = new TelegramAccount(['uuid' => 'account-one']);

        $this->assertSame($firstClient, $pool->forAccount($account));
        $pool->forget($account);

        $this->assertSame($replacementClient, $pool->forAccount($account));
        $this->assertSame(2, $factory->calls);
    }

    public function test_telegram_bridge_reuses_a_dedicated_system_dns_http_client(): void
    {
        $bridgeHttpClient = new ReflectionMethod(ChannelSourceEventHandler::class, 'bridgeHttpClient');

        $first = $bridgeHttpClient->invoke(null);
        $second = $bridgeHttpClient->invoke(null);

        $this->assertInstanceOf(HttpClient::class, $first);
        $this->assertSame($first, $second);
    }

    private function fakeClient(): MadelineClient
    {
        return new class implements MadelineClient
        {
            public function downloadToFile(mixed $media, string $path): string
            {
                return $path;
            }

            public function getChannelMessage(int|string $peer, int $messageId): ?array
            {
                return null;
            }

            public function getHistory(int|string $peer, int $offsetId, int $limit): array
            {
                return [];
            }

            public function getInfo(int|string $peer): array
            {
                return [];
            }

            public function joinChannel(int|string $channel): void {}
        };
    }

    /**
     * @param  list<MadelineClient>  $clients
     */
    private function fakeFactory(array $clients): MadelineClientFactory
    {
        return new class($clients) extends MadelineClientFactory
        {
            public int $calls = 0;

            /** @param list<MadelineClient> $clients */
            public function __construct(private array $clients) {}

            public function make(TelegramAccount $account): MadelineClient
            {
                return $this->clients[$this->calls++];
            }
        };
    }
}
