<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Packing;

use App\Packing\Application\Port\PackingCachePort;
use App\Packing\Domain\Model\Box;
use App\Packing\Domain\Model\Product;
use App\Packing\Infrastructure\Packing\ThirdPartyPackabilityCheckerAdapter;
use App\Packing\Infrastructure\Packing\ThirdPartyPackingException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

#[CoversClass(ThirdPartyPackabilityCheckerAdapter::class)]
#[UsesClass(Box::class)]
#[UsesClass(Product::class)]
final class ThirdPartyPackabilityCheckerAdapterTest extends TestCase
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

    /**
     * @param array<string, mixed> $responseBody
     * @return array{0: ThirdPartyPackabilityCheckerAdapter, 1: InMemoryPackingCache}
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
     * @return array{0: ThirdPartyPackabilityCheckerAdapter, 1: InMemoryPackingCache}
     */
    private function createAdapterWithClient(ClientInterface $httpClient): array
    {
        $httpFactory = new HttpFactory();
        $cache = new InMemoryPackingCache();

        $adapter = new ThirdPartyPackabilityCheckerAdapter(
            $httpClient,
            $httpFactory,
            $httpFactory,
            new NullLogger(),
            'https://example.test/pack',
            'user',
            'key',
            $cache,
        );

        return [$adapter, $cache];
    }

    /**
     * @return array{0: ThirdPartyPackabilityCheckerAdapter, 1: InMemoryPackingCache}
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
