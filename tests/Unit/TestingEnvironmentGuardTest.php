<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * B2.2C.1 pre-execution safety gate.
 *
 * Proves, WITHOUT performing any database write or external connection, that
 * the effective test environment is isolated before any DB-backed test is
 * allowed to run:
 *
 *   - APP_ENV        = testing
 *   - DB default     = sqlite
 *   - sqlite file    = an absolute *.sqlite path OUTSIDE the repositories
 *   - sqlite FKs     = enforced
 *   - mail default   = array (no real transport)
 *   - queue default  = sync  (no worker / no external broker)
 *
 * Reading configuration and the connection's configured driver name performs
 * no query and opens no external connection, so this guard is safe to run even
 * if the environment were misconfigured.
 */
class TestingEnvironmentGuardTest extends TestCase
{
    public function test_effective_environment_is_isolated_and_db_free(): void
    {
        $this->assertSame('testing', app()->environment(), 'APP_ENV must be testing');

        $this->assertSame('sqlite', config('database.default'), 'Default DB connection must be sqlite');

        $database = config('database.connections.sqlite.database');
        $this->assertIsString($database, 'sqlite database must be a string');
        $this->assertNotSame('', $database);
        $this->assertStringEndsWith('.sqlite', $database, 'sqlite database must be a .sqlite file (isolated)');
        $this->assertStringContainsString('OpenCodeTemp', $database, 'sqlite database must live in the approved temp dir, outside the repos');
        $this->assertStringNotContainsStringIgnoringCase('YamakProject', $database, 'sqlite database must not point inside the project repos');

        $this->assertTrue(
            (bool) config('database.connections.sqlite.foreign_key_constraints'),
            'sqlite foreign key enforcement must be enabled',
        );

        $this->assertSame('array', config('mail.default'), 'Mail default must be array (no real delivery)');
        $this->assertSame('sync', config('queue.default'), 'Queue default must be sync (no external broker)');

        // Runs LAST: resolving a connection is the only step that opens one.
        // All configuration is asserted above, so a misexecuted environment
        // fails here first and never reaches a foreign connection.
        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'Effective connection driver must be sqlite');
    }

    public function test_configured_mysql_connection_is_never_the_default(): void
    {
        // The repository .env carries a MySQL connection. This asserts the
        // testing environment can never silently fall back to it.
        $this->assertNotSame('mysql', config('database.default'));
        $this->assertNotNull(config('database.connections.mysql'), 'mysql config may exist but must be inert');
    }
}
