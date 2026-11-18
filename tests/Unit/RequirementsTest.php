<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\B64;
use Mirror\Crypto;
use Mirror\HttpResponse;
use Mirror\Json;
use Mirror\Paths;
use Mirror\Requirements;
use Mirror\Tests\Support\FakeHttpClient;
use Mirror\Tests\Support\Signer;
use Mirror\Tests\Support\TempMirror;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequirementsTest extends TestCase
{
    private const BASE = 'https://mirror.example';

    private Paths $paths;

    protected function setUp(): void
    {
        $this->paths = TempMirror::create();
        $this->writeConfig();
    }

    protected function tearDown(): void
    {
        // A test may have made maps/ read only, and removing its contents needs it writable again.
        @chmod($this->paths->maps(), 0777);
        TempMirror::destroy($this->paths);
    }

    private function writeConfig(): void
    {
        $mirror = Signer::keypair(8);
        $hub = Signer::keypair(1);
        $config = [
            'mirror_id' => 'm1',
            'secret_key' => B64::encode($mirror['secret']),
            'hub_url' => 'https://hub.example',
            'hub_keys' => [B64::encode($hub['public'])],
            'partial_lifetime' => 3600,
        ];
        file_put_contents($this->paths->config(), '<?php return ' . var_export($config, true) . ';');
    }

    /** @return array<string, string> */
    private function ini(): array
    {
        return ['allow_url_fopen' => '1', 'file_uploads' => '1', 'upload_max_filesize' => '2M', 'post_max_size' => '8M'];
    }

    /** @return list<string> */
    private function extensions(): array
    {
        return ['sodium', 'hash', 'json', 'openssl', 'mbstring', 'Core'];
    }

    private function cleanRemote(): FakeHttpClient
    {
        $http = new FakeHttpClient();
        foreach (['private/config.php', 'private/canary.txt', '.partial/', 'src/Config.php', 'bin/keygen.php', 'composer.json', '.git/HEAD', 'fixtures/keys.json', 'examples/nginx.conf'] as $i => $path) {
            $http->on(self::BASE . '/' . $path, new HttpResponse($i % 2 === 0 ? 403 : 404, ''));
        }
        $http->on(self::BASE . '/maps/', new HttpResponse(200, '<!DOCTYPE HTML><html><body>Index of /maps</body></html>'));
        $http->on(self::BASE . '/sync.php', new HttpResponse(403, '{"error":"forbidden"}'));
        $http->on(self::BASE . '/limits.php', new HttpResponse(200, '{"v":1,"max_chunk_bytes":1048576}'));
        $http->on(self::BASE . '/upload.php', new HttpResponse(400, '{"error":"bad_request"}'));
        return $http;
    }

    public function test_clean_environment_has_no_problems(): void
    {
        $this->assertSame([], Requirements::local($this->paths, $this->ini(), $this->extensions()));
    }

    /** @return array<string, array{array<string, string>, list<string>, string}> */
    public static function missingRequirements(): array
    {
        $ini = ['allow_url_fopen' => '1', 'file_uploads' => '1', 'upload_max_filesize' => '2M', 'post_max_size' => '8M'];
        $all = ['sodium', 'hash', 'json', 'openssl', 'mbstring'];
        return [
            'sodium' => [$ini, array_values(array_diff($all, ['sodium'])), 'sodium'],
            'mbstring' => [$ini, array_values(array_diff($all, ['mbstring'])), 'mbstring'],
            'allow_url_fopen' => [['allow_url_fopen' => '0'] + $ini, $all, 'allow_url_fopen'],
            'file_uploads' => [['file_uploads' => '0'] + $ini, $all, 'file_uploads'],
            'no room for a chunk' => [['post_max_size' => '100'] + $ini, $all, 'chunk'],
        ];
    }

    /**
     * @param array<string, string> $ini
     * @param list<string> $extensions
     */
    #[DataProvider('missingRequirements')]
    public function test_each_missing_requirement_is_named(array $ini, array $extensions, string $needle): void
    {
        $problems = Requirements::local($this->paths, $ini, $extensions);
        $this->assertNotSame([], $problems);
        $this->assertStringContainsString($needle, implode("\n", $problems));
    }

    public function test_unwritable_maps_folder_is_named(): void
    {
        chmod($this->paths->maps(), 0555);
        if (is_writable($this->paths->maps())) {
            $this->markTestSkipped('This user can write to read only folders.');
        }
        $problems = Requirements::local($this->paths, $this->ini(), $this->extensions());
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('maps/', $problems[0]);
    }

    public function test_missing_folder_is_named(): void
    {
        rmdir($this->paths->partial());
        $problems = Requirements::local($this->paths, $this->ini(), $this->extensions());
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('.partial/', $problems[0]);
    }

    public function test_fresh_install_without_state_folder_is_fine_when_private_is_writable(): void
    {
        rmdir($this->paths->state() . '/used');
        rmdir($this->paths->state());
        $this->assertSame([], Requirements::local($this->paths, $this->ini(), $this->extensions()));
    }

    public function test_missing_state_folder_is_named_when_private_is_not_writable(): void
    {
        rmdir($this->paths->state() . '/used');
        rmdir($this->paths->state());
        chmod($this->paths->private(), 0555);
        try {
            if (is_writable($this->paths->private())) {
                $this->markTestSkipped('This user can write to read only folders.');
            }
            $problems = Requirements::local($this->paths, $this->ini(), $this->extensions());
            $this->assertCount(1, $problems);
            $this->assertStringContainsString('private/state/', $problems[0]);
        } finally {
            chmod($this->paths->private(), 0777);
        }
    }

    public function test_bad_config_is_named(): void
    {
        file_put_contents($this->paths->config(), '<?php return [];');
        $problems = Requirements::local($this->paths, $this->ini(), $this->extensions());
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('mirror_id', $problems[0]);
    }

    public function test_missing_config_is_named(): void
    {
        unlink($this->paths->config());
        $problems = Requirements::local($this->paths, $this->ini(), $this->extensions());
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('private/config.php', $problems[0]);
    }

    public function test_clean_remote_has_no_problems_and_reports_the_web_chunk_size(): void
    {
        $chunk = null;
        $this->assertSame([], Requirements::remote($this->cleanRemote(), self::BASE . '/', null, $chunk));
        $this->assertSame(1048576, $chunk);
    }

    public function test_remote_flags_exposed_private_config(): void
    {
        $http = $this->cleanRemote();
        $http->on(self::BASE . '/private/config.php', new HttpResponse(200, "<?php return [];"));
        $problems = Requirements::remote($http, self::BASE);
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('private/config.php', $problems[0]);
    }

    public function test_remote_flags_repo_only_folders_left_readable_by_a_git_clone(): void
    {
        foreach (['fixtures/keys.json', 'examples/nginx.conf'] as $path) {
            $http = $this->cleanRemote();
            $http->on(self::BASE . '/' . $path, new HttpResponse(200, '{}'));
            $this->assertStringContainsString($path, implode("\n", Requirements::remote($http, self::BASE)));
        }
    }

    public function test_remote_flags_a_downloadable_canary_file(): void
    {
        $http = $this->cleanRemote();
        $http->on(self::BASE . '/private/canary.txt', new HttpResponse(200, 'This file must never be downloadable.'));
        $problems = Requirements::remote($http, self::BASE);
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('private/canary.txt', $problems[0]);
    }

    public function test_remote_does_not_request_the_private_state_folder(): void
    {
        $http = $this->cleanRemote();
        $http->on(self::BASE . '/private/state/', new HttpResponse(200, 'listing'));
        $this->assertSame([], Requirements::remote($http, self::BASE));
    }

    public function test_remote_flags_an_unreachable_hidden_path(): void
    {
        $http = $this->cleanRemote();
        $http->on(self::BASE . '/.git/HEAD', new HttpResponse(0, ''));
        $problems = Requirements::remote($http, self::BASE);
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('.git/HEAD', $problems[0]);
    }

    public function test_remote_flags_maps_that_is_not_html(): void
    {
        $http = $this->cleanRemote();
        $http->on(self::BASE . '/maps/', new HttpResponse(403, ''));
        $this->assertStringContainsString('maps/', implode("\n", Requirements::remote($http, self::BASE)));
    }

    public function test_remote_explains_a_500_on_maps_as_the_listing_style(): void
    {
        $http = $this->cleanRemote();
        $http->on(self::BASE . '/maps/', new HttpResponse(500, ''));
        $problems = implode("\n", Requirements::remote($http, self::BASE));
        $this->assertStringContainsString('maps/ answered 500', $problems);
        $this->assertStringContainsString('Listing style', $problems);
    }

    public function test_remote_flags_sync_php_that_is_not_forbidden(): void
    {
        $http = $this->cleanRemote();
        $http->on(self::BASE . '/sync.php', new HttpResponse(200, '{}'));
        $this->assertStringContainsString('sync.php', implode("\n", Requirements::remote($http, self::BASE)));
    }

    /** @return array<string, array{HttpResponse}> */
    public static function badLimits(): array
    {
        return [
            'php source' => [new HttpResponse(200, '<?php echo 1;')],
            'not found' => [new HttpResponse(404, '')],
            'uploads disabled' => [new HttpResponse(503, '{"error":"uploads_disabled"}')],
            'zero chunk' => [new HttpResponse(200, '{"v":1,"max_chunk_bytes":0}')],
            'text chunk' => [new HttpResponse(200, '{"v":1,"max_chunk_bytes":"big"}')],
        ];
    }

    #[DataProvider('badLimits')]
    public function test_remote_flags_bad_limits(HttpResponse $answer): void
    {
        $http = $this->cleanRemote();
        $http->on(self::BASE . '/limits.php', $answer);
        $chunk = null;
        $problems = Requirements::remote($http, self::BASE, null, $chunk);
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('limits.php', $problems[0]);
        $this->assertNull($chunk);
    }

    /** @return array<string, array{HttpResponse}> */
    public static function badUpload(): array
    {
        return [
            'forbidden' => [new HttpResponse(403, '')],
            'not found' => [new HttpResponse(404, '')],
            'php source' => [new HttpResponse(200, '<?php echo 1;')],
            'wrong error' => [new HttpResponse(400, '{"error":"bad_token"}')],
            'html' => [new HttpResponse(400, '<html></html>')],
        ];
    }

    #[DataProvider('badUpload')]
    public function test_remote_flags_an_upload_entry_point_that_does_not_run_php(HttpResponse $answer): void
    {
        $http = $this->cleanRemote();
        $http->on(self::BASE . '/upload.php', $answer);
        $problems = Requirements::remote($http, self::BASE);
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('upload.php', $problems[0]);
    }

    public function test_probe_file_needs_an_octet_stream_answer(): void
    {
        $http = $this->cleanRemote();
        $http->on(self::BASE . '/maps/a.sd7', new HttpResponse(200, 'x', ['content-type' => 'application/octet-stream']));
        $this->assertSame([], Requirements::remote($http, self::BASE, 'maps/a.sd7'));

        $http->on(self::BASE . '/maps/a.sd7', new HttpResponse(200, 'x', ['content-type' => 'text/html; charset=UTF-8']));
        $problems = Requirements::remote($http, self::BASE, 'maps/a.sd7');
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('maps/a.sd7', $problems[0]);

        $http->on(self::BASE . '/maps/a.sd7', new HttpResponse(404, ''));
        $this->assertCount(1, Requirements::remote($http, self::BASE, 'maps/a.sd7'));
    }

    /** @return array{int, string, string} exit status, output, error output */
    private function check(string ...$args): array
    {
        $cmd = array_merge([PHP_BINARY, dirname(__DIR__, 2) . '/bin/check.php', '--root=' . $this->paths->root()], $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($proc), $out, $err];
    }

    public function test_check_prints_held_catalogue_seq_and_date(): void
    {
        $hub = Signer::keypair(1);
        $catalogue = CatalogueTest::doc(7);
        $catalogue['generated_at'] = 1795000000;
        $json = Json::encode($catalogue);
        file_put_contents($this->paths->catalogueJson(), $json);
        file_put_contents($this->paths->catalogueSig(), Signer::sign(Crypto::CTX_CATALOGUE, $json, $hub['secret']));

        [$status, $out] = $this->check();

        $this->assertStringContainsString('seq 7', $out);
        $this->assertStringContainsString('2026-11-18T11:06:40Z', $out);
        $this->assertStringContainsString('ok', $out);
        $this->assertSame(0, $status, $out);
    }

    public function test_check_says_when_no_catalogue_is_held(): void
    {
        [$status, $out] = $this->check();
        $this->assertStringContainsString('no catalogue held', $out);
        $this->assertSame(0, $status);
    }

    public function test_check_prints_problems_and_exits_1(): void
    {
        unlink($this->paths->config());
        [$status, $out] = $this->check();
        $this->assertSame(1, $status);
        $this->assertStringContainsString('private/config.php', $out);
        $this->assertStringNotContainsString('ok', $out);
    }
}
