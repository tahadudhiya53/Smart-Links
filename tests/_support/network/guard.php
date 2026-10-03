<?php

// Prepended to every PHP process NetworkIsolationTest starts (auto_prepend_file). It takes over
// every way PHP code can reach the network, records each attempt to the file named by
// SMARTLINKS_NETWORK_LOG, and fails it. The attempt is recorded before anything is thrown, so
// one that the calling code catches and ignores is still on record.
//
// - URL streams (file_get_contents, fopen, get_headers, Guzzle's stream handler…): the http,
//   https, ftp and ftps wrappers are replaced.
// - cURL (Guzzle's default handler), sockets and DNS: the functions are disabled in the ini file
//   that loads this guard, and declared here instead, which PHP allows for a disabled function.
//
// The database is reached by PDO in C, not through these, so Craft runs as usual.

namespace {
    function smartlinks_network_attempt(string $kind, mixed $target): never
    {
        $origin = null;

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['file'] ?? __FILE__) !== __FILE__) {
                $origin = ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?');
                break;
            }
        }

        $log = getenv('SMARTLINKS_NETWORK_LOG');

        if (is_string($log) && $log !== '') {
            file_put_contents($log, json_encode(['kind' => $kind, 'target' => is_scalar($target) ? (string)$target : get_debug_type($target), 'origin' => $origin]) . "\n", FILE_APPEND | LOCK_EX);
        }

        throw new \RuntimeException("Network access ($kind) is not allowed here.");
    }

    final class SmartLinksNetworkGuardStream
    {
        /** @var resource|null */
        public $context;

        public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
        {
            smartlinks_network_attempt('url-stream', $path);
        }

        public function url_stat(string $path, int $flags): array|false
        {
            smartlinks_network_attempt('url-stat', $path);
        }
    }

    foreach (['http', 'https', 'ftp', 'ftps'] as $protocol) {
        if (in_array($protocol, stream_get_wrappers(), true)) {
            stream_wrapper_unregister($protocol);
        }

        stream_wrapper_register($protocol, SmartLinksNetworkGuardStream::class);
    }

    // Declared only where the ini file disabled the built-in function of the same name.
    $guarded = [
        'curl_init', 'curl_exec', 'curl_multi_exec', 'curl_multi_init', 'curl_share_init',
        'fsockopen', 'pfsockopen', 'stream_socket_client', 'get_headers',
        'socket_connect', 'socket_sendto',
        'gethostbyname', 'gethostbynamel', 'gethostbyaddr', 'dns_get_record', 'checkdnsrr', 'dns_check_record', 'getmxrr', 'dns_get_mx',
    ];

    foreach ($guarded as $function) {
        if (function_exists($function)) {
            fwrite(STDERR, "The network guard needs $function() disabled in its ini file.\n");
            exit(3);
        }

        eval("function $function(...\$arguments) { smartlinks_network_attempt('$function', \$arguments[0] ?? null); }");
    }
}
