<?php

namespace App\Support;

use RuntimeException;

/**
 * Fail-closed database safety gate for the two destructive entry points in the
 * backend: the legacy MongoDB import command and the PHPUnit test runner.
 *
 * The gate validates *effective* Laravel configuration (after .env and PHPUnit
 * env overrides have been applied), never raw file text. It contains no
 * credentials, opens no database connection, and performs no query.
 */
final class DatabaseSafety
{
    /** The only MySQL database name approved for PHPUnit by B2.8. */
    public const TEST_DISPOSABLE_DATABASE = 'yamak_test_disposable';

    /** Dedicated, separately authorised target for the legacy import command. */
    public const IMPORT_DISPOSABLE_DATABASE = 'yamak_import_disposable';

    /** Names that must never be reachable from a destructive entry point. */
    private const FORBIDDEN_DATABASES = [
        'yamak_integration_test',
        'yamakdb',
        'yamakdb_prod_test',
    ];

    /** The import command is MySQL-specific and must stay on the local host. */
    private const ALLOWED_LOCAL_HOSTS = ['127.0.0.1', 'localhost'];

    public static function isForbiddenDatabase(?string $database): bool
    {
        if ($database === null || trim($database) === '') {
            // Missing or ambiguous configuration is never safe.
            return true;
        }

        $name = strtolower(trim($database));

        foreach (self::FORBIDDEN_DATABASES as $forbidden) {
            if ($name === $forbidden) {
                return true;
            }
        }

        // Catch any production-like name, including future variants.
        return str_contains($name, 'prod');
    }

    /**
     * Pre-execution gate for PHPUnit. Called from tests/bootstrap.php before
     * any test is loaded, and again from Tests\TestCase::createApplication()
     * before RefreshDatabase traits can migrate or truncate anything.
     *
     * Two approved disposable strategies are accepted, matching the documented
     * history; this does not switch either one:
     *   - sqlite file under the approved OpenCodeTemp directory (B2.2C.1)
     *   - MySQL on the exact disposable name yamak_test_disposable (B2.8)
     */
    public static function assertSafeTestingEnvironment(): void
    {
        self::failUnless(
            app()->environment() === 'testing',
            'PHPUNIT SAFETY GATE: APP_ENV must be "testing".',
        );

        $default = config('database.default');
        self::failUnless(
            is_string($default) && $default !== '',
            'PHPUNIT SAFETY GATE: default database connection is missing or ambiguous.',
        );

        $connection = config('database.connections.'.$default);
        self::failUnless(
            is_array($connection),
            'PHPUNIT SAFETY GATE: no configuration for default connection "'.$default.'".',
        );

        $driver = (string) ($connection['driver'] ?? '');
        $database = $connection['database'] ?? null;
        self::failUnless(
            is_string($database) && trim($database) !== '',
            'PHPUNIT SAFETY GATE: database name is missing or ambiguous.',
        );
        self::failUnless(
            ! self::isForbiddenDatabase($database),
            'PHPUNIT SAFETY GATE: forbidden database "'.$database.'" cannot be used by tests.',
        );

        if ($default === 'sqlite') {
            self::failUnless($driver === 'sqlite', 'PHPUNIT SAFETY GATE: sqlite connection driver mismatch.');
            self::failUnless(
                self::isAbsolutePath($database),
                'PHPUNIT SAFETY GATE: sqlite database must be an absolute path.',
            );
            self::failUnless(
                str_ends_with(strtolower($database), '.sqlite'),
                'PHPUNIT SAFETY GATE: sqlite database must be a .sqlite file.',
            );
            self::failUnless(
                str_contains($database, 'OpenCodeTemp'),
                'PHPUNIT SAFETY GATE: sqlite database must live in the approved OpenCodeTemp directory.',
            );
            self::failUnless(
                ! str_contains(strtolower($database), 'yamakproject'),
                'PHPUNIT SAFETY GATE: sqlite database must be outside the project repositories.',
            );
            self::failUnless(
                (bool) ($connection['foreign_key_constraints'] ?? false),
                'PHPUNIT SAFETY GATE: sqlite foreign key enforcement must be enabled.',
            );
        } elseif ($default === 'mysql') {
            self::failUnless($driver === 'mysql', 'PHPUNIT SAFETY GATE: mysql connection driver mismatch.');
            self::failUnless(
                $database === self::TEST_DISPOSABLE_DATABASE,
                'PHPUNIT SAFETY GATE: MySQL tests may only target "'.self::TEST_DISPOSABLE_DATABASE.'"; resolved "'.$database.'".',
            );
        } else {
            throw new RuntimeException(
                'PHPUNIT SAFETY GATE: unsupported default connection "'.$default.'"; refusing to run.'
            );
        }

        // No outbound side effects during tests.
        self::failUnless(config('mail.default') === 'array', 'PHPUNIT SAFETY GATE: mail must use the array transport.');
        self::failUnless(config('queue.default') === 'sync', 'PHPUNIT SAFETY GATE: queue must use the sync driver.');
    }

