<?php

declare(strict_types=1);

namespace Mirror;

/** Checks the hosting setup. Each method returns a list of problems, empty when all is well. */
final class Requirements
{
    private const REQUIRED_EXTENSIONS = ['sodium', 'hash', 'json', 'openssl', 'mbstring'];

    /**
     * Paths that must not be served. A 403 or a 404 are both fine.
     * private/canary.txt is a real file, so it shows the private folder's files are blocked.
     * A directory URL like .partial/ only shows that the folder is not listed.
     * fixtures/ and examples/ only exist when the mirror is a git clone, and are then readable unless blocked.
     */
    private const HIDDEN = ['private/config.php', 'private/canary.txt', '.partial/', 'src/Config.php', 'bin/keygen.php', 'composer.json', '.git/HEAD', 'fixtures/keys.json', 'examples/nginx.conf'];

    /**
     * Checks the PHP version, extensions, ini settings, config and writable folders.
     *
     * @param array<string, string> $ini allow_url_fopen, file_uploads, upload_max_filesize and post_max_size
     * @param list<string> $extensions the names of the loaded extensions
     * @return list<string>
     */
    public static function local(Paths $p, array $ini, array $extensions): array
    {
        $problems = [];

        if (PHP_VERSION_ID < 80400) {
            $problems[] = 'PHP ' . PHP_VERSION . ' is too old. The mirror needs PHP 8.4 or newer.';
        }

        $loaded = array_map('strtolower', $extensions);
        foreach (self::REQUIRED_EXTENSIONS as $name) {
            if (!in_array($name, $loaded, true)) {
                $problems[] = "The $name PHP extension is not loaded.";
            }
        }

        if (!self::on($ini['allow_url_fopen'] ?? '')) {
            $problems[] = 'allow_url_fopen is off. The mirror needs it to download from the hub and from other mirrors.';
        }
        if (!self::on($ini['file_uploads'] ?? '')) {
            $problems[] = 'file_uploads is off. The mirror cannot receive upload chunks.';
        }
        if (Limits::maxChunkBytes($ini['upload_max_filesize'] ?? '', $ini['post_max_size'] ?? '', self::on($ini['file_uploads'] ?? '')) === null) {
            $problems[] = 'No upload chunk fits. Check file_uploads, upload_max_filesize and post_max_size.';
        }

        try {
            Config::load($p);
        } catch (\Throwable $e) {
            $problems[] = $e->getMessage();
        }

        // private/state/ is made by the first request, so on a fresh install it is enough that private/ is writable.
        $state = is_dir($p->state()) || !is_dir($p->private()) ? $p->state() : $p->private();
        $folders = [];
        foreach (Paths::KINDS as $kind) {
            $folders[Paths::folderFor($kind) . '/'] = $p->dirFor($kind);
        }
        $folders += ['.partial/' => $p->partial(), 'private/state/' => $state];
        foreach ($folders as $name => $dir) {
            if (!is_dir($dir) || !is_writable($dir)) {
                $problems[] = "$name is missing or not writable by the PHP user.";
            }
        }

        return $problems;
    }

