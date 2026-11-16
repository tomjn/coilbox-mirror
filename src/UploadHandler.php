<?php

declare(strict_types=1);

namespace Mirror;

/** Receives upload chunks for a hub-signed token and appends them to a partial file. */
final class UploadHandler
{
    private const STATUS = [
        'bad_request' => 400,
        'bad_token' => 403,
        'wrong_mirror' => 403,
        'expired' => 410,
        'removed' => 410,
        'used' => 409,
        'bad_offset' => 409,
        'too_large' => 413,
        'write_failed' => 500,
        'method_not_allowed' => 405,
    ];

    /** @param \Closure(): int $now returns the time in Unix seconds */
    public function __construct(
        private readonly Paths $paths,
        private readonly Config $config,
        private readonly CatalogueStore $store,
        private readonly HttpClient $http,
        private readonly \Closure $now,
    ) {
    }

    public function handle(Request $r): Response
    {
        try {
            return match ($r->method) {
                'GET' => $this->status($r),
                'POST' => $this->receive($r),
                default => throw new ProtocolError('method_not_allowed'),
            };
        } catch (ProtocolError $e) {
            return new Response(self::STATUS[$e->error] ?? 500, ['error' => $e->error] + $e->extra);
        }
    }

    private function status(Request $r): Response
    {
        $token = $this->verify($r);
        if (is_file($this->usedFile($token))) {
            return new Response(200, ['received' => $token->size, 'complete' => true]);
        }
        clearstatcache(true, $this->partialFile($token));
        $size = @filesize($this->partialFile($token));
        return new Response(200, ['received' => $size === false ? 0 : $size, 'complete' => false]);
    }

    private function receive(Request $r): Response
    {
        $token = $this->verify($r);
        if (is_file($this->usedFile($token))) {
            throw new ProtocolError('used');
        }

        $chunk = $this->openChunk($r->file);
        try {
            $chunkSize = fstat($chunk)['size'];
            if ($chunkSize < 1) {
                throw new ProtocolError('bad_request');
            }
            return $this->append($token, $r, $chunk, $chunkSize);
        } finally {
            fclose($chunk);
        }
    }

    /**
     * @param resource $chunk
     * @throws ProtocolError
     */
    private function append(Token $token, Request $r, $chunk, int $chunkSize): Response
    {
        $path = $this->partialFile($token);
        $out = $this->lockPartial($path);
        try {
            $have = fstat($out)['size'];

            // A partial with no bytes is a first chunk, so the start-by and removal checks apply.
            if ($have === 0) {
                $token->checkStartBy(($this->now)());
                if ($this->store->isRemoved($token->sha256, $token->filename)) {
                    throw new ProtocolError('removed');
                }
            }

            $offset = $r->headers['x-mirror-offset'] ?? null;
            if (!is_string($offset) || preg_match('/\A(0|[1-9][0-9]{0,18})\z/', $offset) !== 1) {
                throw new ProtocolError('bad_request');
            }
            // The text has no leading zeros, so comparing text also copes with numbers beyond PHP_INT_MAX.
            if ($offset !== (string) $have) {
                throw new ProtocolError('bad_offset', ['received' => $have]);
            }
            if ($have + $chunkSize > $token->size) {
                throw new ProtocolError('too_large');
            }

            if (fseek($out, $have) !== 0) {
                throw new ProtocolError('write_failed');
            }
            $written = stream_copy_to_stream($chunk, $out);
            if ($written !== $chunkSize || !fflush($out)) {
                ftruncate($out, $have);
                throw new ProtocolError('write_failed');
            }

            $received = $have + $chunkSize;
            if ($received === $token->size) {
                return $this->finish($token, $path, $received);
            }
            return new Response(200, ['received' => $received, 'complete' => false]);
        } finally {
            // Leave no empty partial behind when the first chunk was refused. The lock is still held,
            // so a request waiting on it sees a different file and starts again.
            if (fstat($out)['size'] === 0) {
                @unlink($path);
            }
            fclose($out);
        }
    }

    /** Task 9 adds the steps that check and place the finished file. */
    private function finish(Token $token, string $partialPath, int $received): Response
    {
        return new Response(200, ['received' => $received, 'complete' => false]);
    }

    /**
     * Opens the partial, creating it if needed, and takes the lock, waiting if another request holds it.
     *
     * @return resource
     */
    private function lockPartial(string $path)
    {
        while (true) {
            $out = fopen($path, 'c+');
            if ($out === false) {
                throw new \RuntimeException("Cannot open $path");
            }
            if (!flock($out, LOCK_EX)) {
                fclose($out);
                throw new \RuntimeException("Cannot lock $path");
            }
            // The previous holder may have deleted an empty partial while this request waited.
            clearstatcache(true, $path);
            $onDisk = @stat($path);
            if ($onDisk !== false && $onDisk['ino'] === fstat($out)['ino']) {
                return $out;
            }
            fclose($out);
        }
    }

    /**
     * @param array<string, mixed>|null $file
     * @return resource
     * @throws ProtocolError bad_request when the chunk is missing, failed or cannot be read
     */
    private function openChunk(?array $file)
    {
        $tmp = $file['tmp_name'] ?? null;
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($tmp) || $tmp === '') {
            throw new ProtocolError('bad_request');
        }
        $handle = @fopen($tmp, 'rb');
        if ($handle === false) {
            throw new ProtocolError('bad_request');
        }
        return $handle;
    }

    private function verify(Request $r): Token
    {
        $raw = $r->headers['x-mirror-token'] ?? null;
        if (!is_string($raw) || $raw === '') {
            throw new ProtocolError('bad_request');
        }
        return Token::verify($raw, $this->store->trustedKeys(), $this->config->mirrorId);
    }

    private function partialFile(Token $token): string
    {
        return $this->paths->partial() . '/upload-' . $token->uploadId;
    }

    private function usedFile(Token $token): string
    {
        return $this->paths->state() . '/used/' . $token->uploadId;
    }
}
