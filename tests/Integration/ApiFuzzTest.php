<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application;
use Doctrine\ORM\EntityManager;

use function fopen;
use function fwrite;
use function is_string;
use function json_decode;
use function json_encode;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Stream;

#[CoversClass(Application::class)]
final class ApiFuzzTest extends TestCase
{
    private Application $application;
    private EntityManager $entityManager;
    private string|null $previousForceFallback = null;

    protected function setUp(): void
    {
        $this->entityManager = require __DIR__ . '/../../src/bootstrap.php';
        $logger = new Logger('packing');
        $logger->pushHandler(new StreamHandler('php://temp', Logger::DEBUG));
        $this->application = new Application($this->entityManager, $logger);
        $previousForceFallback = $_ENV['PACKING_FORCE_FALLBACK'] ?? null;
        $this->previousForceFallback = is_string($previousForceFallback) ? $previousForceFallback : null;
        $_ENV['PACKING_FORCE_FALLBACK'] = '1';
    }

    protected function tearDown(): void
    {
        try {
            $this->entityManager->getConnection()->executeStatement('DELETE FROM packing_calculation_cache');
        } catch (\Exception) {
            // Ignore errors if table doesn't exist.
        }

        if ($this->previousForceFallback === null) {
            unset($_ENV['PACKING_FORCE_FALLBACK']);
        } else {
            $_ENV['PACKING_FORCE_FALLBACK'] = $this->previousForceFallback;
        }
    }

    /**
     * @param array<mixed, mixed> $payload
     * @param list<int> $expectedStatuses
     */
    #[DataProvider('payloadProvider')]
    public function testPackEndpointPayloadMatrix(array $payload, array $expectedStatuses): void
    {
        $request = $this->createJsonRequest($payload);
        $response = $this->application->run($request);
        $status = $response->getStatusCode();

        $this->assertContains($status, $expectedStatuses, 'Unexpected HTTP status for payload.');

        $jsonRaw = (string) $response->getBody();
        $decoded = json_decode($jsonRaw, true);
        $this->assertIsArray($decoded, 'Response JSON must be an object.');
    }

    /**
     * @return array<string, array{0: array<mixed, mixed>, 1: list<int>}>
     */
    public static function payloadProvider(): array
    {
        return [
            // Valid numeric payloads - can be 200 or 422 depending on box availability.
            'valid_small' => [
                ['products' => [['id' => 1, 'width' => 1.0, 'height' => 1.0, 'length' => 1.0, 'weight' => 0.5]]],
                [200, 422],
            ],
            'valid_medium' => [
                ['products' => [['id' => 1, 'width' => 4.0, 'height' => 4.0, 'length' => 4.0, 'weight' => 5.0]]],
                [200, 422],
            ],
            'valid_large' => [
                ['products' => [['id' => 1, 'width' => 12.0, 'height' => 15.0, 'length' => 18.0, 'weight' => 20.0]]],
                [200, 422],
            ],
            'valid_multi' => [
                ['products' => [
                    ['id' => 1, 'width' => 2.0, 'height' => 2.0, 'length' => 2.0, 'weight' => 1.0],
                    ['id' => 2, 'width' => 3.0, 'height' => 1.5, 'length' => 4.0, 'weight' => 2.5],
                ]],
                [200, 422],
            ],
            'valid_five_items' => [
                ['products' => [
                    ['id' => 1, 'width' => 1.0, 'height' => 1.0, 'length' => 1.0, 'weight' => 0.5],
                    ['id' => 2, 'width' => 2.0, 'height' => 1.5, 'length' => 3.0, 'weight' => 1.2],
                    ['id' => 3, 'width' => 1.0, 'height' => 2.0, 'length' => 2.5, 'weight' => 0.8],
                    ['id' => 4, 'width' => 4.0, 'height' => 3.0, 'length' => 2.0, 'weight' => 2.2],
                    ['id' => 5, 'width' => 3.5, 'height' => 2.5, 'length' => 4.5, 'weight' => 3.1],
                ]],
                [200, 422],
            ],

            // Invalid types or missing fields - must be 400.
            'invalid_products_null' => [
                ['products' => null],
                [400],
            ],
            'invalid_products_string' => [
                ['products' => 'not-an-array'],
                [400],
            ],
            'invalid_width_string' => [
                ['products' => [['id' => 1, 'width' => 'wide', 'height' => 2, 'length' => 3, 'weight' => 1]]],
                [400],
            ],
            'invalid_height_null' => [
                ['products' => [['id' => 1, 'width' => 2, 'height' => null, 'length' => 3, 'weight' => 1]]],
                [400],
            ],
            'missing_width' => [
                ['products' => [['id' => 1, 'height' => 2, 'length' => 3, 'weight' => 1]]],
                [400],
            ],
            'negative_length' => [
                ['products' => [['id' => 1, 'width' => 2, 'height' => 3, 'length' => -1, 'weight' => 1]]],
                [400],
            ],
            'zero_width' => [
                ['products' => [['id' => 1, 'width' => 0, 'height' => 3, 'length' => 1, 'weight' => 1]]],
                [400],
            ],
            'weight_string' => [
                ['products' => [['id' => 1, 'width' => 2, 'height' => 3, 'length' => 1, 'weight' => 'heavy']]],
                [400],
            ],
            'weight_zero' => [
                ['products' => [['id' => 1, 'width' => 2, 'height' => 3, 'length' => 1, 'weight' => 0]]],
                [400],
            ],
            'missing_weight' => [
                ['products' => [['id' => 1, 'width' => 2, 'height' => 3, 'length' => 1]]],
                [400],
            ],
            'max_width' => [
                ['products' => [['id' => 1, 'width' => 1001, 'height' => 2, 'length' => 3, 'weight' => 1]]],
                [400],
            ],
            'max_height' => [
                ['products' => [['id' => 1, 'width' => 2, 'height' => 1001, 'length' => 3, 'weight' => 1]]],
                [400],
            ],
            'max_length' => [
                ['products' => [['id' => 1, 'width' => 2, 'height' => 3, 'length' => 1001, 'weight' => 1]]],
                [400],
            ],
            'max_weight' => [
                ['products' => [['id' => 1, 'width' => 2, 'height' => 3, 'length' => 1, 'weight' => 20001]]],
                [400],
            ],
            'missing_products' => [
                [],
                [400],
            ],
        ];
    }

    /**
     * @param array<mixed, mixed> $payload
     */
    private function createJsonRequest(array $payload): \Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/pack')
            ->withHeader('Content-Type', 'application/json');
        $body = json_encode($payload);
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open temp stream');
        }
        if ($body === false) {
            throw new \RuntimeException('Unable to encode JSON');
        }
        fwrite($stream, $body);
        rewind($stream);

        return $request->withBody(new Stream($stream));
    }
}
