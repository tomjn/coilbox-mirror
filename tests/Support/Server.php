<?php

declare(strict_types=1);

namespace Mirror\Tests\Support;

/** Runs PHP's built-in web server on a free local port for the duration of a test. */
final class Server
{
    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function __construct(
        private $process,
        private readonly array $pipes,
        private readonly string $url,
    ) {
    }

    /**
     * @param string $docroot the folder to serve
     * @param string|null $router a router script, as in `php -S host:port -t docroot router`
     */
    public static function start(string $docroot, ?string $router = null): self
    {
        $port = self::freePort();
        $command = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $docroot];
        if ($router !== null) {
            $command[] = $router;
        }

        // Server output goes to a file next to the docroot so it stays out of the test output.
        $log = rtrim($docroot, '/') . '-server.log';
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
        );
        if ($process === false) {
            throw new \RuntimeException('Cannot start the test server');
        }
        $server = new self($process, $pipes, "http://127.0.0.1:$port");

        try {
            $server->waitUntilListening($port);
        } catch (\Throwable $e) {
            $server->stop();
            throw $e;
        }
        return $server;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function stop(): void
    {
        if (!is_resource($this->process)) {
            return;
        }
        proc_terminate($this->process);
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($this->process);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new \RuntimeException("Cannot find a free port: $error");
        }
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function waitUntilListening(int $port): void
    {
        $limit = (float) ini_get('default_socket_timeout');
        $start = microtime(true);
        while (microtime(true) - $start < $limit) {
            if (!(proc_get_status($this->process)['running'] ?? false)) {
                throw new \RuntimeException('The test server exited before it started listening');
            }
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
            usleep(20000);
        }
        throw new \RuntimeException("The test server did not listen on port $port within $limit seconds");
    }
}
