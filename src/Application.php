<?php

declare(strict_types=1);

namespace App;

use App\Packing\Application\UseCase\PackProductsHandler;
use App\Packing\Infrastructure\Packing\FallbackPackabilityCheckerAdapter;
use App\Packing\Infrastructure\Packing\ForcedFallbackPackabilityChecker;
use App\Packing\Infrastructure\Packing\ResilientPackabilityChecker;
use App\Packing\Infrastructure\Packing\ThreeDBinPackingCheckerAdapter;
use App\Packing\Infrastructure\Persistence\DoctrineBoxCatalogAdapter;
use App\Packing\Infrastructure\Persistence\DoctrinePackingCacheAdapter;
use App\Packing\Interface\Http\InputValidationException;
use App\Packing\Interface\Http\ProductRequestMapper;
use App\Shared\Env;
use App\Shared\HttpClientFactory;
use Doctrine\ORM\EntityManager;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use OpenApi\Attributes as OA;
use OpenApi\Generator;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;

#[OA\Info(
    version: '1.0.0',
    title: 'Packing API',
    description: 'API pro balení produktů do krabic.'
)]
class Application
{
    private EntityManager $entityManager;
    private LoggerInterface $logger;
    private ClientInterface|null $httpClient;
    private RequestFactoryInterface|null $requestFactory;
    private StreamFactoryInterface|null $streamFactory;
    /**
     * @var \Slim\App<\Psr\Container\ContainerInterface|null>
     */
    private \Slim\App $app;

    public function __construct(
        EntityManager $entityManager,
        LoggerInterface $logger,
        ClientInterface|null $httpClient = null,
        RequestFactoryInterface|null $requestFactory = null,
        StreamFactoryInterface|null $streamFactory = null
    ) {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
        $this->app = AppFactory::create(new ResponseFactory(), null);

        $this->app->get('/healthcheck', fn (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface => $this->json($response, ['status' => 'ok'], 200));

        $this->app->get('/openapi.json', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
            $openapi = (new Generator())->generate([__DIR__]);
            if ($openapi === null) {
                return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
            }
            $body = $response->getBody();
            $body->write($openapi->toJson());

            return $response->withHeader('Content-Type', 'application/json');
        });

        $this->app->post('/pack', [$this, 'pack']);
    }

