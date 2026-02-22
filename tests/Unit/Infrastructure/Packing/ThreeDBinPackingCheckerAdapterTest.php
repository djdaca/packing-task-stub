<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Packing;

use App\Packing\Application\Port\PackingCachePort;
use App\Packing\Domain\Model\Box;
use App\Packing\Domain\Model\Product;
use App\Packing\Infrastructure\Packing\Api\findBinSize\Dto\Bin;
use App\Packing\Infrastructure\Packing\Api\findBinSize\Dto\Item;
use App\Packing\Infrastructure\Packing\Api\findBinSize\Dto\Params;
use App\Packing\Infrastructure\Packing\Api\findBinSize\Dto\RequestPayload;
use App\Packing\Infrastructure\Packing\Api\findBinSize\Dto\ResponsePayload;
use App\Packing\Infrastructure\Packing\Api\findBinSize\PackingApiRequest;
use App\Packing\Infrastructure\Packing\Api\findBinSize\PackingApiResponse;
use App\Packing\Infrastructure\Packing\ThirdPartyPackingException;
use App\Packing\Infrastructure\Packing\ThreeDBinPackingCheckerAdapter;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;

use function is_array;
use function json_decode;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

#[CoversClass(ThreeDBinPackingCheckerAdapter::class)]
#[UsesClass(Box::class)]
#[UsesClass(Product::class)]
#[UsesClass(PackingApiRequest::class)]
#[UsesClass(PackingApiResponse::class)]
#[UsesClass(Bin::class)]
#[UsesClass(Item::class)]
#[UsesClass(Params::class)]
#[UsesClass(RequestPayload::class)]
#[UsesClass(ResponsePayload::class)]
final class ThreeDBinPackingCheckerAdapterTest extends TestCase
{
    public function testReturnsSelectedBoxWhenAllItemsPackedInSingleBin(): void
    {
        $responseBody = [
            'response' => [
                'status' => 1,
                'bins_packed' => [[
                    'bin_data' => ['id' => 'box-7-1'],
                    'items' => [
                        ['id' => 'item-1'],
                        ['id' => 'item-2'],
                    ],
                ]],
                'not_packed_items' => [],
            ],
        ];

        [$adapter, $cache] = $this->createAdapter($responseBody);

        $result = $adapter->findFirstPackableBox(
            [new Product(2.0, 2.0, 2.0, 1.0), new Product(1.0, 1.0, 1.0, 1.0)],
            [new Box(7, 5.0, 5.0, 5.0, 10.0)],
        );

        self::assertSame(7, $result?->getId());
        self::assertSame(1, $cache->storeCalls);
        self::assertSame(7, $cache->storedBoxId);
    }

    public function testReturnsNullWhenItemsAreDistributedToMultipleBins(): void
    {
        $responseBody = [
            'response' => [
                'status' => 1,
                'bins_packed' => [
                    ['items' => [['id' => 'item-1']]],
                    ['items' => [['id' => 'item-2']]],
                ],
                'not_packed_items' => [],
            ],
        ];

        [$adapter, $cache] = $this->createAdapter($responseBody);

        $result = $adapter->findFirstPackableBox(
            [new Product(2.0, 2.0, 2.0, 1.0), new Product(1.0, 1.0, 1.0, 1.0)],
            [new Box(7, 5.0, 5.0, 5.0, 10.0)],
        );

        self::assertNull($result);
        self::assertSame(0, $cache->storeCalls);
    }

    public function testReturnsNullWhenNotPackedItemsExist(): void
    {
        $responseBody = [
            'response' => [
                'status' => 1,
                'bins_packed' => [[
                    'items' => [['id' => 'item-1']],
                ]],
                'not_packed_items' => [['id' => 'item-2']],
            ],
        ];

        [$adapter, $cache] = $this->createAdapter($responseBody);

        $result = $adapter->findFirstPackableBox(
            [new Product(2.0, 2.0, 2.0, 1.0), new Product(1.0, 1.0, 1.0, 1.0)],
            [new Box(7, 5.0, 5.0, 5.0, 10.0)],
        );

        self::assertNull($result);
        self::assertSame(0, $cache->storeCalls);
    }

