<?php

namespace App\Support;

use Illuminate\Support\ConfigurationUrlParser;
use InvalidArgumentException;
use RuntimeException;

/**
 * Fail-closed database safety gate for the two destructive entry points in the
 * backend: the legacy MongoDB import command and the PHPUnit test runner.
 *
 * The gate validates effective Laravel configuration after .env and PHPUnit
 * environment overrides, and it mirrors Laravel's DATABASE_URL parsing before
 * a connection is resolved. It contains no credentials, opens no connection,
 * and performs no query.
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

    /** Import and MySQL test targets must stay on the local server. */
    private const ALLOWED_LOCAL_HOSTS = ['127.0.0.1', 'localhost'];

    private const ALLOWED_LOCAL_PORT = '3306';

    /** Variables whose effective values are safety critical before Laravel boots. */
    private const SAFETY_ENV_KEYS = [
        'APP_ENV',
        'DB_CONNECTION',
        'DB_DATABASE',
        'DB_HOST',
        'DB_PORT',
        'DB_SOCKET',
        'DATABASE_URL',
        'DB_FOREIGN_KEYS',
    ];

    /** Query-string keys in DATABASE_URL that must never override the gate. */
    private const URL_OVERRIDE_KEYS = [
        'driver',
        'host',
        'port',
        'database',
        'dbname',
        'unix_socket',
        'socket',
    ];

    /** Alias map used by Laravel's ConfigurationUrlParser. */
    private const DRIVER_ALIASES = [
        'mssql' => 'sqlsrv',
        'mysql2' => 'mysql',
        'postgres' => 'pgsql',
        'postgresql' => 'pgsql',
        'sqlite3' => 'sqlite',
        'redis' => 'tcp',
        'rediss' => 'tls',
    ];

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
     * Pre-bootstrap gate for PHPUnit. Called before bootstrap/app.php is loaded
     * and before the Console Kernel boots, so a provider cannot open a DB
     * connection before the safety decision is made.
     *
     * PHPUnit applies <php><env> values before loading tests/bootstrap.php.
     * With force="true" in phpunit.xml these values override shell variables.
     * Conflicting values in getenv(), $_ENV and $_SERVER still fail closed.
     */
    public static function assertPreBootstrapTestingEnvironment(): void
    {
        $values = [];

        foreach (self::SAFETY_ENV_KEYS as $key) {
            $values[$key] = self::readRawEnvironmentValues($key);
            self::failUnless(
                count($values[$key]) <= 1,
                'PHPUNIT SAFETY GATE: conflicting '.$key.' values are present; refusing to run.',
            );
        }

        $environment = $values['APP_ENV'][0] ?? null;
        $connection = $values['DB_CONNECTION'][0] ?? null;
        $database = $values['DB_DATABASE'][0] ?? null;
        $host = $values['DB_HOST'][0] ?? null;
        $port = $values['DB_PORT'][0] ?? null;
        $socket = $values['DB_SOCKET'][0] ?? null;
        $url = $values['DATABASE_URL'][0] ?? null;
        $foreignKeys = self::rawBoolean($values['DB_FOREIGN_KEYS'][0] ?? null, true);

        self::failUnless($environment === 'testing', 'PHPUNIT SAFETY GATE: APP_ENV must be "testing".');
        self::failUnless(
            in_array($connection, ['mysql', 'sqlite'], true),
            'PHPUNIT SAFETY GATE: DB_CONNECTION must be an approved disposable strategy.',
        );
        self::failUnless(
            is_string($database) && trim($database) !== '' && ! self::isForbiddenDatabase($database),
            'PHPUNIT SAFETY GATE: DB_DATABASE is missing, ambiguous or forbidden.',
        );
        self::failUnless(
            $socket === null || trim((string) $socket) === '',
            'PHPUNIT SAFETY GATE: DB_SOCKET must not redirect the MySQL connection.',
        );

        $candidate = [
            'driver' => $connection,
            'database' => $database,
            'foreign_key_constraints' => $foreignKeys,
        ];

        if ($host !== null) {
            $candidate['host'] = $host;
        }
        if ($port !== null) {
            $candidate['port'] = $port;
        }
        if ($socket !== null) {
            $candidate['unix_socket'] = $socket;
        }
        if ($url !== null) {
            $candidate['url'] = $url;
        }

        self::assertTestingConnectionConfigurationAllowed($candidate, 'pre-bootstrap');
    }

    /**
     * Effective-config gate. Called from tests/bootstrap.php after Laravel has
     * booted and again from Tests\TestCase::createApplication() before
     * RefreshDatabase traits can migrate or truncate anything.
     *
     * Two approved disposable strategies are accepted, matching the documented
     * history; this does not switch either one:
     *   - sqlite file under the approved OpenCodeTemp directory (B2.2C.1)
     *   - MySQL on the exact disposable name yamak_test_disposable (B2.8)
     * A named in-memory SQLite connection is also accepted because it cannot
     * persist data outside the test process.
     */
    public static function assertSafeTestingEnvironment(): void
    {
        self::assertPreBootstrapTestingEnvironment();
        self::failUnless(app()->environment('testing'), 'PHPUNIT SAFETY GATE: APP_ENV must be "testing".');

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

        self::assertTestingConnectionAllowed($default, $connection);

        // No outbound side effects during tests.
        self::failUnless(config('mail.default') === 'array', 'PHPUNIT SAFETY GATE: mail must use the array transport.');
        self::failUnless(config('queue.default') === 'sync', 'PHPUNIT SAFETY GATE: queue must use the sync driver.');
    }

    /**
     * Validate a specifically named connection (including RefreshDatabase's
     * $connectionsToTransact and any DB::connection('name') target).
     */
    public static function assertTestingConnectionAllowed(string $name, array $config): void
    {
        self::failUnless(trim($name) !== '', 'PHPUNIT SAFETY GATE: connection name is missing.');
        self::assertTestingConnectionConfigurationAllowed($config, 'connection "'.$name.'"');
    }

    /**
     * Used by SafeDatabaseManager after Laravel has parsed DATABASE_URL but
     * before a PDO connection is created. This is the defence against runtime
     * config()->set() / DB::purge() / DB::connection() mutation.
     */
    public static function assertResolvedTestingConnectionAllowed(string $name, array $config): void
    {
        self::failUnless(app()->environment('testing'), 'PHPUNIT SAFETY GATE: resolved connection may only be checked while testing.');
        self::assertTestingConnectionAllowed($name, $config);
    }

    /** @param list<string> $names */
    public static function assertNamedTestingConnectionsAllowed(array $names): void
    {
        foreach ($names as $name) {
            self::failUnless(is_string($name) && trim($name) !== '', 'PHPUNIT SAFETY GATE: declared connection name is invalid.');

            $config = config('database.connections.'.$name);
            self::failUnless(is_array($config), 'PHPUNIT SAFETY GATE: no configuration for declared connection "'.$name.'".');
            self::assertTestingConnectionAllowed($name, $config);
        }
    }

    /**
     * Pure configuration check used by the guards and by non-executed static
     * regression tests. It parses DATABASE_URL the same way Laravel does and
     * rejects any redirect or ambiguity before applying the target policy.
     */
    public static function assertTestingConnectionConfigurationAllowed(array $config, string $context = 'effective config'): void
    {
        $gate = 'PHPUNIT SAFETY GATE ['.$context.']';
        $resolution = self::resolveConnectionConfiguration($config, $gate);
        $resolved = $resolution['config'];

        self::assertUrlDoesNotConflict($config, $resolution['components'], $gate);

        $driver = (string) ($resolved['driver'] ?? '');
        $database = $resolved['database'] ?? null;

        self::failUnless($driver !== '', $gate.': driver is missing or ambiguous.');
        self::failUnless(
            is_string($database) && trim($database) !== '',
            $gate.': database name is missing or ambiguous.',
        );
        self::failUnless(
            ! self::isForbiddenDatabase($database),
            $gate.': forbidden database "'.$database.'" cannot be used by tests.',
        );
        self::failUnless(
            trim((string) ($resolved['unix_socket'] ?? '')) === '',
            $gate.': unix_socket must not redirect the connection.',
        );

        $host = strtolower(trim((string) ($resolved['host'] ?? '')));
        $port = (string) ($resolved['port'] ?? '');

        if ($driver === 'mysql') {
            self::failUnless(
                in_array($host, self::ALLOWED_LOCAL_HOSTS, true),
                $gate.': MySQL host "'.$host.'" is not an approved local host.',
            );
            self::failUnless(
                $port === self::ALLOWED_LOCAL_PORT,
                $gate.': MySQL port "'.$port.'" is not the approved local port.',
            );
            self::failUnless(
                $database === self::TEST_DISPOSABLE_DATABASE,
                $gate.': MySQL tests may only target "'.self::TEST_DISPOSABLE_DATABASE.'"; resolved "'.$database.'".',
            );

            return;
        }

        if ($driver === 'sqlite') {
            // SQLite ignores host/port, but reject values that suggest a remote
            // target or an ambiguous override.
            if ($host !== '') {
                self::failUnless(
                    in_array($host, self::ALLOWED_LOCAL_HOSTS, true),
                    $gate.': SQLite host "'.$host.'" is not an approved local value.',
                );
            }
            if ($port !== '') {
                self::failUnless(
                    $port === self::ALLOWED_LOCAL_PORT,
                    $gate.': SQLite port "'.$port.'" is not an approved local value.',
                );
            }

            self::failUnless(
                (bool) ($resolved['foreign_key_constraints'] ?? false),
                $gate.': SQLite foreign key enforcement must be enabled.',
            );

            if ($database === ':memory:') {
                // A named in-memory SQLite connection cannot persist outside
                // the test process and is used by existing schema tests.
                return;
            }

            self::assertApprovedSqliteFile((string) $database, $gate);

            return;
        }

        throw new RuntimeException($gate.': unsupported driver "'.$driver.'"; refusing to run.');
    }

    /**
     * Pre-execution gate for `yamak:seed-mongo-dump`. Called as the very first
     * statement of handle(), before path checks, BSON conversion, cleanup or
     * any import write. It relies on explicit approved configuration, not on
     * an interactive confirmation prompt.
     */
    public static function assertImportAllowed(bool $destructive): void
    {
        $gate = 'IMPORT SAFETY GATE';

        self::failUnless(
            app()->environment('local'),
            $gate.': the legacy import command requires APP_ENV=local.',
        );

        $default = config('database.default');
        self::failUnless(
            $default === 'mysql',
            $gate.': the legacy import command requires the mysql default connection.',
        );

        $connection = config('database.connections.mysql');
        self::failUnless(is_array($connection), $gate.': mysql connection configuration is missing.');

        $resolution = self::resolveConnectionConfiguration($connection, $gate);
        $resolved = $resolution['config'];
        self::assertUrlDoesNotConflict($connection, $resolution['components'], $gate);

        self::failUnless(
            ($resolved['driver'] ?? null) === 'mysql',
            $gate.': mysql connection driver mismatch.',
        );

        $database = $resolved['database'] ?? null;
        self::failUnless(
            is_string($database) && trim($database) !== '',
            $gate.': database name is missing or ambiguous.',
        );
        self::failUnless(
            ! self::isForbiddenDatabase($database),
            $gate.': forbidden database "'.$database.'" cannot be imported into.',
        );
        self::failUnless(
            $database === self::IMPORT_DISPOSABLE_DATABASE,
            $gate.': only the dedicated disposable database "'.self::IMPORT_DISPOSABLE_DATABASE.'" is approved; resolved "'.$database.'".',
        );

        $host = strtolower(trim((string) ($resolved['host'] ?? '')));
        $port = (string) ($resolved['port'] ?? '');
        self::failUnless(
            in_array($host, self::ALLOWED_LOCAL_HOSTS, true),
            $gate.': only local MySQL hosts are allowed for legacy import.',
        );
        self::failUnless(
            $port === self::ALLOWED_LOCAL_PORT,
            $gate.': only the approved local MySQL port is allowed for legacy import.',
        );
        self::failUnless(
            trim((string) ($resolved['unix_socket'] ?? '')) === '',
            $gate.': unix_socket must not redirect the import connection.',
        );

        // Explicit, out-of-band acknowledgement. Defaults are all deny.
        $acknowledged = (string) config('database-safety.import.allow', '');
        $declaredDatabase = (string) config('database-safety.import.database', '');
        $destructiveAcknowledged = (string) config('database-safety.import.allow_destructive', '');

        self::failUnless(
            $acknowledged === '1',
            $gate.': set YAMAK_IMPORT_ALLOW=1 to authorise this import.',
        );
        self::failUnless(
            $declaredDatabase !== '' && hash_equals($declaredDatabase, (string) $database),
            $gate.': YAMAK_IMPORT_DATABASE must explicitly match the effective database.',
        );

        if ($destructive) {
            self::failUnless(
                $destructiveAcknowledged === '1',
                $gate.': set YAMAK_IMPORT_ALLOW_DESTRUCTIVE=1 for --clean; it is never allowed against "yamak_integration_test".',
            );
        }
    }

    private static function assertApprovedSqliteFile(string $database, string $gate): void
    {
        self::failUnless(self::isAbsolutePath($database), $gate.': sqlite database must be an absolute path.');
        self::failUnless(str_ends_with(strtolower($database), '.sqlite'), $gate.': sqlite database must be a .sqlite file.');
        self::failUnless(
            str_contains($database, 'OpenCodeTemp'),
            $gate.': sqlite database must live in the approved OpenCodeTemp directory.',
        );
        self::failUnless(
            ! str_contains(strtolower($database), 'yamakproject'),
            $gate.': sqlite database must be outside the project repositories.',
        );
    }

    /** @return array{config: array, url: ?string, components: array} */
    private static function resolveConnectionConfiguration(array $config, string $gate): array
    {
        $url = $config['url'] ?? null;
        self::failUnless(
            $url === null || is_string($url),
            $gate.': DATABASE_URL configuration is ambiguous.',
        );

        if (! is_string($url) || trim($url) === '') {
            return ['config' => $config, 'url' => null, 'components' => []];
        }

        $components = parse_url($url);
        self::failUnless($components !== false, $gate.': DATABASE_URL is malformed.');

        try {
            $resolved = (new ConfigurationUrlParser())->parseConfiguration($config);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException($gate.': DATABASE_URL could not be parsed.', 0, $exception);
        }

        return ['config' => $resolved, 'url' => $url, 'components' => $components];
    }

    /** @param array<string, mixed> $components */
    private static function assertUrlDoesNotConflict(array $config, array $components, string $gate): void
    {
        $url = $config['url'] ?? null;
        if (! is_string($url) || trim($url) === '') {
            return;
        }

        $scheme = strtolower(trim((string) ($components['scheme'] ?? '')));
        if ($scheme !== '') {
            self::failUnless(
                self::normaliseDriver($scheme) === self::normaliseDriver((string) ($config['driver'] ?? '')),
                $gate.': DATABASE_URL scheme conflicts with the configured driver.',
            );
        }

        if (isset($components['query']) && is_string($components['query']) && $components['query'] !== '') {
            $query = [];
            parse_str($components['query'], $query);
            foreach (self::URL_OVERRIDE_KEYS as $key) {
                self::failUnless(
                    ! array_key_exists($key, $query),
                    $gate.': DATABASE_URL query option "'.$key.'" is not permitted.',
                );
            }
        }

        if (isset($components['host']) && trim((string) ($config['host'] ?? '')) !== '') {
            self::failUnless(
                strtolower((string) $components['host']) === strtolower((string) $config['host']),
                $gate.': DATABASE_URL host conflicts with DB_HOST.',
            );
        }

        if (isset($components['port']) && (string) ($config['port'] ?? '') !== '') {
            self::failUnless(
                (string) $components['port'] === (string) $config['port'],
                $gate.': DATABASE_URL port conflicts with DB_PORT.',
            );
        }

        $path = $components['path'] ?? null;
        if (is_string($path) && $path !== '' && $path !== '/' && trim((string) ($config['database'] ?? '')) !== '') {
            $urlDatabase = rawurldecode(substr($path, 1));
            self::failUnless(
                $urlDatabase === (string) $config['database'],
                $gate.': DATABASE_URL database conflicts with DB_DATABASE.',
            );
        }

        // Every resolved value is independently validated by
        // assertTestingConnectionConfigurationAllowed().
    }

    /** @return list<string> */
    private static function readRawEnvironmentValues(string $key): array
    {
        $values = [];
        $sources = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];

        foreach ($sources as $value) {
            if ($value === false || $value === null) {
                continue;
            }
            self::failUnless(
                is_scalar($value),
                'PHPUNIT SAFETY GATE: '.$key.' is not a scalar environment value.',
            );

            $string = (string) $value;
            if (! in_array($string, $values, true)) {
                $values[] = $string;
            }
        }

        return $values;
    }

    private static function rawBoolean(?string $value, bool $default): bool
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function normaliseDriver(string $driver): string
    {
        $driver = strtolower(trim($driver));

        return self::DRIVER_ALIASES[$driver] ?? $driver;
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