    #[OA\Post(
        path: '/pack',
        summary: 'Navrhne vhodnou krabici pro produkty',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/json',
                schema: new OA\Schema(
                    type: 'object',
                    example: [
                        'products' => [
                            [
                                'id' => 1,
                                'width' => 3.4,
                                'height' => 2.1,
                                'length' => 3.0,
                                'weight' => 4.0
                            ],
                            [
                                'id' => 2,
                                'width' => 4.9,
                                'height' => 1.0,
                                'length' => 2.4,
                                'weight' => 9.9
                            ]
                        ]
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Návrh krabice',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'box',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'width', type: 'number', format: 'float', example: 4.0),
                                new OA\Property(property: 'height', type: 'number', format: 'float', example: 4.0),
                                new OA\Property(property: 'length', type: 'number', format: 'float', example: 4.0),
                                new OA\Property(property: 'maxWeight', type: 'number', format: 'float', example: 20.0),
                            ]
                        )
                    ],
                    type: 'object',
                    example: [
                        'box' => [
                            'id' => 1,
                            'width' => 4.0,
                            'height' => 4.0,
                            'length' => 4.0,
                            'maxWeight' => 20.0,
                        ]
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Žádná vhodná krabice',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'No single usable box found for the provided products.')
                    ],
                    type: 'object',
                    examples: [
                        new OA\Examples(
                            example: 'no_box_found',
                            summary: 'Zadny vhodny box',
                            value: ['error' => 'No single usable box found for the provided products.']
                        )
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Chybný vstup',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Product field "weight" must be > 0.')
                    ],
                    type: 'object',
                    examples: [
                        new OA\Examples(
                            example: 'missing_products',
                            summary: 'Chybi pole products',
                            value: ['error' => 'Field "products" is required and must be an array.']
                        ),
                        new OA\Examples(
                            example: 'invalid_field_type',
                            summary: 'Neplatny typ pole',
                            value: ['error' => 'Product at index 0 field "width" must be a number.']
                        ),
                        new OA\Examples(
                            example: 'domain_validation',
                            summary: 'Neplatna domennova validace',
                            value: ['error' => 'Product field "weight" must be > 0.']
                        )
                    ]
                )
            ),
            new OA\Response(
                response: 500,
                description: 'Interní chyba',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string', example: 'Unexpected internal error.'),
                        new OA\Property(property: 'details', type: 'string', nullable: true, example: 'Optional error details when APP_DEBUG=1')
                    ],
                    type: 'object',
                    examples: [
                        new OA\Examples(
                            example: 'internal_error',
                            summary: 'Bez detailu',
                            value: ['error' => 'Unexpected internal error.']
                        ),
                        new OA\Examples(
                            example: 'internal_error_with_details',
                            summary: 'S detailem (APP_DEBUG=1)',
                            value: ['error' => 'Unexpected internal error.', 'details' => 'Optional error details when APP_DEBUG=1']
                        )
                    ]
                )
            )
        ]
    )]
    public function pack(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $payload = $this->decodeJsonBody((string) $request->getBody());
            $products = (new ProductRequestMapper())->map($payload);
            $selectedBox = $this->buildHandler()->handle($products);
            if ($selectedBox === null) {
                return $this->json($response, [
                    'error' => 'No single usable box found for the provided products.',
                ], 422);
            }

            return $this->json($response, [
                'box' => $selectedBox->toArray(),
            ], 200);
        } catch (InputValidationException $exception) {
            return $this->json($response, ['error' => $exception->getMessage()], 400);
        } catch (\Throwable $exception) {
            $errorPayload = ['error' => 'Unexpected internal error.'];
            if (($_ENV['APP_DEBUG'] ?? '0') === '1') {
                $errorPayload['details'] = $exception->getMessage();
            }

            return $this->json($response, $errorPayload, 500);
        }
    }

    public function run(ServerRequestInterface $request): ResponseInterface
    {
        return $this->app->handle($request);
    }

    private function buildHandler(): PackProductsHandler
    {
        $cache = new DoctrinePackingCacheAdapter(
            $this->entityManager,
            $this->logger,
        );

        $thirdPartyChecker = new ThreeDBinPackingCheckerAdapter(
            $this->buildHttpClient(),
            $this->buildRequestFactory(),
            $this->buildStreamFactory(),
            $this->logger,
            Env::getString('PACKING_API_URL'),
            Env::getString('PACKING_API_USERNAME'),
            Env::getString('PACKING_API_KEY'),
            $cache,
        );

        if (Env::getInt('PACKING_FORCE_FALLBACK', 0) === 1) {
            $thirdPartyChecker = new ForcedFallbackPackabilityChecker();
        }

        $checker = new ResilientPackabilityChecker(
            $thirdPartyChecker,
            new FallbackPackabilityCheckerAdapter($this->logger),
            $this->logger
        );

        return new PackProductsHandler(
            new DoctrineBoxCatalogAdapter($this->entityManager),
            $checker,
            $cache,
            $this->logger
        );
    }

    private function buildHttpClient(): ClientInterface
    {
        if ($this->httpClient !== null) {
            return $this->httpClient;
        }

        return $this->buildHttpClientFactory()->createClient();
    }

    private function buildRequestFactory(): RequestFactoryInterface
    {
        if ($this->requestFactory !== null) {
            return $this->requestFactory;
        }

        return $this->buildHttpClientFactory()->createRequestFactory();
    }

    private function buildStreamFactory(): StreamFactoryInterface
    {
        if ($this->streamFactory !== null) {
            return $this->streamFactory;
        }

        return $this->buildHttpClientFactory()->createStreamFactory();
    }

    private function buildHttpClientFactory(): HttpClientFactory
    {
        return new HttpClientFactory(
            Env::getInt('PACKING_API_TIMEOUT_SECONDS', 4),
            false
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBody(string $rawBody): array
    {
        if ($rawBody === '') {
            throw new InputValidationException('Request body must not be empty.');
        }

        try {
            $decoded = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InputValidationException('Request body must be valid JSON.');
        }

        return $this->normalizeStringKeyedArray($decoded, 'Request JSON body must be an object.');
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeStringKeyedArray(mixed $value, string $errorMessage): array
    {
        if (!is_array($value)) {
            throw new InputValidationException($errorMessage);
        }

        foreach ($value as $key => $_) {
            if (!is_string($key)) {
                throw new InputValidationException($errorMessage);
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload, int $statusCode): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_THROW_ON_ERROR));

        return $response
            ->withStatus($statusCode)
            ->withHeader('Content-Type', 'application/json');
    }
}