    public function testThrowsWhenResponseStatusIsHttpError(): void
    {
        $responseBody = ['response' => ['status' => 0, 'bins_packed' => [], 'not_packed_items' => []]];

        [$adapter] = $this->createAdapter($responseBody, 503);

        $this->expectException(ThirdPartyPackingException::class);
        $adapter->findFirstPackableBox([new Product(2.0, 2.0, 2.0, 1.0)], [new Box(7, 5.0, 5.0, 5.0, 10.0)]);
    }

    public function testThrowsWhenResponseStatusIsNegative(): void
    {
        $responseBody = [
            'response' => [
                'status' => -1,
                'errors' => [],
                'bins_packed' => [],
                'not_packed_items' => [],
            ],
        ];

        [$adapter] = $this->createAdapter($responseBody);

        $this->expectException(ThirdPartyPackingException::class);
        $adapter->findFirstPackableBox([new Product(2.0, 2.0, 2.0, 1.0)], [new Box(7, 5.0, 5.0, 5.0, 10.0)]);
    }

    public function testThrowsWhenApiAccessIsLockedOut(): void
    {
        $responseBody = [
            'response' => [
                'status' => 1,
                'errors' => [['message' => 'Account has been locked out due to abuse']],
                'bins_packed' => [],
                'not_packed_items' => [],
            ],
        ];

        [$adapter] = $this->createAdapter($responseBody);

        $this->expectException(ThirdPartyPackingException::class);
        $adapter->findFirstPackableBox([new Product(2.0, 2.0, 2.0, 1.0)], [new Box(7, 5.0, 5.0, 5.0, 10.0)]);
    }

    public function testThrowsWhenBinsPackedKeyIsMissing(): void
    {
        $responseBody = [
            'response' => [
                'status' => 1,
                'errors' => [],
                'not_packed_items' => [],
            ],
        ];

        [$adapter] = $this->createAdapter($responseBody);

        $this->expectException(ThirdPartyPackingException::class);
        $adapter->findFirstPackableBox([new Product(2.0, 2.0, 2.0, 1.0)], [new Box(7, 5.0, 5.0, 5.0, 10.0)]);
    }

    public function testThrowsWhenBinsPackedHasInvalidType(): void
    {
        $responseBody = [
            'response' => [
                'status' => 1,
                'errors' => [],
                'bins_packed' => 'invalid',
                'not_packed_items' => [],
            ],
        ];

        [$adapter] = $this->createAdapter($responseBody);

        $this->expectException(ThirdPartyPackingException::class);
        $adapter->findFirstPackableBox([new Product(2.0, 2.0, 2.0, 1.0)], [new Box(7, 5.0, 5.0, 5.0, 10.0)]);
    }

    public function testReturnsNullWhenPackedBinIdIsUnknown(): void
    {
        $responseBody = [
            'response' => [
                'status' => 1,
                'errors' => [],
                'bins_packed' => [[
                    'bin_data' => ['id' => 'box-999-1'],
                    'items' => [['id' => 'item-1']],
                ]],
                'not_packed_items' => [],
            ],
        ];

        [$adapter, $cache] = $this->createAdapter($responseBody);

        $result = $adapter->findFirstPackableBox(
            [new Product(2.0, 2.0, 2.0, 1.0)],
            [new Box(7, 5.0, 5.0, 5.0, 10.0)],
        );

        self::assertNull($result);
        self::assertSame(0, $cache->storeCalls);
    }

    public function testReturnsNullWhenResponseNodeIsMissing(): void
    {
        [$adapter] = $this->createAdapterFromRawResponseBody('[]');

        $result = $adapter->findFirstPackableBox(
            [new Product(2.0, 2.0, 2.0, 1.0)],
            [new Box(7, 5.0, 5.0, 5.0, 10.0)],
        );

        self::assertNull($result);
    }

    public function testThrowsWhenResponseBodyIsInvalidJson(): void
    {
        [$adapter] = $this->createAdapterFromRawResponseBody('{invalid-json');

        $this->expectException(ThirdPartyPackingException::class);
        $adapter->findFirstPackableBox(
            [new Product(2.0, 2.0, 2.0, 1.0)],
            [new Box(7, 5.0, 5.0, 5.0, 10.0)],
        );
    }

