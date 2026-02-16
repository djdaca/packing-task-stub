<?php

declare(strict_types=1);

use App\Application;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Slim\Psr7\Factory\ServerRequestFactory;

require __DIR__ . '/../vendor/autoload.php';

$entityManager = require __DIR__ . '/../src/bootstrap.php';

$logger = new Logger('packing');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

$request = (new ServerRequestFactory())->createFromGlobals();

$app = new Application($entityManager, $logger);
$response = $app->run($request);

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header(sprintf('%s: %s', $name, $value), false);
    }
}

echo (string) $response->getBody();
