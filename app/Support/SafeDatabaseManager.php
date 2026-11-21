<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * Testing-only DatabaseManager that validates every resolved connection after
 * Laravel has parsed DATABASE_URL and before PDO is constructed.
 *
 * This narrow hook covers runtime config()->set() / DB::purge() /
 * DB::connection() mutation as far as PHP's runtime can: no connection can be
 * resolved unless its effective configuration passes the DatabaseSafety gate.
 * It is not a substitute for least-privilege database accounts.
 */
final class SafeDatabaseManager extends DatabaseManager
{
    /** @param array<string, mixed> $config */
    protected function makeConnection($name): Connection
    {
        if (app()->environment('testing')) {
            $config = $this->configuration($name);
            DatabaseSafety::assertResolvedTestingConnectionAllowed($name, $config);
        }

        /** @var Connection $connection */
        $connection = parent::makeConnection($name);

        return $connection;
    }

    /**
     * build() and direct connectUsing() calls bypass makeConnection(), so
     * validate their explicit configuration too.
     *
     * @param  array<string, mixed>  $config
     * @return \Illuminate\Database\ConnectionInterface
     */
    public function connectUsing(string $name, array $config, bool $force = false)
    {
        if (app()->environment('testing')) {
            DatabaseSafety::assertResolvedTestingConnectionAllowed($name, $config);
        }

        return parent::connectUsing($name, $config, $force);
    }
}