    public function testThrowsWhenResponseBodyIsEmpty(): void
    {
        [$adapter] = $this->createAdapterFromRawResponseBody('');

        $this->expectException(ThirdPartyPackingException::class);
        $adapter->findFirstPackableBox(
            [new Product(2.0, 2.0, 2.0, 1.0)],
            [new Box(7, 5.0, 5.0, 5.0, 10.0)],
        );
    }

    public function testThrowsWhenHttpStatusIsUnauthorized(): void
    {
        $responseBody = ['response' => ['status' => 0, 'bins_packed' => [], 'not_packed_items' => []]];

        [$adapter] = $this->createAdapter($responseBody, 401);

        $this->expectException(ThirdPartyPackingException::class);
        $adapter->findFirstPackableBox(
            [new Product(2.0, 2.0, 2.0, 1.0)],
            [new Box(7, 5.0, 5.0, 5.0, 10.0)],
        );
    }

    public function testThrowsWhenNetworkFailureOccurs(): void
    {
        $httpClient = new class () implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new NetworkClientException('Connection timeout');
            }
        };

        [$adapter] = $this->createAdapterWithClient($httpClient);

        $this->expectException(ThirdPartyPackingException::class);
        $adapter->findFirstPackableBox(
            [new Product(2.0, 2.0, 2.0, 1.0)],
            [new Box(7, 5.0, 5.0, 5.0, 10.0)],
        );
    }

    public function testBuildsExpectedRequestPayload(): void
    {
        $responseBody = [
            'response' => [
                'status' => 1,
                'errors' => [],
                'bins_packed' => [[
                    'bin_data' => ['id' => 'box-7-1'],
                    'items' => [
                        ['id' => 'item-1'],
                        ['id' => 'item-2'],
                    ],
                ]],
                'not_packed_items' => [],
            ],
        ];

        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($responseBody, JSON_THROW_ON_ERROR),
        );

        $capturingClient = new CapturingHttpClient($response);
        [$adapter] = $this->createAdapterWithClient($capturingClient);

        $adapter->findFirstPackableBox(
            [new Product(2.0, 3.0, 4.0, 1.5), new Product(1.0, 1.5, 2.0, 0.9)],
            [new Box(7, 5.0, 6.0, 7.0, 10.0), new Box(8, 8.0, 9.0, 10.0, 20.0)],
        );

        self::assertNotSame([], $capturingClient->requests);

        $firstRequest = $capturingClient->requests[0];

        $decodedPayload = json_decode((string) $firstRequest->getBody(), true);
        self::assertTrue(is_array($decodedPayload));

        /** @var array<string, mixed> $decodedPayload */
        self::assertSame('user', $decodedPayload['username'] ?? null);
        self::assertSame('key', $decodedPayload['api_key'] ?? null);

        self::assertEquals(
            [
                ['id' => 'box-7-1', 'w' => 5.0, 'h' => 6.0, 'd' => 7.0, 'max_wg' => 10.0],
                ['id' => 'box-8-2', 'w' => 8.0, 'h' => 9.0, 'd' => 10.0, 'max_wg' => 20.0],
            ],
            $decodedPayload['bins'] ?? null,
        );

        self::assertEquals(
            [
                ['id' => 'item-1', 'w' => 2.0, 'h' => 3.0, 'd' => 4.0, 'wg' => 1.5, 'q' => 1],
                ['id' => 'item-2', 'w' => 1.0, 'h' => 1.5, 'd' => 2.0, 'wg' => 0.9, 'q' => 1],
            ],
            $decodedPayload['items'] ?? null,
        );

        self::assertSame(
            ['optimization_mode' => 'bins_number', 'item_distribution' => false],
            $decodedPayload['params'] ?? null,
        );
    }

    public function testSendsExpectedRequestHeaders(): void
    {
        $responseBody = [
            'response' => [
                'status' => 1,
                'errors' => [],
                'bins_packed' => [[
                    'bin_data' => ['id' => 'box-7-1'],
                    'items' => [['id' => 'item-1']],
                ]],
                'not_packed_items' => [],
            ],
        ];

        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($responseBody, JSON_THROW_ON_ERROR),
        );

        $capturingClient = new CapturingHttpClient($response);
        [$adapter] = $this->createAdapterWithClient($capturingClient);

        $adapter->findFirstPackableBox(
            [new Product(2.0, 3.0, 4.0, 1.5)],
            [new Box(7, 5.0, 6.0, 7.0, 10.0)],
        );

        self::assertNotSame([], $capturingClient->requests);
        self::assertSame(['application/json'], $capturingClient->requests[0]->getHeader('Accept'));
        self::assertSame(['application/json'], $capturingClient->requests[0]->getHeader('Content-Type'));
    }

    public function testReusesInMemoryProbeResultForIdenticalRequest(): void
    {
        $responseBody = [
            'response' => [
                'status' => 1,
                'errors' => [],
                'bins_packed' => [[
                    'bin_data' => ['id' => 'box-7-1'],
                    'items' => [['id' => 'item-1']],
                ]],
                'not_packed_items' => [],
            ],
        ];

        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($responseBody, JSON_THROW_ON_ERROR),
        );

        $capturingClient = new CapturingHttpClient($response);
        [$adapter, $cache] = $this->createAdapterWithClient($capturingClient);

        $products = [new Product(2.0, 3.0, 4.0, 1.5)];
        $boxes = [new Box(7, 5.0, 6.0, 7.0, 10.0)];

        $firstResult = $adapter->findFirstPackableBox($products, $boxes);
        $secondResult = $adapter->findFirstPackableBox($products, $boxes);

        self::assertSame(7, $firstResult?->getId());
        self::assertSame(7, $secondResult?->getId());
        self::assertCount(1, $capturingClient->requests);
        self::assertSame(2, $cache->storeCalls);
    }

    /**
     * @param array<string, mixed> $responseBody
    * @return array{0: ThreeDBinPackingCheckerAdapter, 1: InMemoryPackingCache}
     */
    private function createAdapter(array $responseBody, int $statusCode = 200): array
    {
        $response = new Response(
            $statusCode,
            ['Content-Type' => 'application/json'],
            json_encode($responseBody, JSON_THROW_ON_ERROR),
        );

        $httpClient = new class ($response) implements ClientInterface {
            public function __construct(private ResponseInterface $response)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        return $this->createAdapterWithClient($httpClient);
    }

    /**
    * @return array{0: ThreeDBinPackingCheckerAdapter, 1: InMemoryPackingCache}
     */
    private function createAdapterWithClient(ClientInterface $httpClient): array
    {
        $httpFactory = new HttpFactory();
        $cache = new InMemoryPackingCache();

        $adapter = new ThreeDBinPackingCheckerAdapter(
            $httpClient,
            $httpFactory,
            $httpFactory,
            new NullLogger(),
            'https://example.test',
            'user',
            'key',
            $cache,
        );

        return [$adapter, $cache];
    }

    /**
    * @return array{0: ThreeDBinPackingCheckerAdapter, 1: InMemoryPackingCache}
     */
    private function createAdapterFromRawResponseBody(string $rawResponseBody, int $statusCode = 200): array
    {
        $response = new Response(
            $statusCode,
            ['Content-Type' => 'application/json'],
            $rawResponseBody,
        );

        $httpClient = new class ($response) implements ClientInterface {
            public function __construct(private ResponseInterface $response)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        return $this->createAdapterWithClient($httpClient);
    }
}

final class NetworkClientException extends \RuntimeException implements ClientExceptionInterface
{
}

final class CapturingHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    public function __construct(
        private ResponseInterface $response,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return $this->response;
    }
}

final class InMemoryPackingCache implements PackingCachePort
{
    public int $storeCalls = 0;
    public int|null $storedBoxId = null;

    public function getSelectedBox(array $products): int|null
    {
        return null;
    }

    public function storeSelectedBox(array $products, int $selectedBoxId): void
    {
        $this->storeCalls++;
        $this->storedBoxId = $selectedBoxId;
    }
}
