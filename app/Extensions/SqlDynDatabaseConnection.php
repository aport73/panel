<?php

namespace Pterodactyl\Extensions;

use Pterodactyl\Models\DatabaseHost;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Config\Repository as ConfigRepository;
use Pterodactyl\Contracts\Repository\DatabaseHostRepositoryInterface;
use Pterodactyl\Models\Database;



class SqlDynDatabaseConnection
{
    public const DB_CHARSET = 'utf8';
    public const DB_COLLATION = 'utf8_unicode_ci';
    public const DB_DRIVER = 'mysql';

    public function __construct(
        protected ConfigRepository $config,
        protected Encrypter $encrypter,
        protected DatabaseHostRepositoryInterface $repository
    ) {
    }

    public function setWithDatabaseCredentials(string $connection, Database $database): void
    {
        $host = $database->host;
        $username = $database->username;
        $password = $this->encrypter->decrypt($database->password);
        $databaseName = $database->database;

        $this->config->set('database.connections.' . $connection, [
            'driver' => self::DB_DRIVER,
            'host' => $host->host,
            'port' => $host->port,
            'database' => $databaseName,
            'username' => $username,
            'password' => $password,
            'charset' => self::DB_CHARSET,
            'collation' => self::DB_COLLATION,
        ]);
    }
}
