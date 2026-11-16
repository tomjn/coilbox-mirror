<?php

declare(strict_types=1);

namespace Mirror;

use Closure;

/** Brings the mirror in line with the hub: catalogue, removals, files, partial cleanup and the report. */
final class Sync
{
    /**
     * @param Closure(): int $now returns the current Unix time
     * @param Closure(string): void $log receives one line per event
     */
    public function __construct(
        private readonly Paths $paths,
        private readonly Config $config,
        private readonly CatalogueStore $store,
        private readonly HttpClient $http,
        private readonly Deadline $deadline,
        private readonly Closure $now,
        private readonly Closure $log,
    ) {
    }

    /** Returns the exit code. */
    public function run(): int
    {
        $this->paths->ensureState();
        $lock = Lock::acquire($this->paths->state() . '/sync.lock', false);
        if ($lock === null) {
            $this->log('another sync is running');
            return 0;
        }

        try {
            $this->phase('catalogue refresh', fn () => $this->refreshCatalogue());
            $this->phase('listing rebuild', fn () => $this->rebuildListing());
            $this->phase('removals', fn () => $this->applyRemovals());
            $this->phase('fetch', fn () => $this->fetchMissing());
            $this->phase('partial cleanup', fn () => $this->cleanPartials());
            $this->phase('listing rebuild', fn () => $this->rebuildListing());
            $reported = $this->report();

            if (!$reported) {
                return 1;
            }
            return $this->store->held() === null ? 1 : 0;
        } finally {
            $lock->release();
        }
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

        foreach ($this->store->held()['mirrors'] ?? [] as $peer) {
            if ($peer['id'] === $this->config->mirrorId) {
                continue;
            }
            $source = 'peer:' . $peer['id'];
            if ($this->tryFetch($source, $peer['base_url'] . '/catalogue.json', $peer['base_url'] . '/catalogue.sig') === true) {
                return $source;
            }
        }
        return null;
    }

    /** @return list<string> deleted paths relative to the root */
    public function applyRemovals(): array
    {
        $removed = $this->store->held()['removed'] ?? [];
        if ($removed === []) {
            return [];
        }
        $hashes = [];
        $names = [];
        foreach ($removed as $entry) {
            $hashes[$entry['sha256']] = true;
            $names[mb_strtolower($entry['filename'])] = true;
        }

        $deleted = [];
        $this->withDiskLock(function () use ($hashes, $names, &$deleted): void {
            $listing = Listing::build($this->paths, Listing::load($this->paths), ($this->now)());
            foreach ($listing['files'] as $file) {
                if (!isset($hashes[$file['sha256']]) && !isset($names[mb_strtolower($file['filename'])])) {
                    continue;
                }
                $relative = ($file['kind'] === 'map' ? 'maps/' : 'games/') . $file['filename'];
                if (@unlink($this->paths->root() . '/' . $relative)) {
                    $deleted[] = $relative;
                    $this->log("removed $relative");
                } else {
                    $this->log("could not remove $relative");
                }
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
        $own = Listing::load($this->paths) ?? Listing::build($this->paths, null, ($this->now)());

        $missing = [];
        foreach ($held['archives'] as $archive) {
            if ($this->isOnDisk($own, $archive) || $this->store->isRemoved($archive['sha256'], $archive['filename'])) {
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

        $peers = $this->peerListings($held['mirrors']);
        foreach ($missing as $archive) {
            if ($this->deadline->expired()) {
                $this->log('fetch stopped: out of time');
                return;
            }
            if (!$this->fetchOne($archive, $peers)) {
                return;
            }
        }
    }

    /** @return array<string, array> each usable peer's listing, keyed by base URL */
    private function peerListings(array $mirrors): array
    {
        $listings = [];
        foreach ($mirrors as $peer) {
            if ($peer['id'] === $this->config->mirrorId) {
                continue;
            }
            $response = $this->http->get($peer['base_url'] . '/listing.json');
            $doc = $response->status === 200 ? Json::decodeObject($response->body) : null;
            if ($doc === null || !Listing::isValidDoc($doc)) {
                $this->log('listing from peer:' . $peer['id'] . ' skipped');
                continue;
            }
            $listings[$peer['base_url']] = $doc;
        }
        return $listings;
    }

    private function isOnDisk(array $listing, array $archive): bool
    {
        $name = mb_strtolower($archive['filename']);
        foreach ($listing['files'] as $file) {
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
        foreach ($listing['files'] as $file) {
            if ($file['kind'] === $archive['kind'] && mb_strtolower($file['filename']) === $name && $file['sha256'] !== $archive['sha256']) {
                return $file;
            }
        }
        return null;
    }

    /** @return bool false when the whole fetch should stop */
    private function fetchOne(array $archive, array $peers): bool
    {
        $folder = $archive['kind'] === 'map' ? 'maps' : 'games';
        $partial = $this->paths->partial() . '/sync-' . $archive['sha256'];

        foreach ($peers as $baseUrl => $listing) {
            $entry = Listing::find($listing, $archive['kind'], $archive['filename']);
            if ($entry === null || $entry['sha256'] !== $archive['sha256'] || $entry['size'] !== $archive['size']) {
                continue;
            }

            $url = "$baseUrl/$folder/" . rawurlencode($archive['filename']);
            $result = $this->http->download($url, $partial, $archive['size'], $this->deadline);
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
                $this->log("hash mismatch for $folder/{$archive['filename']} from $baseUrl");
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
            if ($mtime !== false && $mtime < $cutoff && @unlink($path)) {
                $deleted[] = $name;
                $this->log("removed .partial/$name");
            }
        }
        return $deleted;
    }

    public function report(): bool
    {
        try {
            $now = ($this->now)();
            $listing = Listing::load($this->paths) ?? Listing::build($this->paths, null, $now);
            $payload = Report::payload($this->config, $this->store->held(), $listing, 'sync', null, $now);
            $ok = Report::send($this->http, $this->config, Report::body($payload, $this->config->secretKey));
        } catch (\Throwable $e) {
            $this->log('sync report failed: ' . $e->getMessage());
            return false;
        }
        if (!$ok) {
            $this->log('sync report failed: the hub did not answer with a 2xx status');
        }
        return $ok;
    }

    /** @return true|string|false true when accepted, the rejection reason when rejected, false when the fetch failed */
    private function tryFetch(string $source, string $jsonUrl, string $sigUrl): bool|string
    {
        $json = $this->http->get($jsonUrl);
        if ($json->status !== 200) {
            $this->log("catalogue from $source failed: status {$json->status}");
            return false;
        }
        $sig = $this->http->get($sigUrl);
        if ($sig->status !== 200) {
            $this->log("catalogue from $source failed: signature status {$sig->status}");
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

    private function phase(string $name, Closure $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->log("$name failed: " . $e->getMessage());
        }
    }

    private function log(string $line): void
    {
        ($this->log)($line);
    }
}