    /**
     * Pre-execution gate for `yamak:seed-mongo-dump`. Called as the very first
     * statement of handle(), before path checks, BSON conversion, cleanup or
     * any import write. It relies on explicit approved configuration, not on
     * an interactive confirmation prompt.
     */
    public static function assertImportAllowed(bool $destructive): void
    {
        self::failUnless(
            app()->environment('local'),
            'IMPORT SAFETY GATE: the legacy import command requires APP_ENV=local.',
        );

        $default = config('database.default');
        self::failUnless(
            $default === 'mysql',
            'IMPORT SAFETY GATE: the legacy import command requires the mysql default connection.',
        );

        $connection = config('database.connections.mysql');
        self::failUnless(is_array($connection), 'IMPORT SAFETY GATE: mysql connection configuration is missing.');
        self::failUnless(
            ($connection['driver'] ?? null) === 'mysql',
            'IMPORT SAFETY GATE: mysql connection driver mismatch.',
        );

        $database = $connection['database'] ?? null;
        self::failUnless(
            is_string($database) && trim($database) !== '',
            'IMPORT SAFETY GATE: database name is missing or ambiguous.',
        );
        self::failUnless(
            ! self::isForbiddenDatabase($database),
            'IMPORT SAFETY GATE: forbidden database "'.$database.'" cannot be imported into.',
        );
        self::failUnless(
            $database === self::IMPORT_DISPOSABLE_DATABASE,
            'IMPORT SAFETY GATE: only the dedicated disposable database "'.self::IMPORT_DISPOSABLE_DATABASE.'" is approved; resolved "'.$database.'".',
        );

        $host = strtolower((string) ($connection['host'] ?? ''));
        self::failUnless(
            in_array($host, self::ALLOWED_LOCAL_HOSTS, true),
            'IMPORT SAFETY GATE: only local MySQL hosts are allowed for legacy import.',
        );

        // Explicit, out-of-band acknowledgement. Defaults are all deny.
        $acknowledged = (string) config('database-safety.import.allow', '');
        $declaredDatabase = (string) config('database-safety.import.database', '');
        $destructiveAcknowledged = (string) config('database-safety.import.allow_destructive', '');

        self::failUnless(
            $acknowledged === '1',
            'IMPORT SAFETY GATE: set YAMAK_IMPORT_ALLOW=1 to authorise this import.',
        );
        self::failUnless(
            $declaredDatabase !== '' && hash_equals($declaredDatabase, (string) $database),
            'IMPORT SAFETY GATE: YAMAK_IMPORT_DATABASE must explicitly match the effective database.',
        );

        if ($destructive) {
            self::failUnless(
                $destructiveAcknowledged === '1',
                'IMPORT SAFETY GATE: set YAMAK_IMPORT_ALLOW_DESTRUCTIVE=1 for --clean; it is never allowed against "yamak_integration_test".',
            );
        }
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private static function failUnless(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
