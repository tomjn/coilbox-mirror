<?php

declare(strict_types=1);

namespace Mirror;

use Closure;

/** Brings the mirror in line with the hub: catalogue, removals, files, partial cleanup and the report. */
final class Sync
{
    /**
     * Log lines that report normal work. The log has no severity, so sync.php prints every other line as a
     * failure when it runs quietly. Keep these in step with the messages below. SyncRoutineTest checks them.
     */
    public const ROUTINE_EXACT = ['another sync is running'];
    public const ROUTINE_PREFIXES = ['catalogue accepted from ', 'fetched ', 'removed '];
    public const ROUTINE_SUFFIXES = [' is already present', ' rejected: not_newer'];

    /** True when the line reports normal work, such as an unchanged catalogue, and not a failure. */
    public static function isRoutine(string $line): bool
    {
        if (in_array($line, self::ROUTINE_EXACT, true)) {
            return true;
        }
        foreach (self::ROUTINE_PREFIXES as $prefix) {
            if (str_starts_with($line, $prefix)) {
                return true;
            }
        }
        foreach (self::ROUTINE_SUFFIXES as $suffix) {
            if (str_ends_with($line, $suffix)) {
                return true;
            }
        }
        return false;
    }

    /** @var Closure(list<string>): list<string> */
    private readonly Closure $orderPeers;

    /**
     * @param HttpClient $http talks to the hub, which is trusted, so it may follow redirects
     * @param HttpClient $peerHttp talks to peers, and must not follow redirects
     * @param Closure(): int $now returns the current Unix time
     * @param Closure(string): void $log receives one line per event
     * @param (Closure(list<string>): list<string>)|null $orderPeers puts peer base URLs in the order to try them,
     *                                                  once per fetch. The default shuffles them.
     */
    public function __construct(
        private readonly Paths $paths,
        private readonly Config $config,
        private readonly CatalogueStore $store,
        private readonly HttpClient $http,
        private readonly HttpClient $peerHttp,
        private readonly Deadline $deadline,
        private readonly Closure $now,
        private readonly Closure $log,
        ?Closure $orderPeers = null,
    ) {
        // A shuffle spreads the load, so every mirror does not ask the first peer in the catalogue first.
        $this->orderPeers = $orderPeers ?? static function (array $urls): array {
            shuffle($urls);
            return $urls;
        };
    }

    /** Returns the exit code. */
    public function run(): int
    {
        $this->paths->ensureState();
        $lockPath = $this->paths->state() . '/sync.lock';
        $lock = Lock::acquire($lockPath, false);
        if ($lock === null) {
            return $this->otherRunHoldsTheLock($lockPath);
        }

        try {
            if (!$lock->write((string) ($this->now)())) {
                $this->log('could not record the start time in sync.lock');
            }
            $ok = $this->phase('catalogue refresh', fn () => $this->refreshCatalogue());
            $ok = $this->phase('listing rebuild', fn () => $this->rebuildListing()) && $ok;
            $ok = $this->phase('removals', fn () => $this->applyRemovals()) && $ok;
            $ok = $this->phase('fetch', fn () => $this->fetchMissing()) && $ok;
            $ok = $this->phase('partial cleanup', fn () => $this->cleanPartials()) && $ok;
            $ok = $this->phase('listing rebuild', fn () => $this->rebuildListing()) && $ok;
            $reported = $this->report();

            return $ok && $reported && $this->store->held() !== null ? 0 : 1;
        } finally {
            $lock->release();
        }
    }

    /**
     * Another run holding the lock is routine, unless the operator set sync_time_limit and that run started
     * longer ago than the limit. Then it is probably stuck on a slow peer, and the operator needs to know.
     */
    private function otherRunHoldsTheLock(string $lockPath): int
    {
        $limit = $this->config->syncTimeLimit;
        $started = @file_get_contents($lockPath);
        if (
            $limit > 0
            && is_string($started)
            && preg_match('/\A[0-9]{1,19}\z/', $started) === 1
            && ($this->now)() - (int) $started > $limit
        ) {
            $this->log("sync has been running for over $limit seconds");
            return 1;
        }
        $this->log('another sync is running');
        return 0;
    }

