<?php

declare(strict_types=1);

use App\Shared\Env;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;

require __DIR__ . '/../vendor/autoload.php';

$config = ORMSetup::createAttributeMetadataConfiguration([__DIR__], true);
$config->setNamingStrategy(new UnderscoreNamingStrategy());

// Use TEST_DB_NAME if set (for PHPUnit), otherwise use DB_NAME
$testDbName = isset($_ENV['TEST_DB_NAME']) && is_string($_ENV['TEST_DB_NAME']) ? $_ENV['TEST_DB_NAME'] : null;
$dbName = $testDbName !== null ? $testDbName : Env::getString('DB_NAME', '');

return new EntityManager(DriverManager::getConnection([
    'driver' => 'pdo_mysql',
    'host' => Env::getString('DB_HOST', 'localhost'),
    'user' => Env::getString('DB_USER', 'root'),
    'password' => Env::getString('DB_PASSWORD', ''),
    'dbname' => $dbName,
    'port' => Env::getInt('DB_PORT', 3306),
]), $config);
