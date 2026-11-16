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

    public function fetchMissing(): void
    {
        // Task 11 fills this in.
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
