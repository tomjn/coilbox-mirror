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
        'hash_mismatch' => 422,
        'exists' => 409,
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
        $this->paths->ensureState();
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
            $outcome = $this->append($token, $r, $chunk, $chunkSize);
        } finally {
            fclose($chunk);
        }
        if ($outcome instanceof Response) {
            return $outcome;
        }
        // The partial lock and the disk lock are both released, so a slow hub holds neither.
        return new Response(201, [
            'complete' => true,
            'received' => $token->size,
            'path' => Paths::folderFor($token->kind) . '/' . $token->filename,
            // A file missing from the listing would make the report claim the hub's copy is unneeded, so skip it.
            'reported' => $outcome && $this->report($token),
        ]);
    }

    /**
     * @param resource $chunk
     * @return Response|bool the response for a chunk that is not the last, or once the file is in place
     *                       whether its listing entry was written
     * @throws ProtocolError
     */
    private function append(Token $token, Request $r, $chunk, int $chunkSize): Response|bool
    {
        $path = $this->partialFile($token);
        $out = $this->lockPartial($path);
        try {
            // A request that waited on the lock may have found the upload finished by the previous holder.
            if (is_file($this->usedFile($token))) {
                throw new ProtocolError('used');
            }
            $have = fstat($out)['size'];

            // A full partial means an earlier finish died after the last byte, for example when PHP was killed
            // while hashing. The bytes are all here, so finish now instead of refusing every resend.
            if ($have === $token->size) {
                return $this->finish($token, $path);
            }

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
            $written = stream_copy_to_stream($chunk, $out, $chunkSize);
            if ($written !== $chunkSize || !fflush($out)) {
                ftruncate($out, $have);
                throw new ProtocolError('write_failed');
            }

            $received = $have + $chunkSize;
            if ($received === $token->size) {
                return $this->finish($token, $path);
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

    /**
     * Checks the finished partial and moves it into the public folder. The caller holds the partial lock.
     * Returns whether the listing entry was written. A false means the file is in place but unlisted.
     *
     * Any failure deletes the partial first, so a failed finish never leaves a full partial that refuses every resend.
     *
     * @throws ProtocolError hash_mismatch, exists or write_failed
     */
    private function finish(Token $token, string $partialPath): bool
    {
        try {
            return $this->checkAndPlace($token, $partialPath);
        } catch (\Throwable $e) {
            @unlink($partialPath);
            throw $e;
        }
    }

    /** The body of finish(). Every throw here reaches finish(), which deletes the partial. */
    private function checkAndPlace(Token $token, string $partialPath): bool
    {
        $hash = hash_file('sha256', $partialPath);
        if ($hash === false || !hash_equals($token->sha256, $hash)) {
            throw new ProtocolError('hash_mismatch');
        }

        $disk = Lock::acquire($this->paths->state() . '/disk.lock', true);
        if ($disk === null) {
            throw new ProtocolError('write_failed');
        }
        try {
            $dir = $this->paths->dirFor($token->kind);
            $names = @scandir($dir);
            if ($names === false) {
                throw new ProtocolError('write_failed');
            }
            $wanted = mb_strtolower($token->filename);
            foreach ($names as $name) {
                if (mb_strtolower($name) === $wanted) {
                    throw new ProtocolError('exists');
                }
            }

            if (!@rename($partialPath, "$dir/$token->filename")) {
                throw new ProtocolError('write_failed');
            }
            // The file is in place now, so later failures do not fail the upload. A replay of this upload id
            // is refused anyway because the name exists, and the next sync lists and reports the file.
            if (!@touch($this->usedFile($token))) {
                error_log("mirror: cannot write used marker for upload $token->uploadId");
            }
            try {
                Listing::addFile($this->paths, $token->kind, $token->filename, ($this->now)(), $hash);
                return true;
            } catch (\Throwable $e) {
                error_log("mirror: cannot list $token->filename after upload $token->uploadId: " . $e->getMessage());
                return false;
            }
        } finally {
            $disk->release();
        }
    }

    private function report(Token $token): bool
    {
        // The file is in place by now, so nothing here may turn the 201 into an error.
        try {
            $now = ($this->now)();
            $listing = Listing::load($this->paths) ?? ['v' => 1, 'generated_at' => $now, 'files' => []];
            $payload = Report::payload($this->config, $this->store->held(), $listing, 'upload', $token->uploadId, $now);
            return Report::send($this->http, $this->config, Report::body($payload, $this->config->secretKey));
        } catch (\Throwable $e) {
            error_log("mirror: upload report for $token->uploadId failed: " . $e->getMessage());
            return false;
        }
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