    /** @return string|null 'hub', 'peer:<id>', or null when nothing new was accepted */
    public function refreshCatalogue(): ?string
    {
        $hub = $this->config->hubUrl;
        $outcome = $this->tryFetch('hub', "$hub/api/v1/catalogue", "$hub/api/v1/catalogue.sig");
        if ($outcome === true) {
            return 'hub';
        }
        if ($outcome === 'not_newer') {
            return null;
        }

        // Policy: a peer's catalogue may be at most twice the size of the one held, which leaves room for the
        // catalogue to grow between syncs. Without a cap a peer could send enough to exhaust memory_limit.
        // The hub fetch above has no cap, because the hub is trusted.
        clearstatcache(true, $this->paths->catalogueJson());
        $heldBytes = @filesize($this->paths->catalogueJson());
        if ($heldBytes === false) {
            return null;
        }
        $jsonCap = 2 * $heldBytes;
        $sigCap = self::signatureTextBytes();

        foreach ($this->store->held()['mirrors'] ?? [] as $peer) {
            if ($peer['id'] === $this->config->mirrorId) {
                continue;
            }
            $source = 'peer:' . $peer['id'];
            $base = $peer['base_url'];
            if ($this->tryFetch($source, "$base/catalogue.json", "$base/catalogue.sig", $jsonCap, $sigCap) === true) {
                return $source;
            }
        }
        return null;
    }

