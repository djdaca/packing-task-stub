<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Application;
use App\Packing\Application\UseCase\PackProductsHandler;
use App\Packing\Domain\Entity\Packaging;
use App\Packing\Domain\Entity\PackingCalculationCache;
use App\Packing\Domain\Model\Box;
use App\Packing\Domain\Model\Product;
use App\Packing\Infrastructure\Packing\FallbackPackabilityCheckerAdapter;
use App\Packing\Infrastructure\Packing\ForcedFallbackPackabilityChecker;
use App\Packing\Infrastructure\Packing\ResilientPackabilityChecker;
use App\Packing\Infrastructure\Packing\ThirdPartyPackabilityCheckerAdapter;
use App\Packing\Infrastructure\Persistence\DoctrineBoxCatalogAdapter;
use App\Packing\Infrastructure\Persistence\DoctrinePackingCacheAdapter;
use App\Packing\Interface\Http\ProductRequestMapper;
use App\Shared\Env;
use Doctrine\ORM\EntityManager;

use function file_get_contents;
use function fopen;
use function fwrite;
use function is_array;
use function json_decode;
use function json_encode;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Stream;

/**
 * Tests that API gracefully handles third-party service errors
 * and falls back to local calculation algorithm
 */
#[CoversClass(Application::class)]
#[CoversClass(PackProductsHandler::class)]
#[CoversClass(Packaging::class)]
#[CoversClass(PackingCalculationCache::class)]
#[CoversClass(Box::class)]
#[CoversClass(Product::class)]
#[CoversClass(FallbackPackabilityCheckerAdapter::class)]
#[CoversClass(ForcedFallbackPackabilityChecker::class)]
#[CoversClass(ResilientPackabilityChecker::class)]
#[CoversClass(ThirdPartyPackabilityCheckerAdapter::class)]
#[CoversClass(DoctrineBoxCatalogAdapter::class)]
#[CoversClass(DoctrinePackingCacheAdapter::class)]
#[CoversClass(ProductRequestMapper::class)]
#[CoversClass(Env::class)]
final class ApplicationAPIErrorsTest extends TestCase
{
    private Application $application;
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = require __DIR__ . '/../../src/bootstrap.php';
        $logger = new Logger('packing');
        $logger->pushHandler(new StreamHandler('php://temp', Logger::DEBUG));
        $this->application = new Application($this->entityManager, $logger);
    }

    protected function tearDown(): void
    {
        // Clear cache after each test
        try {
            $this->entityManager->getConnection()->executeStatement('DELETE FROM packing_calculation_cache');
        } catch (\Exception) {
            // Ignore errors if table doesn't exist
        }
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

    /**
     * Test that API still works when third-party service is unavailable
     * The system should gracefully fallback to local calculation algorithm
     */
    public function testPackEndpointWithValidProductsSucceeds(): void
    {
        $previousForceFallback = $_ENV['PACKING_FORCE_FALLBACK'] ?? null;
        $_ENV['PACKING_FORCE_FALLBACK'] = '1';

        try {
            $fixturesRaw = file_get_contents(__DIR__ . '/fixtures/pack-fixtures.json');
            if ($fixturesRaw === false) {
                throw new \RuntimeException('Unable to read fixture file');
            }
            $fixtures = json_decode($fixturesRaw, true);
            if (!is_array($fixtures) || !isset($fixtures['fits']) || !is_array($fixtures['fits'])) {
                throw new \RuntimeException('Invalid fixture structure');
            }

            $request = $this->createJsonRequest($fixtures['fits']);
            $response = $this->application->run($request);

            // Should successfully pack even if third-party API is forced to fail
            $this->assertSame(200, $response->getStatusCode());
            $jsonRaw = (string) $response->getBody();
            $json = json_decode($jsonRaw, true);
            $this->assertIsArray($json);
            $this->assertArrayHasKey('box', $json);

            // Verify box has required fields
            $box = $json['box'];
            $this->assertIsArray($box);
            $this->assertArrayHasKey('id', $box);
            $this->assertArrayHasKey('width', $box);
            $this->assertArrayHasKey('height', $box);
            $this->assertArrayHasKey('length', $box);
            $this->assertArrayHasKey('maxWeight', $box);
        } finally {
            if ($previousForceFallback === null) {
                unset($_ENV['PACKING_FORCE_FALLBACK']);
            } else {
                $_ENV['PACKING_FORCE_FALLBACK'] = $previousForceFallback;
            }
        }
    }

    /**
     * Test that input validation catches oversized products early
     * This prevents unnecessary API calls for impossible-to-pack requests
     */
    public function testPackEndpointValidatesProductDimensions(): void
    {
        // Product with invalid (too large) dimensions
        $payload = ['products' => [['width' => 2000, 'height' => 2000, 'length' => 2000, 'weight' => 1]]];
        $request = $this->createJsonRequest($payload);
        $response = $this->application->run($request);

        // Should be rejected at validation layer
        $this->assertSame(400, $response->getStatusCode());
        $jsonRaw = (string) $response->getBody();
        $json = json_decode($jsonRaw, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('error', $json);
        // Error message should mention dimension constraint
        $this->assertIsString($json['error']);
        $this->assertStringContainsString('must be', $json['error']);
    }

    /**
     * Test that input validation catches invalid product weights
     */
    public function testPackEndpointValidatesProductWeight(): void
    {
        // Product with excessive weight
        $payload = ['products' => [['width' => 10, 'height' => 10, 'length' => 10, 'weight' => 20000]]];
        $request = $this->createJsonRequest($payload);
        $response = $this->application->run($request);

        // Should be rejected at validation layer
        $this->assertSame(400, $response->getStatusCode());
        $jsonRaw = (string) $response->getBody();
        $json = json_decode($jsonRaw, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('error', $json);
    }

    /**
     * Test that system handles zero or negative dimensions gracefully
     */
    public function testPackEndpointRejectsInvalidDimensions(): void
    {
        $invalidCases = [
            ['width' => 0, 'height' => 10, 'length' => 10, 'weight' => 1],
            ['width' => -5, 'height' => 10, 'length' => 10, 'weight' => 1],
            ['width' => 10, 'height' => 0, 'length' => 10, 'weight' => 1],
            ['width' => 10, 'height' => 10, 'length' => 0, 'weight' => 1],
        ];

        foreach ($invalidCases as $invalidCase) {
            $payload = ['products' => [$invalidCase]];
            $request = $this->createJsonRequest($payload);
            $response = $this->application->run($request);

            $this->assertSame(400, $response->getStatusCode(), 'Invalid dimensions should be rejected');
            $jsonRaw = (string) $response->getBody();
            $json = json_decode($jsonRaw, true);
            $this->assertIsArray($json);
            $this->assertArrayHasKey('error', $json);
        }
    }

    /**
     * Test that empty product list is rejected
     */
    public function testPackEndpointRejectsEmptyProductList(): void
    {
        $payload = ['products' => []];
        $request = $this->createJsonRequest($payload);
        $response = $this->application->run($request);

        $this->assertSame(400, $response->getStatusCode());
        $jsonRaw = (string) $response->getBody();
        $json = json_decode($jsonRaw, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('error', $json);
        $this->assertIsString($json['error']);
        $this->assertStringContainsString('at least one', $json['error']);
    }

    /**
     * Test that missing products field is rejected
     */
    public function testPackEndpointRejectsMissingProductsField(): void
    {
        $payload = [];
        $request = $this->createJsonRequest($payload);
        $response = $this->application->run($request);

        $this->assertSame(400, $response->getStatusCode());
        $jsonRaw = (string) $response->getBody();
        $json = json_decode($jsonRaw, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('error', $json);
    }
}
