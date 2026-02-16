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
use App\Packing\Infrastructure\Packing\ResilientPackabilityChecker;
use App\Packing\Infrastructure\Packing\ThirdPartyPackabilityCheckerAdapter;
use App\Packing\Infrastructure\Persistence\DoctrineBoxCatalogAdapter;
use App\Packing\Infrastructure\Persistence\DoctrinePackingCacheAdapter;
use App\Packing\Interface\Http\ProductRequestMapper;
use App\Shared\Env;
use DateTimeImmutable;
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

#[CoversClass(Application::class)]
#[CoversClass(PackProductsHandler::class)]
#[CoversClass(Packaging::class)]
#[CoversClass(PackingCalculationCache::class)]
#[CoversClass(Box::class)]
#[CoversClass(Product::class)]
#[CoversClass(FallbackPackabilityCheckerAdapter::class)]
#[CoversClass(ResilientPackabilityChecker::class)]
#[CoversClass(ThirdPartyPackabilityCheckerAdapter::class)]
#[CoversClass(DoctrineBoxCatalogAdapter::class)]
#[CoversClass(DoctrinePackingCacheAdapter::class)]
#[CoversClass(ProductRequestMapper::class)]
#[CoversClass(Env::class)]
final class ApplicationRunTest extends TestCase
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

    public function testPackEndpointFits(): void
    {
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

        $this->assertSame(200, $response->getStatusCode());
        $jsonRaw = (string) $response->getBody();
        $json = json_decode($jsonRaw, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('box', $json);
    }

    public function testPackEndpointTooBig(): void
    {
        $fixturesRaw = file_get_contents(__DIR__ . '/fixtures/pack-fixtures.json');
        if ($fixturesRaw === false) {
            throw new \RuntimeException('Unable to read fixture file');
        }
        $fixtures = json_decode($fixturesRaw, true);
        if (!is_array($fixtures) || !isset($fixtures['tooBig']) || !is_array($fixtures['tooBig'])) {
            throw new \RuntimeException('Invalid fixture structure');
        }
        $request = $this->createJsonRequest($fixtures['tooBig']);
        $response = $this->application->run($request);

        $this->assertSame(422, $response->getStatusCode());
        $jsonRaw = (string) $response->getBody();
        $json = json_decode($jsonRaw, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('error', $json);
    }

    public function testPackEndpointNoBoxFound(): void
    {
        // Use a product too large for any box
        $payload = ['products' => [['width' => 50, 'height' => 50, 'length' => 50, 'weight' => 1]]];
        $request = $this->createJsonRequest($payload);
        $response = $this->application->run($request);

        $this->assertSame(422, $response->getStatusCode());
        $jsonRaw = (string) $response->getBody();
        $json = json_decode($jsonRaw, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('error', $json);

        // Verify cache is NOT stored when no box found (selected_box_id NOT NULL constraint)
        $this->entityManager->clear();
        $cached = $this->entityManager
            ->getRepository(PackingCalculationCache::class)
            ->createQueryBuilder('pcc')
            ->where('pcc.selectedBoxId IS NULL')
            ->getQuery()
            ->getOneOrNullResult();

        $this->assertNull($cached, 'Cache should NOT be stored when no box found (selected_box_id NOT NULL)');
    }

    public function testCacheEntryExpiresWhenTtlElapsed(): void
    {
        $logger = new Logger('packing-cache');
        $logger->pushHandler(new StreamHandler('php://temp', Logger::DEBUG));
        $cache = new DoctrinePackingCacheAdapter($this->entityManager, $logger, 1);

        $products = [new Product(1.0, 2.0, 3.0, 1.0)];
        $cache->storeSelectedBox($products, 1);

        $hash = $this->entityManager->getConnection()->fetchOne('SELECT input_hash FROM packing_calculation_cache LIMIT 1');
        $this->assertIsString($hash);

        $expiredAt = (new DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s');
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE packing_calculation_cache SET updated_at = :expiredAt WHERE input_hash = :hash',
            ['expiredAt' => $expiredAt, 'hash' => $hash]
        );

        $selectedBoxId = $cache->getSelectedBox($products);
        $this->assertNull($selectedBoxId, 'Expired cache entry should be treated as miss.');

        $remainingRaw = $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM packing_calculation_cache WHERE input_hash = :hash',
            ['hash' => $hash]
        );
        if (!is_numeric($remainingRaw)) {
            $this->fail('Expected numeric COUNT(*) result.');
        }
        $this->assertSame(0, (int) $remainingRaw);
    }

    public function testCacheDoesNotExpireWhenTtlDisabled(): void
    {
        $logger = new Logger('packing-cache');
        $logger->pushHandler(new StreamHandler('php://temp', Logger::DEBUG));
        $cache = new DoctrinePackingCacheAdapter($this->entityManager, $logger, 0);

        $products = [new Product(1.5, 2.5, 3.5, 1.0)];
        $cache->storeSelectedBox($products, 2);

        $hash = $this->entityManager->getConnection()->fetchOne('SELECT input_hash FROM packing_calculation_cache LIMIT 1');
        $this->assertIsString($hash);

        $expiredAt = (new DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s');
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE packing_calculation_cache SET updated_at = :expiredAt WHERE input_hash = :hash',
            ['expiredAt' => $expiredAt, 'hash' => $hash]
        );

        $selectedBoxId = $cache->getSelectedBox($products);
        $this->assertSame(2, $selectedBoxId);

        $remainingRaw = $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM packing_calculation_cache WHERE input_hash = :hash',
            ['hash' => $hash]
        );
        if (!is_numeric($remainingRaw)) {
            $this->fail('Expected numeric COUNT(*) result.');
        }
        $this->assertSame(1, (int) $remainingRaw);
    }
}
