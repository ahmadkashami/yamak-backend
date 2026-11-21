<?php

namespace Tests\Unit;

use App\Support\DatabaseSafety;
use Tests\TestCase;

/**
 * B3.6-G1R reconciled pre-execution safety gate.
 *
 * Approved test strategies (no silent database switch):
 *   - sqlite file under the approved OpenCodeTemp directory (B2.2C.1)
 *   - MySQL on the exact disposable name yamak_test_disposable (B2.8)
 *
 * Everything else, especially yamak_integration_test, yamakdb,
 * yamakdb_prod_test and any production-like name, is refused.
 */
class TestingEnvironmentGuardTest extends TestCase
{
    public function test_effective_environment_is_isolated_and_db_free(): void
    {
        $this->assertSame('testing', app()->environment(), 'APP_ENV must be testing');

        // The same fail-closed gate already enforced in tests/bootstrap.php.
        DatabaseSafety::assertSafeTestingEnvironment();

        $default = config('database.default');
        $this->assertContains($default, ['sqlite', 'mysql'], 'Default connection must be an approved disposable strategy');

        $connection = config('database.connections.'.$default);
        $this->assertIsArray($connection);

        $database = (string) ($connection['database'] ?? '');
        $this->assertNotSame('', $database, 'Database name must be configured');
        $this->assertFalse(DatabaseSafety::isForbiddenDatabase($database), 'Database target must not be forbidden');

        if ($default === 'sqlite') {
            $this->assertSame('sqlite', $connection['driver'] ?? null);
            $this->assertStringEndsWith('.sqlite', $database, 'sqlite database must be a .sqlite file');
            $this->assertStringContainsString('OpenCodeTemp', $database, 'sqlite file must live in the approved temp dir');
            $this->assertStringNotContainsStringIgnoringCase('YamakProject', $database, 'sqlite file must be outside the repos');
            $this->assertTrue((bool) ($connection['foreign_key_constraints'] ?? false), 'sqlite FKs must be enforced');
        } else {
            $this->assertSame('mysql', $connection['driver'] ?? null);
            $this->assertSame(
                DatabaseSafety::TEST_DISPOSABLE_DATABASE,
                $database,
                'MySQL tests may only target the approved disposable database',
            );
        }

        $this->assertSame('array', config('mail.default'), 'Mail must not have a real transport');
        $this->assertSame('sync', config('queue.default'), 'Queue must not use an external broker');

        // Config-only: no connection resolution and no query is performed.
        $this->assertSame($connection['driver'] ?? null, config('database.connections.'.$default.'.driver'));
    }

    public function test_configured_mysql_connection_is_never_a_forbidden_database(): void
    {
        $mysql = config('database.connections.mysql');
        $this->assertIsArray($mysql);

        $name = (string) ($mysql['database'] ?? '');
        $this->assertNotSame('', $name, 'mysql database must be explicitly configured');
        $this->assertFalse(DatabaseSafety::isForbiddenDatabase($name));
    }
}
