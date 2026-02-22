<?php

declare(strict_types=1);

namespace App\Packing\Infrastructure\Packing;

use App\Packing\Application\Port\PackabilityCheckerPort;
use App\Packing\Application\Port\PackingCachePort;
use App\Packing\Domain\Model\Box;
use App\Packing\Domain\Model\Product;
use App\Packing\Infrastructure\Packing\Api\findBinSize\PackingApiRequest;
use App\Packing\Infrastructure\Packing\Api\findBinSize\PackingApiResponse;

use function count;
use function in_array;
use function json_encode;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

use function rtrim;
use function sprintf;

final class ThreeDBinPackingCheckerAdapter implements PackabilityCheckerPort
{
    private const string FIND_BIN_SIZE_PATH = '/packer/findBinSize';
    private const array RETRIABLE_STATUS_CODES = [408, 429, 503, 504]; // Timeout, RateLimit, ServiceUnavailable, GatewayTimeout

    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private LoggerInterface $logger,
        private string $apiUrl,
        private string $apiUsername,
        private string $apiKey,
        private PackingCachePort $cache,
    ) {
    }

    /**
     * @param list<Product> $products
     * @param list<Box> $boxes
     */
    public function findFirstPackableBox(array $products, array $boxes): Box|null
    {
        if ($boxes === []) {
            return null;
        }

        $this->assertConfigured();

        $selectedBox = $this->findSelectedPackableBoxFromApi($products, $boxes);
        if ($selectedBox !== null) {
            $this->logger->info('[PackingAPI] API-first selection succeeded.', ['boxId' => $selectedBox->getId()]);
        }

        if ($selectedBox === null) {
            return null;
        }

        $boxId = $selectedBox->getId();
        if ($boxId === null) {
            $this->logger->warning('[PackingAPI] Selected box has no ID; skipping cache write.');

            return $selectedBox;
        }

        $this->cache->storeSelectedBox($products, $boxId);

        return $selectedBox;
    }

    /**
     * @param list<Product> $products
     * @param list<Box> $boxes
     */
    private function findSelectedPackableBoxFromApi(array $products, array $boxes): Box|null
    {
        $request = $this->buildRequest($products, $boxes);
        $boxByExternalId = $request->boxByExternalId();
        $response = $this->sendRequest($request);
        $this->assertResponseStatus($response);

        $parsedResponse = PackingApiResponse::fromHttpResponse($response);
        $selectedExternalBoxId = $this->extractSelectedPackedBinId($parsedResponse, count($products));

        if ($selectedExternalBoxId === null) {
            return null;
        }

        $selectedBox = $boxByExternalId[$selectedExternalBoxId] ?? null;
        if ($selectedBox === null) {
            $this->logger->error('[PackingAPI] Response returned unknown bin id.', [
                'binId' => $selectedExternalBoxId,
            ]);

            return null;
        }

        return $selectedBox;
    }

    private function assertConfigured(): void
    {
        if ($this->apiUrl === '' || $this->apiUsername === '' || $this->apiKey === '') {
            $this->logger->error('[PackingAPI] Missing third-party packing API configuration.');

            throw new ThirdPartyPackingException('Missing third-party packing API configuration.');
        }
    }

    /**
     * @param list<Product> $products
     * @param list<Box> $boxes
     */
    private function buildRequest(array $products, array $boxes): PackingApiRequest
    {
        return PackingApiRequest::fromDomain($products, $boxes, $this->apiUsername, $this->apiKey);
    }

    private function sendRequest(PackingApiRequest $request): ResponseInterface
    {
        $payload = $request->toArray();
        $endpointUrl = $this->buildEndpointUrl();

        $this->logger->info('[PackingAPI] Sending request to external API', ['url' => $endpointUrl]);
        $sanitizedPayload = $payload;
        unset($sanitizedPayload['api_key']);
        $this->logger->debug('[PackingAPI] Payload', ['payload' => $sanitizedPayload]);

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
            $request = $this->requestFactory->createRequest('POST', $endpointUrl)
                ->withHeader('Accept', 'application/json')
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($body));
            $response = $this->httpClient->sendRequest($request);
            $this->logger->info('[PackingAPI] Response status', ['status' => $response->getStatusCode()]);
            $this->logger->debug('[PackingAPI] Response body', ['body' => (string) $response->getBody()]);

            return $response;
        } catch (\JsonException $exception) {
            $this->logger->error('[PackingAPI] Payload serialization failed', ['error' => $exception->getMessage()]);

            throw new ThirdPartyPackingException('Third-party packing API payload serialization failed.', 0, $exception);
        } catch (ClientExceptionInterface $exception) {
            $this->logger->error('[PackingAPI] Network failure', ['error' => $exception->getMessage()]);

            throw new ThirdPartyPackingException('Third-party packing API network failure.', 0, $exception);
        }
    }

    private function buildEndpointUrl(): string
    {
        return rtrim($this->apiUrl, '/') . self::FIND_BIN_SIZE_PATH;
    }

    private function assertResponseStatus(ResponseInterface $response): void
    {
        if ($response->getStatusCode() < 400) {
            return;
        }

        $statusCode = $response->getStatusCode();
        $isRetriable = in_array($statusCode, self::RETRIABLE_STATUS_CODES, true);

        $logMessage = sprintf(
            '[PackingAPI] API returned status %d (%s) - Will use fallback',
            $statusCode,
            $this->getHttpStatusName($statusCode),
        );

        if ($isRetriable) {
            $this->logger->warning($logMessage);
        } else {
            $this->logger->error($logMessage);
        }

        throw new ThirdPartyPackingException(
            sprintf('Third-party API error (status %d). Falling back to local calculation.', $statusCode)
        );
    }

    private function extractSelectedPackedBinId(PackingApiResponse|null $response, int $requestedItemCount): string|null
    {
        if ($response === null) {
            $this->logger->error('[PackingAPI] Response does not contain "response" node.');

            return null;
        }

        return $response->selectedPackedBinId($requestedItemCount);
    }

    private function getHttpStatusName(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            408 => 'Request Timeout',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            default => 'HTTP Error',
        };
    }
}
