<?php

namespace Tests\Unit;

use App\Support\DatabaseSafety;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pure-logic coverage for the fail-closed gate. No application boot, no
 * database connection and no PHPUnit execution were used while authoring these
 * tests in B3.6-G1T.
 */
final class DatabaseSafetyGuardTest extends TestCase
{
    #[DataProvider('forbiddenDatabaseProvider')]
    public function test_forbidden_databases_are_refused(?string $name): void
    {
        $this->assertTrue(DatabaseSafety::isForbiddenDatabase($name));
    }

    public static function forbiddenDatabaseProvider(): array
    {
        return [
            'integration database' => ['yamak_integration_test'],
            'legacy production test' => ['yamakdb_prod_test'],
            'legacy database' => ['yamakdb'],
            'case insensitive' => ['YamakDB_Prod_Test'],
            'any production-like name' => ['customer_prod_backup'],
            'missing configuration' => [null],
            'empty configuration' => [''],
        ];
    }

    #[DataProvider('disposableDatabaseProvider')]
    public function test_approved_disposable_databases_are_not_forbidden(string $name): void
    {
        $this->assertFalse(DatabaseSafety::isForbiddenDatabase($name));
    }

    public static function disposableDatabaseProvider(): array
    {
        return [
            'phpunit disposable' => [DatabaseSafety::TEST_DISPOSABLE_DATABASE],
            'import disposable' => [DatabaseSafety::IMPORT_DISPOSABLE_DATABASE],
        ];
    }

    public function test_approved_mysql_disposable_configuration_is_accepted(): void
    {
        $this->expectNotToPerformAssertions();

        DatabaseSafety::assertTestingConnectionConfigurationAllowed([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'yamak_test_disposable',
            'unix_socket' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    public function test_approved_sqlite_file_configuration_is_accepted(): void
    {
        $this->expectNotToPerformAssertions();

        DatabaseSafety::assertTestingConnectionConfigurationAllowed([
            'driver' => 'sqlite',
            'database' => 'D:/OpenCodeTemp/yamak_sqlite_tests/test.sqlite',
            'foreign_key_constraints' => true,
        ]);
    }

    public function test_named_in_memory_sqlite_configuration_is_accepted(): void
    {
        $this->expectNotToPerformAssertions();

        DatabaseSafety::assertTestingConnectionConfigurationAllowed([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => true,
        ]);
    }

    #[DataProvider('unsafeConnectionProvider')]
    public function test_unsafe_connection_configurations_are_rejected(array $config): void
    {
        $this->expectException(RuntimeException::class);

        DatabaseSafety::assertTestingConnectionConfigurationAllowed($config);
    }

    public static function unsafeConnectionProvider(): array
    {
        $safeMysql = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'yamak_test_disposable',
            'unix_socket' => '',
            'foreign_key_constraints' => true,
        ];

        return [
            'integration database' => [array_replace($safeMysql, ['database' => 'yamak_integration_test'])],
            'legacy production database' => [array_replace($safeMysql, ['database' => 'yamakdb'])],
            'production-like database' => [array_replace($safeMysql, ['database' => 'yamak_prod'])],
            'foreign host' => [array_replace($safeMysql, ['host' => 'db.example.com'])],
            'foreign port' => [array_replace($safeMysql, ['port' => '3307'])],
            'missing database' => [array_replace($safeMysql, ['database' => ''])],
            'missing host' => [array_replace($safeMysql, ['host' => ''])],
            'unix socket' => [array_replace($safeMysql, ['unix_socket' => '/var/run/mysqld/mysqld.sock'])],
            'unsupported driver' => [array_replace($safeMysql, ['driver' => 'pgsql'])],
            'DATABASE_URL database override' => [array_replace($safeMysql, [
                'url' => 'mysql://127.0.0.1:3306/yamak_integration_test',
            ])],
            'DATABASE_URL host/port override' => [array_replace($safeMysql, [
                'url' => 'mysql://db.example.com:3307/yamak_test_disposable',
            ])],
            'DATABASE_URL query database override' => [array_replace($safeMysql, [
                'url' => 'mysql://127.0.0.1:3306/yamak_test_disposable?database=yamak_integration_test',
            ])],
            'DATABASE_URL query host override' => [array_replace($safeMysql, [
                'url' => 'mysql://127.0.0.1:3306/yamak_test_disposable?host=db.example.com',
            ])],
            'SQLite FK disabled' => [[
                'driver' => 'sqlite',
                'database' => 'D:/OpenCodeTemp/yamak_sqlite_tests/test.sqlite',
                'foreign_key_constraints' => false,
            ]],
            'SQLite inside project repo' => [[
                'driver' => 'sqlite',
                'database' => 'D:/AALYASSIN/Projects/work/YamakProject/yamak-backend/database/database.sqlite',
                'foreign_key_constraints' => true,
            ]],
        ];
    }
}
