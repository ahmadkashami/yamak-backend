<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Static-only assertions about the reconciled PHPUnit strategy. These tests
 * inspect source/configuration files; they do not boot the app or connect to a
 * database. NOT EXECUTED in B3.6-G1T.
 */
final class TestingEnvironmentGuardTest extends TestCase
{
    private const BACKEND_ROOT = __DIR__.'/../..';

    public function test_phpunit_keeps_the_approved_mysql_disposable_strategy(): void
    {
        $xml = (string) file_get_contents(self::BACKEND_ROOT.'/phpunit.xml');

        $this->assertStringContainsString('<env name="APP_ENV" value="testing" force="true"/>', $xml);
        $this->assertStringContainsString('<env name="DB_CONNECTION" value="mysql" force="true"/>', $xml);
        $this->assertStringContainsString('<env name="DB_DATABASE" value="yamak_test_disposable" force="true"/>', $xml);
        $this->assertStringContainsString('<env name="DB_HOST" value="127.0.0.1" force="true"/>', $xml);
        $this->assertStringContainsString('<env name="DB_PORT" value="3306" force="true"/>', $xml);
        $this->assertStringContainsString('<env name="DATABASE_URL" value="" force="true"/>', $xml);
        $this->assertStringContainsString('<env name="DB_SOCKET" value="" force="true"/>', $xml);
    }

    public function test_bootstrap_gates_before_framework_boot(): void
    {
        $bootstrap = (string) file_get_contents(self::BACKEND_ROOT.'/tests/bootstrap.php');

        $gate = strpos($bootstrap, 'DatabaseSafety::assertPreBootstrapTestingEnvironment()');
        $app = strpos($bootstrap, "require __DIR__.'/../bootstrap/app.php'");
        $kernel = strpos($bootstrap, 'Console\\Kernel::class');

        $this->assertNotFalse($gate);
        $this->assertNotFalse($app);
        $this->assertNotFalse($kernel);
        $this->assertLessThan($app, $gate);
        $this->assertLessThan($kernel, $gate);
    }

    public function test_named_runtime_connections_are_validated(): void
    {
        $source = (string) file_get_contents(self::BACKEND_ROOT.'/app/Support/DatabaseSafety.php');
        $manager = (string) file_get_contents(self::BACKEND_ROOT.'/app\Support\SafeDatabaseManager.php');

        $this->assertStringContainsString('assertNamedTestingConnectionsAllowed', $source);
        $this->assertStringContainsString('assertResolvedTestingConnectionAllowed', $source);
        $this->assertStringContainsString("protected function makeConnection(\$name): Connection", $manager);
        $this->assertStringContainsString('public function connectUsing(string $name, array $config, bool $force = false)', $manager);
    }
}