    /**
     * Requests the mirror over HTTP to check what the web server exposes and runs.
     *
     * @param string|null $probeFile a path like maps/name.sd7 that must be served as a download
     * @param int|null $webChunkBytes set to max_chunk_bytes as the web PHP reports it, which can differ from the CLI value
     * @return list<string>
     */
    public static function remote(HttpClient $h, string $baseUrl, ?string $probeFile = null, ?int &$webChunkBytes = null): array
    {
        $base = rtrim($baseUrl, '/');
        $webChunkBytes = null;
        $problems = [];

        foreach (self::HIDDEN as $path) {
            $status = $h->get("$base/$path")->status;
            if ($status !== 403 && $status !== 404) {
                $problems[] = "$path answered " . self::describe($status) . ". It must answer 403 or 404.";
            }
        }

        $maps = $h->get("$base/maps/");
        if ($maps->status !== 200 || preg_match('/<html|<!doctype/i', $maps->body) !== 1) {
            $problems[] = 'maps/ answered ' . self::describe($maps->status) . ' without an HTML page. It must answer 200 with a page that lists the archives.'
                . ($maps->status === 500 ? ' The likeliest cause is a host that does not let .htaccess files set Indexes options. Delete the Listing style lines in the .htaccess files of maps/, games/, engines/, replays/ and tracks/ to fall back to a plain listing.' : '');
        }

        // The hub makes the same request when the mirror reports that it takes engines.
        $engines = $h->get("$base/engines/")->status;
        if ($engines !== 200) {
            $problems[] = 'engines/ answered ' . self::describe($engines) . '. It must answer 200, or the hub marks the mirror unhealthy. Check that engines/.htaccess is on the server.';
        }

        foreach (['replays', 'tracks'] as $folder) {
            $status = $h->get("$base/$folder/")->status;
            if ($status !== 200) {
                $problems[] = "$folder/ answered " . self::describe($status) . ". It must answer 200, so players and the hub can reach the files. Check that $folder/.htaccess is on the server.";
            }
        }

        $sync = $h->get("$base/sync.php")->status;
        if ($sync !== 403) {
            $problems[] = 'sync.php answered ' . self::describe($sync) . '. It must answer 403 to web requests.';
        }

        $limits = $h->get("$base/limits.php");
        $max = $limits->status === 200 ? (Json::decodeObject($limits->body)['max_chunk_bytes'] ?? null) : null;
        if (is_int($max) && $max > 0) {
            $webChunkBytes = $max;
        } else {
            $problems[] = 'limits.php answered ' . self::describe($limits->status) . ($limits->status === 200 ? self::reason($limits->body) : '') . '. It must answer 200 with a positive max_chunk_bytes.';
        }

        $upload = $h->get("$base/upload.php");
        $error = Json::decodeObject($upload->body)['error'] ?? null;
        if ($upload->status !== 400 || $error !== 'bad_request') {
            $problems[] = 'upload.php answered ' . self::describe($upload->status) . self::reason($upload->body) . '. Without a token it must answer 400 with the error bad_request, which shows PHP runs.';
        }

        // 404 means the mirror has no base_url yet, which bin/check.php's local checks do not count as a fault.
        $identity = $h->get("$base/identity.php");
        $identityBody = Json::decodeObject($identity->body);
        if (!($identity->status === 200 && is_string($identityBody['mirror_id'] ?? null))
            && !($identity->status === 404 && ($identityBody['error'] ?? null) === 'not_set_up')) {
            $problems[] = 'identity.php answered ' . self::describe($identity->status) . self::reason($identity->body) . '. It must answer 200 with the mirror ID, or 404 before setup.';
        }

        if ($probeFile !== null) {
            $file = $h->get("$base/" . ltrim($probeFile, '/'));
            $type = strtolower(trim(explode(';', $file->headers['content-type'] ?? '')[0]));
            if ($file->status !== 200 || $type !== 'application/octet-stream') {
                $problems[] = "$probeFile answered " . self::describe($file->status) . ($file->status === 200 ? " with Content-Type " . ($type === '' ? 'none' : $type) : '') . '. It must answer 200 with Content-Type application/octet-stream.';
            }
        }

        return $problems;
    }

    /**
     * Fetches the hub's published key and names the problem when this mirror does not trust it,
     * or null when it does.
     *
     * @param list<string> $trusted raw public keys, from the config and those learned from catalogues
     */
    public static function hubKeyProblem(HttpClient $h, string $hubUrl, array $trusted): ?string
    {
        $answer = $h->get($hubUrl . '/api/v1/mirrors/hub-key');
        $published = $answer->status === 200 ? (Json::decodeObject($answer->body)['public_key'] ?? null) : null;
        $raw = is_string($published) ? B64::decode($published) : null;
        if ($raw === null || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return "The hub's published key could not be read from $hubUrl/api/v1/mirrors/hub-key (answered " . self::describe($answer->status) . '). Check hub_url.';
        }
        if (!in_array($raw, $trusted, true)) {
            return "The hub's published key $published is not in hub_keys, so this mirror will refuse the hub's catalogue. Check hub_url, then add the key to hub_keys if the hub operator confirms it.";
        }
        return null;
    }

    private static function on(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function describe(int $status): string
    {
        return $status === 0 ? 'nothing (no response)' : (string) $status;
    }

    /** Names the likeliest cause when a body shows the server sent PHP source instead of running it. */
    private static function reason(string $body): string
    {
        return str_contains($body, '<?php') ? ' and sent the PHP source, so PHP is not running it' : '';
    }
}
