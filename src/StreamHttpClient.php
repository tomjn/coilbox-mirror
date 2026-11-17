<?php

declare(strict_types=1);

namespace Mirror;

/**
 * Outbound HTTP over PHP URL streams, so it needs allow_url_fopen and openssl.
 *
 * Redirects are a per-client choice. The hub is trusted, so its client may follow them. Peer URLs come from
 * the catalogue and point at servers nobody here controls, so the peer client must not follow them. A peer
 * that redirected to an internal address would otherwise make the mirror send requests inside its own network.
 */
final class StreamHttpClient implements HttpClient
{
    private const USER_AGENT = 'coilbox-mirror';
    private const BLOCK = 8192;

    public function __construct(private readonly bool $followRedirects)
    {
    }

    public function get(string $url, ?int $maxBytes = null): HttpResponse
    {
        return $this->request($url, $this->context('GET', []), $maxBytes);
    }

    public function postJson(string $url, string $body): HttpResponse
    {
        return $this->request($url, $this->context('POST', ['Content-Type: application/json'], $body));
    }

    public function download(string $url, string $dest, int $expectedSize, Deadline $deadline): DownloadResult
    {
        clearstatcache(true, $dest);
        $have = is_file($dest) ? (int) filesize($dest) : 0;
        if ($have === $expectedSize) {
            return DownloadResult::Complete;
        }

        $headers = [];
        if ($have > 0 && $have < $expectedSize) {
            $headers[] = 'Range: bytes=' . $have . '-';
        }
        $in = @fopen($url, 'rb', false, $this->context('GET', $headers));
        if ($in === false) {
            return DownloadResult::Failed;
        }
        $status = self::status();
        if ($status !== 200 && $status !== 206) {
            fclose($in);
            return DownloadResult::Failed;
        }

        // A 206 continues the partial file. A 200 means the server ignored the range, so start again.
        $out = @fopen($dest, $status === 206 ? 'ab' : 'wb');
        if ($out === false) {
            fclose($in);
            return DownloadResult::Failed;
        }
        $size = $status === 206 ? $have : 0;

        $result = DownloadResult::Complete;
        while (!feof($in)) {
            $block = $deadline->step(static fn (): string|false => fread($in, self::BLOCK));
            if ($block === false || ($block === '' && !feof($in))) {
                $result = DownloadResult::Failed;
                break;
            }
            if ($block !== '') {
                if (fwrite($out, $block) !== strlen($block)) {
                    $result = DownloadResult::Failed;
                    break;
                }
                $size += strlen($block);
            }
            if ($size > $expectedSize) {
                $result = DownloadResult::Failed;
                break;
            }
            if ($size < $expectedSize && !feof($in) && $deadline->expired()) {
                $result = DownloadResult::Stopped;
                break;
            }
        }
        fclose($in);
        fclose($out);

        if ($result === DownloadResult::Complete && $size !== $expectedSize) {
            return DownloadResult::Failed;
        }
        return $result;
    }

    /** @param list<string> $headers */
    private function context(string $method, array $headers, ?string $body = null)
    {
        $headers[] = 'User-Agent: ' . self::USER_AGENT;
        $http = [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'follow_location' => $this->followRedirects ? 1 : 0,
            'timeout' => (float) ini_get('default_socket_timeout'),
        ];
        if ($body !== null) {
            $http['content'] = $body;
        }
        return stream_context_create(['http' => $http]);
    }

    private function request(string $url, $context, ?int $maxBytes = null): HttpResponse
    {
        // Reading one byte past the limit is enough to know the body is too long.
        $body = $maxBytes === null
            ? @file_get_contents($url, false, $context)
            : @file_get_contents($url, false, $context, 0, $maxBytes + 1);
        if ($body === false || ($maxBytes !== null && strlen($body) > $maxBytes)) {
            return new HttpResponse(0, '');
        }
        return new HttpResponse(self::status(), $body, self::headers());
    }

    /** Redirects add earlier status lines, so the last one is the final response. */
    private static function status(): int
    {
        $status = 0;
        foreach (http_get_last_response_headers() ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        return $status;
    }

    /**
     * Returns the headers of the final response only, with lowercase names.
     *
     * @return array<string, string>
     */
    private static function headers(): array
    {
        $headers = [];
        foreach (http_get_last_response_headers() ?? [] as $line) {
            if (str_starts_with($line, 'HTTP/')) {
                $headers = [];
            } elseif (preg_match('/^([^:\s]+):\s*(.*)$/', $line, $m) === 1) {
                $headers[strtolower($m[1])] = $m[2];
            }
        }
        return $headers;
    }
}
