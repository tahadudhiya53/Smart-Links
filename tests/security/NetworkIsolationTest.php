<?php

namespace Tahadudhiya\SmartLinks\Tests\security;

use craft\helpers\FileHelper;
use craft\helpers\Json;
use PHPUnit\Framework\TestCase;

/**
 * Smart Links' link types never reach the network while authoring, validating, storing,
 * resolving or rendering links.
 *
 * Test suites run in PHP processes under a guard (tests/_support/network/guard.php) that records
 * and refuses every attempt to reach the network: URL streams, cURL (and so Guzzle), sockets and
 * DNS. The guard is shown to catch each kind, even when the code making the attempt ignores the
 * failure, and then the link type, GraphQL and field suites are run under it, and must pass with
 * not one attempt on record.
 */
final class NetworkIsolationTest extends TestCase
{
    /** The PHP functions that reach the network, which the guard stands in for. */
    private const GUARDED = [
        'curl_init', 'curl_exec', 'curl_multi_exec', 'curl_multi_init', 'curl_share_init',
        'fsockopen', 'pfsockopen', 'stream_socket_client', 'get_headers',
        'socket_connect', 'socket_sendto',
        'gethostbyname', 'gethostbynamel', 'gethostbyaddr', 'dns_get_record', 'checkdnsrr', 'dns_check_record', 'getmxrr', 'dns_get_mx',
    ];

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/smart-links-network-' . bin2hex(random_bytes(6));
        FileHelper::createDirectory($this->directory);
        file_put_contents($this->directory . '/zz-smart-links-network-guard.ini', implode("\n", [
            'disable_functions = ' . implode(',', self::GUARDED),
            'auto_prepend_file = "' . realpath(__DIR__ . '/../_support/network/guard.php') . '"',
            '',
        ]));
    }

    protected function tearDown(): void
    {
        FileHelper::removeDirectory($this->directory);
    }

    public function testTheGuardRecordsEveryKindOfNetworkAttemptEvenWhenItIsIgnored(): void
    {
        [$exit, $output, $errors, $log] = $this->runGuarded([PHP_BINARY, __DIR__ . '/../_support/network/attempts.php']);

        self::assertSame(0, $exit, $errors . $output);
        $recorded = Json::decode($output);
        self::assertIsArray($recorded, $output);

        foreach ($recorded as $kind => $count) {
            self::assertGreaterThanOrEqual(1, $count, "The guard missed: $kind");
        }

        self::assertCount(12, $recorded);
        self::assertNotSame([], $log);
    }

    public function testTheLinkTypeGraphqlAndFieldSuitesMakeNoNetworkAttempt(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['tests/integration/LinkTypesTest.php', 'tests/integration/GraphqlTest.php', 'tests/integration/SmartLinkFieldTest.php'] as $suite) {
            [$exit, $output, $errors, $log] = $this->runGuarded([PHP_BINARY, "$root/vendor/bin/phpunit", '-c', "$root/phpunit.integration.xml.dist", "$root/$suite"]);

            self::assertSame(0, $exit, "$suite failed under the guard:\n" . $errors . substr($output, -4000));
            self::assertStringContainsString('OK', $output, $suite);
            self::assertSame([], $log, "$suite reached for the network.");
        }
    }

    /**
     * Runs a command in PHP processes under the guard: this one, and every PHP process it starts.
     *
     * @param list<string> $command
     * @return array{int, string, string, list<array<string, mixed>>} Exit code, output, errors and
     * the attempts the guard recorded.
     */
    private function runGuarded(array $command): array
    {
        $log = $this->directory . '/attempts.log';
        $environment = [
            // A leading separator keeps PHP's own ini directory, and adds the guard's after it.
            'PHP_INI_SCAN_DIR' => PATH_SEPARATOR . $this->directory,
            'SMARTLINKS_NETWORK_LOG' => $log,
        ] + getenv();

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 4), $environment);
        self::assertIsResource($process);
        $output = (string)stream_get_contents($pipes[1]);
        $errors = (string)stream_get_contents($pipes[2]);
        $exit = proc_close($process);

        $attempts = is_file($log) ? array_map(static fn(string $line): array => (array)Json::decode($line), file($log, FILE_IGNORE_NEW_LINES) ?: []) : [];

        return [$exit, $output, $errors, $attempts];
    }
}