    /** The length of a base64url Ed25519 signature, which is all a catalogue.sig may hold. */
    private static function signatureTextBytes(): int
    {
        return strlen(B64::encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES)));
    }

    /**
     * Returns the hashes and the lowercase filenames on the held catalogue's removal list, as sets.
     *
     * @return array{array<string, true>, array<string, true>}
     */
    private static function removedSets(?array $held): array
    {
        $hashes = [];
        $names = [];
        foreach ($held['removed'] ?? [] as $entry) {
            $hashes[$entry['sha256']] = true;
            $names[mb_strtolower($entry['filename'])] = true;
        }
        return [$hashes, $names];
    }

    /** @return list<string> deleted paths relative to the root */
    public function applyRemovals(): array
    {
        [$hashes, $names] = self::removedSets($this->store->held());
        if ($hashes === []) {
            return [];
        }

        $deleted = [];
        $this->withDiskLock(function () use ($hashes, $names, &$deleted): void {
            $listing = Listing::build($this->paths, Listing::load($this->paths), ($this->now)());
            foreach (Listing::entries($listing) as $file) {
                if (!isset($hashes[$file['sha256']]) && !isset($names[mb_strtolower($file['filename'])])) {
                    continue;
                }
                $relative = Paths::folderFor($file['kind']) . '/' . $file['filename'];
                if (@unlink($this->paths->root() . '/' . $relative)) {
                    $deleted[] = $relative;
                    $this->log("removed $relative");
                } else {
                    $this->log("could not remove $relative");
                }
            }
            // Write the listing now, so listing.json and upload reports stop naming deleted files straight away.
            if ($deleted !== []) {
                Listing::write($this->paths, Listing::build($this->paths, $listing, ($this->now)()));
            }
        });

        foreach (array_keys($hashes) as $hash) {
            $partial = $this->paths->partial() . '/sync-' . $hash;
            if (is_file($partial) && @unlink($partial)) {
                $this->log('removed .partial/sync-' . $hash);
            }
        }
        return $deleted;
    }

    /** Downloads each archive the mirror lacks from a peer that lists it, and keeps only bytes that match the catalogue hash. */
    public function fetchMissing(): void
    {
        $held = $this->store->held();
        if ($held === null) {
            return;
        }
        foreach (Paths::OWN_LIST_KINDS as $kind) {
            foreach (Catalogue::ownList($held, $kind)[1] as $line) {
                $this->log($line);
            }
        }
        $wanted = Catalogue::wanted($held);
        $own = Listing::load($this->paths) ?? Listing::build($this->paths, null, ($this->now)());
        [$removedHashes, $removedNames] = self::removedSets($held);

        $missing = [];
        foreach ($wanted as $archive) {
            if (
                $this->isOnDisk($own, $archive)
                || isset($removedHashes[$archive['sha256']])
                || isset($removedNames[mb_strtolower($archive['filename'])])
            ) {
                continue;
            }
            $clash = $this->nameTakenByOtherHash($own, $archive);
            if ($clash !== null) {
                $this->log("conflict: {$clash['filename']} is already here with different bytes, not downloaded");
                continue;
            }
            $missing[] = $archive;
        }
        if ($missing === []) {
            return;
        }

        $listings = $this->peerListings($held['mirrors'], count($wanted));
        $peers = [];
        foreach (($this->orderPeers)(array_keys($listings)) as $baseUrl) {
            $peers[$baseUrl] = $listings[$baseUrl];
        }
        // Peers that served bytes with the wrong hash. They are not asked again until the next run.
        $badPeers = [];
        foreach ($missing as $archive) {
            if ($this->deadline->expired()) {
                $this->log('fetch stopped: out of time');
                return;
            }
            if (!$this->fetchOne($archive, $peers, $badPeers)) {
                return;
            }
        }
    }

    /** @return array<string, array> each usable peer's listing, keyed by base URL */
    private function peerListings(array $mirrors, int $archiveCount): array
    {
        // Policy: a peer listing may hold as many unknown files as the catalogue lists files, so the factor is 2.
        $cap = Listing::maxDocBytes(2 * $archiveCount + 1);
        $listings = [];
        foreach ($mirrors as $peer) {
            if ($peer['id'] === $this->config->mirrorId) {
                continue;
            }
            $response = $this->peerHttp->get($peer['base_url'] . '/listing.json', $cap);
            $doc = $response->status === 200 ? Json::decodeObject($response->body) : null;
            if ($doc === null || !Listing::isValidDoc($doc)) {
                $this->log('listing from peer:' . $peer['id'] . ' skipped, missing, malformed or over ' . $cap . ' bytes');
                continue;
            }
            $listings[$peer['base_url']] = $doc;
        }
        return $listings;
    }

    private function isOnDisk(array $listing, array $archive): bool
    {
        $name = mb_strtolower($archive['filename']);
        foreach (Listing::entries($listing) as $file) {
            if ($file['kind'] === $archive['kind'] && $file['sha256'] === $archive['sha256'] && mb_strtolower($file['filename']) === $name) {
                return true;
            }
        }
        return false;
    }

    /** Returns the listed file with the same kind and name in any letter case, when its hash differs. */
    private function nameTakenByOtherHash(array $listing, array $archive): ?array
    {
        $name = mb_strtolower($archive['filename']);
        foreach (Listing::entries($listing) as $file) {
            if ($file['kind'] === $archive['kind'] && mb_strtolower($file['filename']) === $name && $file['sha256'] !== $archive['sha256']) {
                return $file;
            }
        }
        return null;
    }

    /**
     * @param array<string, true> $badPeers base URLs that served a hash mismatch this run, added to here
     * @return bool false when the whole fetch should stop
     */
    private function fetchOne(array $archive, array $peers, array &$badPeers): bool
    {
        $folder = Paths::folderFor($archive['kind']);
        $partial = $this->paths->partial() . '/sync-' . $archive['sha256'];

        foreach ($peers as $baseUrl => $listing) {
            if (isset($badPeers[$baseUrl])) {
                continue;
            }
            $entry = Listing::find($listing, $archive['kind'], $archive['filename']);
            if ($entry === null || $entry['sha256'] !== $archive['sha256'] || $entry['size'] !== $archive['size']) {
                continue;
            }

            $url = "$baseUrl/$folder/" . rawurlencode($archive['filename']);
            $result = $this->peerHttp->download($url, $partial, $archive['size'], $this->deadline);
            if ($result === DownloadResult::Stopped) {
                $this->log("download of $folder/{$archive['filename']} stopped: out of time");
                return false;
            }
            if ($result === DownloadResult::Failed) {
                @unlink($partial);
                $this->log("download of $folder/{$archive['filename']} from $baseUrl failed");
                continue;
            }

            $actual = $this->deadline->step(static fn (): string|false => @hash_file('sha256', $partial));
            if (!is_string($actual) || !hash_equals($archive['sha256'], $actual)) {
                @unlink($partial);
                $badPeers[$baseUrl] = true;
                $this->log("hash mismatch for $folder/{$archive['filename']} from $baseUrl, not asking it again this run");
                continue;
            }

            $this->place($archive, $folder, $partial);
            return true;
        }
        return true;
    }

    /** Moves a verified partial into the kind folder unless the name is already taken. */
    private function place(array $archive, string $folder, string $partial): void
    {
        $this->withDiskLock(function () use ($archive, $folder, $partial): void {
            $dir = $this->paths->dirFor($archive['kind']);
            $names = is_dir($dir) ? scandir($dir) : false;
            if ($names === false) {
                $this->log("cannot read $folder, kept the download in .partial");
                return;
            }

            $lower = mb_strtolower($archive['filename']);
            foreach ($names as $name) {
                if (mb_strtolower($name) !== $lower) {
                    continue;
                }
                $existing = @hash_file('sha256', "$dir/$name");
                @unlink($partial);
                if (is_string($existing) && hash_equals($archive['sha256'], $existing)) {
                    $this->log("$folder/$name is already present");
                } else {
                    $this->log("conflict: $folder/$name exists with different bytes, left alone");
                }
                return;
            }

            if (!rename($partial, "$dir/{$archive['filename']}")) {
                @unlink($partial);
                $this->log("could not move $folder/{$archive['filename']} into place");
                return;
            }
            Listing::addFile($this->paths, $archive['kind'], $archive['filename'], ($this->now)());
            $this->log("fetched $folder/{$archive['filename']}");
        });
    }

    /** @return list<string> deleted file names */
    public function cleanPartials(): array
    {
        $dir = $this->paths->partial();
        $names = is_dir($dir) ? scandir($dir) : false;
        if ($names === false) {
            return [];
        }
        $cutoff = ($this->now)() - $this->config->partialLifetime;
        $deleted = [];
        foreach ($names as $name) {
            $path = "$dir/$name";
            if ($name === '.htaccess' || !is_file($path)) {
                continue;
            }
            clearstatcache(true, $path);
            $mtime = filemtime($path);
            if ($mtime !== false && $mtime < $cutoff && $this->deletePartial($name, $path)) {
                $deleted[] = $name;
                $this->log("removed .partial/$name");
            }
        }
        return $deleted;
    }

    /**
     * Deletes one partial. An upload partial is deleted only while holding its lock, so a slow upload that
     * still holds it is left alone. Sync partials need no lock, because only the run holding sync.lock uses them.
     */
    private function deletePartial(string $name, string $path): bool
    {
        if (!str_starts_with($name, 'upload-')) {
            return @unlink($path);
        }
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return false;
        }
        try {
            // The upload handler checks the file is still on disk after it takes the lock, so deleting while
            // holding the lock is safe for a request that is waiting on it.
            return flock($handle, LOCK_EX | LOCK_NB) && @unlink($path);
        } finally {
            fclose($handle);
        }
    }

    public function report(): bool
    {
        try {
            $now = ($this->now)();
            $listing = Listing::load($this->paths) ?? Listing::build($this->paths, null, $now);
            $payload = Report::payload($this->config, $this->store->held(), $listing, 'sync', null, $now);
            $response = Report::post($this->http, $this->config, Report::body($payload, $this->config->secretKey));
        } catch (\Throwable $e) {
            $this->log('sync report failed: ' . $e->getMessage());
            return false;
        }
        if (Report::unknownMirror($response)) {
            $this->log('sync report failed: not approved by the hub yet');
            return false;
        }
        $ok = Report::accepted($response);
        if (!$ok) {
            $this->log('sync report failed: the hub did not answer with a 2xx status');
        }
        return $ok;
    }

    /**
     * Fetches from the hub when no caps are given, and from a peer, through the peer client, when they are.
     *
     * @return true|string|false true when accepted, the rejection reason when rejected, false when the fetch failed
     */
    private function tryFetch(string $source, string $jsonUrl, string $sigUrl, ?int $jsonCap = null, ?int $sigCap = null): bool|string
    {
        $client = $jsonCap === null ? $this->http : $this->peerHttp;
        $json = $client->get($jsonUrl, $jsonCap);
        if ($json->status !== 200) {
            $this->log("catalogue from $source failed: status {$json->status}" . self::capNote($json, $jsonCap));
            return false;
        }
        $sig = $client->get($sigUrl, $sigCap);
        if ($sig->status !== 200) {
            $this->log("catalogue from $source failed: signature status {$sig->status}" . self::capNote($sig, $sigCap));
            return false;
        }
        try {
            $this->store->accept($json->body, $sig->body);
        } catch (CatalogueRejected $e) {
            $this->log("catalogue from $source rejected: {$e->reason}");
            return $e->reason;
        }
        $this->log("catalogue accepted from $source");
        return true;
    }

    /** Status 0 from a capped fetch can mean the body was too long, so say so. */
    private static function capNote(HttpResponse $response, ?int $cap): string
    {
        return $response->status === 0 && $cap !== null ? ", no answer or over $cap bytes" : '';
    }

    private function rebuildListing(): void
    {
        $this->withDiskLock(function (): void {
            Listing::write($this->paths, Listing::build($this->paths, Listing::load($this->paths), ($this->now)()));
        });
    }

    private function withDiskLock(Closure $fn): void
    {
        $lock = Lock::acquire($this->paths->state() . '/disk.lock', true);
        if ($lock === null) {
            throw new \RuntimeException('Could not take the disk lock');
        }
        try {
            $fn();
        } finally {
            $lock->release();
        }
    }

    /** Returns false when the phase threw. */
    private function phase(string $name, Closure $fn): bool
    {
        try {
            $fn();
            return true;
        } catch (\Throwable $e) {
            $this->log("$name failed: " . $e->getMessage());
            return false;
        }
    }

    private function log(string $line): void
    {
        ($this->log)($line);
    }
}
