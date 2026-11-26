<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\B64;
use Mirror\Catalogue;
use Mirror\CatalogueRejected;
use Mirror\Crypto;
use Mirror\Json;
use Mirror\Tests\Support\Signer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CatalogueTest extends TestCase
{
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @return array<string, mixed> */
    public static function doc(int $seq = 42): array
    {
        $m = Signer::keypair(9);
        return [
            'v' => 1,
            'seq' => $seq,
            'generated_at' => 1795000000,
            'mirrors' => [['id' => 'm1', 'base_url' => 'https://m1.example', 'public_key' => B64::encode($m['public'])]],
            'archives' => [[
                'filename' => 'x.sd7', 'kind' => 'map', 'sha256' => self::SHA, 'size' => 123,
                'internal_name' => 'X', 'version' => '1.0', 'shortname' => null, 'author' => null, 'description' => null,
            ]],
            'removed' => [['sha256' => self::SHA, 'filename' => 'old.sd7', 'reason' => 'withdrawn', 'removed_at' => 1794000000]],
        ];
    }

    /** @return array{string, string} the JSON and the signature text */
    private function signed(array $doc, string $secret): array
    {
        $json = Json::encode($doc);
        return [$json, Signer::sign(Crypto::CTX_CATALOGUE, $json, $secret)];
    }

    private function assertRejected(string $reason, callable $fn): void
    {
        try {
            $fn();
        } catch (CatalogueRejected $e) {
            $this->assertSame($reason, $e->reason);
            return;
        }
        $this->fail("Expected CatalogueRejected $reason");
    }

    public function test_valid_catalogue_is_accepted(): void
    {
        $hub = Signer::keypair(1);
        [$json, $sig] = $this->signed(self::doc(), $hub['secret']);
        $cat = Catalogue::verify($json, $sig, [$hub['public']], 41);
        $this->assertSame(42, $cat['seq']);
        $this->assertSame('x.sd7', $cat['archives'][0]['filename']);
    }

    /** @return array<string, mixed> an engines entry as the hub writes it, without a kind */
    private static function engine(array $override = []): array
    {
        return $override + [
            'filename' => 'recoil_2026.09.03_arm64-macos.7z', 'sha256' => str_repeat('b', 64), 'size' => 456,
            'version' => '2026.09.03', 'platform' => 'arm64-macos', 'note' => 'Tests the Metal path.',
        ];
    }

    public function test_valid_engines_get_the_engine_kind(): void
    {
        [$engines, $dropped] = Catalogue::ownList(self::doc() + ['engines' => [self::engine()]], 'engine');
        $this->assertSame([self::engine() + ['kind' => 'engine']], $engines);
        $this->assertSame([], $dropped);
    }

    public function test_a_bad_engine_entry_is_dropped_and_the_rest_are_kept(): void
    {
        $missingName = self::engine();
        unset($missingName['filename']);
        $doc = self::doc() + ['engines' => [
            self::engine(['filename' => 'x.sd7']),
            self::engine(),
            self::engine(['sha256' => str_repeat('B', 64)]),
            self::engine(['size' => 0]),
            'not an object',
            $missingName,
        ]];

        [$engines, $dropped] = Catalogue::ownList($doc, 'engine');

        $this->assertSame([self::engine() + ['kind' => 'engine']], $engines);
        $this->assertSame([
            'catalogue engine entry 0 dropped, malformed',
            'catalogue engine entry 2 dropped, malformed',
            'catalogue engine entry 3 dropped, malformed',
            'catalogue engine entry 4 dropped, malformed',
            'catalogue engine entry 5 dropped, malformed',
        ], $dropped);
    }

    public function test_an_engine_is_always_the_engine_kind(): void
    {
        foreach (['x', 'game', 'map'] as $kind) {
            [$engines] = Catalogue::ownList(self::doc() + ['engines' => [self::engine(['kind' => $kind])]], 'engine');
            $this->assertSame('engine', $engines[0]['kind']);
        }
    }

    public function test_engines_that_is_not_a_list_drops_them_all(): void
    {
        foreach ([['a' => self::engine()], 'x', 5] as $bad) {
            $this->assertSame([[], ['catalogue engines list dropped, malformed']], Catalogue::ownList(self::doc() + ['engines' => $bad], 'engine'));
        }
    }

    public function test_no_engines_key_gives_no_engines_and_no_lines(): void
    {
        $this->assertSame([[], []], Catalogue::ownList(self::doc(), 'engine'));
    }

    public function test_a_catalogue_with_a_bad_engine_still_verifies(): void
    {
        $hub = Signer::keypair(1);
        [$json, $sig] = $this->signed(self::doc() + ['engines' => [self::engine(['size' => 'big'])]], $hub['secret']);
        $this->assertSame(42, Catalogue::verify($json, $sig, [$hub['public']], 41)['seq']);
    }

    public function test_wanted_lists_archives_then_engines(): void
    {
        $doc = self::doc() + ['engines' => [self::engine(), self::engine(['size' => 0])]];
        $this->assertSame([$doc['archives'][0], self::engine() + ['kind' => 'engine']], Catalogue::wanted($doc));
    }

    /** @return array<string, array{string, string, string}> kind, list key and filename */
    public static function replayKinds(): array
    {
        return [
            'replay' => ['replay', 'replays', '0123456789abcdef0123456789abcdef.sdfz'],
            'track' => ['track', 'tracks', '0123456789abcdef0123456789abcdef.cbtrack'],
        ];
    }

    #[DataProvider('replayKinds')]
    public function test_each_list_sets_its_own_kind_and_drops_bad_entries(string $kind, string $key, string $name): void
    {
        $good = ['filename' => $name, 'sha256' => str_repeat('b', 64), 'size' => 456];
        $doc = self::doc() + [$key => [['filename' => 'x.sd7'] + $good, ['kind' => 'map'] + $good]];

        $this->assertSame(
            [[['kind' => $kind] + $good], ["catalogue $kind entry 0 dropped, malformed"]],
            Catalogue::ownList($doc, $kind),
        );
        $this->assertSame([$doc['archives'][0], ['kind' => $kind] + $good], Catalogue::wanted($doc));
        $this->assertSame([[], ["catalogue $key list dropped, malformed"]], Catalogue::ownList(self::doc() + [$key => 'x'], $kind));
    }

    #[DataProvider('replayKinds')]
    public function test_archives_cannot_hold_a_replay_or_track(string $kind, string $key, string $name): void
    {
        $hub = Signer::keypair(1);
        $doc = self::doc();
        $doc['archives'][0] = ['kind' => $kind, 'filename' => $name] + $doc['archives'][0];
        [$json, $sig] = $this->signed($doc, $hub['secret']);

        $this->assertRejected('malformed', fn () => Catalogue::verify($json, $sig, [$hub['public']], null));
    }

    public function test_one_changed_byte_is_bad_signature(): void
    {
        $hub = Signer::keypair(1);
        [$json, $sig] = $this->signed(self::doc(), $hub['secret']);
        $changed = str_replace('"size":123', '"size":124', $json);
        $this->assertNotSame($json, $changed);
        $this->assertRejected('bad_signature', fn () => Catalogue::verify($changed, $sig, [$hub['public']], null));
    }

    public function test_unknown_signing_key_is_bad_signature(): void
    {
        $hub = Signer::keypair(1);
        $stranger = Signer::keypair(3);
        [$json, $sig] = $this->signed(self::doc(), $stranger['secret']);
        $this->assertRejected('bad_signature', fn () => Catalogue::verify($json, $sig, [$hub['public']], null));
    }

    public function test_signature_text_that_is_not_base64url_is_bad_signature(): void
    {
        $hub = Signer::keypair(1);
        [$json] = $this->signed(self::doc(), $hub['secret']);
        $this->assertRejected('bad_signature', fn () => Catalogue::verify($json, '***', [$hub['public']], null));
    }

    public function test_equal_or_lower_seq_is_not_newer(): void
    {
        $hub = Signer::keypair(1);
        [$json, $sig] = $this->signed(self::doc(42), $hub['secret']);
        $this->assertRejected('not_newer', fn () => Catalogue::verify($json, $sig, [$hub['public']], 42));
        $this->assertRejected('not_newer', fn () => Catalogue::verify($json, $sig, [$hub['public']], 43));
    }

    public function test_first_catalogue_is_accepted_with_no_held_seq(): void
    {
        $hub = Signer::keypair(1);
        [$json, $sig] = $this->signed(self::doc(1), $hub['secret']);
        $this->assertSame(1, Catalogue::verify($json, $sig, [$hub['public']], null)['seq']);
    }

    public function test_catalogue_signed_by_endorsed_new_key_is_accepted(): void
    {
        $k1 = Signer::keypair(1);
        $k2 = Signer::keypair(2);
        $doc = self::doc();
        $doc['next_key'] = [
            'public_key' => B64::encode($k2['public']),
            'sig' => Signer::sign(Crypto::CTX_HUB_KEY, $k2['public'], $k1['secret']),
        ];
        [$json, $sig] = $this->signed($doc, $k2['secret']);
        $cat = Catalogue::verify($json, $sig, [$k1['public']], null);
        $this->assertSame($doc['next_key'], $cat['next_key']);
    }

    public function test_endorsement_by_untrusted_key_is_rejected(): void
    {
        $k1 = Signer::keypair(1);
        $k2 = Signer::keypair(2);
        $stranger = Signer::keypair(3);
        $doc = self::doc();
        $doc['next_key'] = [
            'public_key' => B64::encode($k2['public']),
            'sig' => Signer::sign(Crypto::CTX_HUB_KEY, $k2['public'], $stranger['secret']),
        ];
        [$json, $sig] = $this->signed($doc, $k2['secret']);
        $this->assertRejected('bad_key_endorsement', fn () => Catalogue::verify($json, $sig, [$k1['public']], null));
    }

    /** @return array<string, array{mixed}> */
    public static function badNextKeys(): array
    {
        $k = Signer::keypair(2);
        $good = B64::encode($k['public']);
        $sig = B64::encode(str_repeat('s', 64));
        return [
            'null' => [null],
            'a string' => ['x'],
            'no sig' => [['public_key' => $good]],
            'no key' => [['sig' => $sig]],
            'short key' => [['public_key' => B64::encode('short'), 'sig' => $sig]],
            'short sig' => [['public_key' => $good, 'sig' => B64::encode('short')]],
            'key not base64url' => [['public_key' => '***', 'sig' => $sig]],
            'key not a string' => [['public_key' => 5, 'sig' => $sig]],
        ];
    }

    #[DataProvider('badNextKeys')]
    public function test_next_key_of_the_wrong_shape_is_bad_key_endorsement(mixed $nextKey): void
    {
        $k1 = Signer::keypair(1);
        $doc = self::doc();
        $doc['next_key'] = $nextKey;
        [$json, $sig] = $this->signed($doc, $k1['secret']);
        $this->assertRejected('bad_key_endorsement', fn () => Catalogue::verify($json, $sig, [$k1['public']], null));
    }

    public function test_new_key_cannot_endorse_itself(): void
    {
        $k1 = Signer::keypair(1);
        $k2 = Signer::keypair(2);
        $doc = self::doc();
        $doc['next_key'] = [
            'public_key' => B64::encode($k2['public']),
            'sig' => Signer::sign(Crypto::CTX_HUB_KEY, $k2['public'], $k2['secret']),
        ];
        [$json, $sig] = $this->signed($doc, $k2['secret']);
        $this->assertRejected('bad_key_endorsement', fn () => Catalogue::verify($json, $sig, [$k1['public']], null));
    }

    public function test_html_error_page_is_malformed(): void
    {
        $hub = Signer::keypair(1);
        $sig = Signer::sign(Crypto::CTX_CATALOGUE, '<html>500</html>', $hub['secret']);
        $this->assertRejected('malformed', fn () => Catalogue::verify('<html>500</html>', $sig, [$hub['public']], null));
    }

    public function test_json_list_is_malformed(): void
    {
        $hub = Signer::keypair(1);
        $sig = Signer::sign(Crypto::CTX_CATALOGUE, '[1]', $hub['secret']);
        $this->assertRejected('malformed', fn () => Catalogue::verify('[1]', $sig, [$hub['public']], null));
    }

    public function test_archive_with_path_in_filename_is_malformed(): void
    {
        $hub = Signer::keypair(1);
        $doc = self::doc();
        $doc['archives'][0]['filename'] = '../x.sd7';
        [$json, $sig] = $this->signed($doc, $hub['secret']);
        $this->assertRejected('malformed', fn () => Catalogue::verify($json, $sig, [$hub['public']], null));
    }

    public function test_unknown_fields_are_allowed(): void
    {
        $hub = Signer::keypair(1);
        $doc = self::doc();
        $doc['future'] = true;
        $doc['archives'][0]['extra'] = 1;
        [$json, $sig] = $this->signed($doc, $hub['secret']);
        $this->assertSame(42, Catalogue::verify($json, $sig, [$hub['public']], null)['seq']);
    }

    /** @return array<string, array{callable}> */
    public static function badShapes(): array
    {
        $mut = static fn (callable $f): callable => static function () use ($f): array {
            $d = self::doc();
            $f($d);
            return $d;
        };
        return [
            'v 2' => [$mut(function (&$d) { $d['v'] = 2; })],
            'v string' => [$mut(function (&$d) { $d['v'] = '1'; })],
            'seq zero' => [$mut(function (&$d) { $d['seq'] = 0; })],
            'seq string' => [$mut(function (&$d) { $d['seq'] = '5'; })],
            'seq float' => [$mut(function (&$d) { $d['seq'] = 5.5; })],
            'generated_at string' => [$mut(function (&$d) { $d['generated_at'] = 'now'; })],
            'missing mirrors' => [$mut(function (&$d) { unset($d['mirrors']); })],
            'missing archives' => [$mut(function (&$d) { unset($d['archives']); })],
            'missing removed' => [$mut(function (&$d) { unset($d['removed']); })],
            'archives an object' => [$mut(function (&$d) { $d['archives'] = ['a' => $d['archives'][0]]; })],
            'archive not an object' => [$mut(function (&$d) { $d['archives'] = [5]; })],
            'mirror id upper case' => [$mut(function (&$d) { $d['mirrors'][0]['id'] = 'M1'; })],
            'mirror id trailing newline' => [$mut(function (&$d) { $d['mirrors'][0]['id'] = "m1\n"; })],
            'mirror id leading hyphen' => [$mut(function (&$d) { $d['mirrors'][0]['id'] = '-m1'; })],
            'base_url ftp' => [$mut(function (&$d) { $d['mirrors'][0]['base_url'] = 'ftp://m1.example'; })],
            'base_url trailing slash' => [$mut(function (&$d) { $d['mirrors'][0]['base_url'] = 'https://m1.example/'; })],
            'base_url no host' => [$mut(function (&$d) { $d['mirrors'][0]['base_url'] = 'https://'; })],
            'base_url javascript' => [$mut(function (&$d) { $d['mirrors'][0]['base_url'] = 'javascript:alert(1)'; })],
            'mirror key short' => [$mut(function (&$d) { $d['mirrors'][0]['public_key'] = B64::encode('short'); })],
            'mirror key not base64url' => [$mut(function (&$d) { $d['mirrors'][0]['public_key'] = '***'; })],
            'archive kind unknown' => [$mut(function (&$d) { $d['archives'][0]['kind'] = 'mod'; })],
            'archive sha upper case' => [$mut(function (&$d) { $d['archives'][0]['sha256'] = strtoupper(self::SHA); })],
            'archive sha trailing newline' => [$mut(function (&$d) { $d['archives'][0]['sha256'] = substr(self::SHA, 1) . "\n"; })],
            'archive size zero' => [$mut(function (&$d) { $d['archives'][0]['size'] = 0; })],
            'archive size string' => [$mut(function (&$d) { $d['archives'][0]['size'] = '5'; })],
            'archive internal_name null' => [$mut(function (&$d) { $d['archives'][0]['internal_name'] = null; })],
            'archive version int' => [$mut(function (&$d) { $d['archives'][0]['version'] = 1; })],
            'archive shortname int' => [$mut(function (&$d) { $d['archives'][0]['shortname'] = 1; })],
            'archive author missing' => [$mut(function (&$d) { unset($d['archives'][0]['author']); })],
            'archive description int' => [$mut(function (&$d) { $d['archives'][0]['description'] = 1; })],
            'removed sha short' => [$mut(function (&$d) { $d['removed'][0]['sha256'] = 'abc'; })],
            'removed filename empty' => [$mut(function (&$d) { $d['removed'][0]['filename'] = ''; })],
            'removed reason int' => [$mut(function (&$d) { $d['removed'][0]['reason'] = 1; })],
            'removed removed_at string' => [$mut(function (&$d) { $d['removed'][0]['removed_at'] = 'x'; })],
        ];
    }

    #[DataProvider('badShapes')]
    public function test_wrong_shape_is_malformed(callable $make): void
    {
        $hub = Signer::keypair(1);
        [$json, $sig] = $this->signed($make(), $hub['secret']);
        $this->assertRejected('malformed', fn () => Catalogue::verify($json, $sig, [$hub['public']], null));
    }

    public function test_empty_lists_are_accepted(): void
    {
        $hub = Signer::keypair(1);
        $doc = self::doc();
        $doc['mirrors'] = [];
        $doc['archives'] = [];
        $doc['removed'] = [];
        [$json, $sig] = $this->signed($doc, $hub['secret']);
        $this->assertSame(42, Catalogue::verify($json, $sig, [$hub['public']], null)['seq']);
    }

    public function test_bad_signature_is_reported_before_not_newer_and_shape(): void
    {
        $hub = Signer::keypair(1);
        $stranger = Signer::keypair(3);
        $doc = self::doc(1);
        $doc['v'] = 9;
        [$json, $sig] = $this->signed($doc, $stranger['secret']);
        $this->assertRejected('bad_signature', fn () => Catalogue::verify($json, $sig, [$hub['public']], 5));
    }

    public function test_not_newer_is_reported_before_shape(): void
    {
        $hub = Signer::keypair(1);
        $doc = self::doc(1);
        $doc['v'] = 9;
        [$json, $sig] = $this->signed($doc, $hub['secret']);
        $this->assertRejected('not_newer', fn () => Catalogue::verify($json, $sig, [$hub['public']], 5));
    }
}
