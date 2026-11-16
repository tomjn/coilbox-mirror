<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\B64;
use Mirror\CatalogueRejected;
use Mirror\CatalogueStore;
use Mirror\Config;
use Mirror\Crypto;
use Mirror\Json;
use Mirror\Paths;
use Mirror\Tests\Support\Signer;
use Mirror\Tests\Support\TempMirror;
use PHPUnit\Framework\TestCase;

final class CatalogueStoreTest extends TestCase
{
    private Paths $paths;

    protected function setUp(): void
    {
        $this->paths = TempMirror::create();
    }

    protected function tearDown(): void
    {
        TempMirror::destroy($this->paths);
    }

    private function store(string ...$hubKeys): CatalogueStore
    {
        $mirror = Signer::keypair(8);
        $config = Config::fromArray([
            'mirror_id' => 'm1',
            'secret_key' => B64::encode($mirror['secret']),
            'hub_url' => 'https://hub.example',
            'hub_keys' => array_map(B64::encode(...), $hubKeys),
            'partial_lifetime' => 3600,
        ]);
        return new CatalogueStore($this->paths, $config);
    }

    /** @return array{string, string} */
    private function signed(array $doc, string $secret): array
    {
        $json = Json::encode($doc);
        return [$json, Signer::sign(Crypto::CTX_CATALOGUE, $json, $secret)];
    }

    public function test_nothing_is_held_at_first(): void
    {
        $k1 = Signer::keypair(1);
        $store = $this->store($k1['public']);
        $this->assertNull($store->held());
        $this->assertNull($store->heldSeq());
        $this->assertSame([$k1['public']], $store->trustedKeys());
        $this->assertFalse($store->isRemoved(str_repeat('a', 64), 'old.sd7'));
    }

    public function test_accept_writes_exact_bytes_and_sig(): void
    {
        $k1 = Signer::keypair(1);
        $store = $this->store($k1['public']);
        // Spacing that Json::encode would not produce proves the bytes are stored unchanged.
        $json = json_encode(CatalogueTest::doc(7), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $sig = Signer::sign(Crypto::CTX_CATALOGUE, $json, $k1['secret']);
        $cat = $store->accept($json, $sig);
        $this->assertSame(7, $cat['seq']);
        $this->assertSame($json, file_get_contents($this->paths->catalogueJson()));
        $this->assertSame($sig, file_get_contents($this->paths->catalogueSig()));
        $this->assertSame(7, $store->heldSeq());
        $this->assertSame('x.sd7', $store->held()['archives'][0]['filename']);
        $this->assertSame([], glob($this->paths->root() . '/*.tmp'));
    }

    public function test_rejected_catalogue_leaves_the_held_one_alone(): void
    {
        $k1 = Signer::keypair(1);
        $store = $this->store($k1['public']);
        [$json, $sig] = $this->signed(CatalogueTest::doc(5), $k1['secret']);
        $store->accept($json, $sig);
        [$old, $oldSig] = $this->signed(CatalogueTest::doc(4), $k1['secret']);
        try {
            $store->accept($old, $oldSig);
            $this->fail('Expected CatalogueRejected');
        } catch (CatalogueRejected $e) {
            $this->assertSame('not_newer', $e->reason);
        }
        $this->assertSame($json, file_get_contents($this->paths->catalogueJson()));
        $this->assertSame($sig, file_get_contents($this->paths->catalogueSig()));
    }

    public function test_endorsed_key_is_saved_and_trusted_later(): void
    {
        $k1 = Signer::keypair(1);
        $k2 = Signer::keypair(2);
        $store = $this->store($k1['public']);

        $doc2 = CatalogueTest::doc(2);
        $doc2['next_key'] = [
            'public_key' => B64::encode($k2['public']),
            'sig' => Signer::sign(Crypto::CTX_HUB_KEY, $k2['public'], $k1['secret']),
        ];
        [$json2, $sig2] = $this->signed($doc2, $k2['secret']);
        $store->accept($json2, $sig2);
        $this->assertSame([$k1['public'], $k2['public']], $store->trustedKeys());

        // A fresh store reads the saved key from disk. The config holds only K1.
        $later = $this->store($k1['public']);
        [$json3, $sig3] = $this->signed(CatalogueTest::doc(3), $k2['secret']);
        $this->assertSame(3, $later->accept($json3, $sig3)['seq']);
    }

    public function test_saving_an_already_trusted_key_changes_nothing(): void
    {
        $k1 = Signer::keypair(1);
        $k2 = Signer::keypair(2);
        $store = $this->store($k1['public']);
        $next = [
            'public_key' => B64::encode($k2['public']),
            'sig' => Signer::sign(Crypto::CTX_HUB_KEY, $k2['public'], $k1['secret']),
        ];
        foreach ([2, 3] as $seq) {
            $doc = CatalogueTest::doc($seq);
            $doc['next_key'] = $next;
            [$json, $sig] = $this->signed($doc, $k2['secret']);
            $store->accept($json, $sig);
        }
        $this->assertSame([$k1['public'], $k2['public']], $store->trustedKeys());
        $this->assertSame([B64::encode($k2['public'])], json_decode(file_get_contents($this->paths->state() . '/hub_keys.json'), true));
    }

    public function test_key_already_in_config_is_not_saved(): void
    {
        $k1 = Signer::keypair(1);
        $store = $this->store($k1['public']);
        $doc = CatalogueTest::doc(2);
        $doc['next_key'] = [
            'public_key' => B64::encode($k1['public']),
            'sig' => Signer::sign(Crypto::CTX_HUB_KEY, $k1['public'], $k1['secret']),
        ];
        [$json, $sig] = $this->signed($doc, $k1['secret']);
        $store->accept($json, $sig);
        $this->assertSame([$k1['public']], $store->trustedKeys());
        $this->assertFileDoesNotExist($this->paths->state() . '/hub_keys.json');
    }

    public function test_key_is_not_saved_when_the_catalogue_is_rejected(): void
    {
        $k1 = Signer::keypair(1);
        $k2 = Signer::keypair(2);
        $store = $this->store($k1['public']);
        $doc = CatalogueTest::doc(2);
        $doc['next_key'] = [
            'public_key' => B64::encode($k2['public']),
            'sig' => Signer::sign(Crypto::CTX_HUB_KEY, $k2['public'], $k1['secret']),
        ];
        $doc['v'] = 9;
        [$json, $sig] = $this->signed($doc, $k2['secret']);
        $this->expectException(CatalogueRejected::class);
        try {
            $store->accept($json, $sig);
        } finally {
            $this->assertSame([$k1['public']], $store->trustedKeys());
            $this->assertFileDoesNotExist($this->paths->catalogueJson());
        }
    }

    public function test_held_is_null_for_a_file_that_is_not_an_object(): void
    {
        $store = $this->store(Signer::keypair(1)['public']);
        file_put_contents($this->paths->catalogueJson(), '<html>');
        $this->assertNull($store->held());
    }

    public function test_accept_records_the_seq_in_private_state(): void
    {
        $k1 = Signer::keypair(1);
        $store = $this->store($k1['public']);
        [$json, $sig] = $this->signed(CatalogueTest::doc(7), $k1['secret']);
        $store->accept($json, $sig);
        $this->assertSame('7', file_get_contents($this->paths->state() . '/catalogue_seq'));
        $this->assertSame([], glob($this->paths->state() . '/*.tmp'));
    }

    public function test_damaged_catalogue_json_keeps_the_not_newer_check(): void
    {
        $k1 = Signer::keypair(1);
        $store = $this->store($k1['public']);
        [$json, $sig] = $this->signed(CatalogueTest::doc(7), $k1['secret']);
        $store->accept($json, $sig);
        file_put_contents($this->paths->catalogueJson(), '<html>');

        $this->assertSame(7, $store->heldSeq());
        [$old, $oldSig] = $this->signed(CatalogueTest::doc(6), $k1['secret']);
        try {
            $store->accept($old, $oldSig);
            $this->fail('Expected CatalogueRejected');
        } catch (CatalogueRejected $e) {
            $this->assertSame('not_newer', $e->reason);
        }
    }

    public function test_catalogue_held_before_the_state_file_existed_still_gives_its_seq(): void
    {
        $k1 = Signer::keypair(1);
        $store = $this->store($k1['public']);
        [$json, $sig] = $this->signed(CatalogueTest::doc(7), $k1['secret']);
        $store->accept($json, $sig);
        unlink($this->paths->state() . '/catalogue_seq');

        $this->assertSame(7, $store->heldSeq());
    }

    public function test_unreadable_catalogue_with_no_state_file_refuses_every_catalogue(): void
    {
        $k1 = Signer::keypair(1);
        $store = $this->store($k1['public']);
        file_put_contents($this->paths->catalogueJson(), '<html>');
        [$json, $sig] = $this->signed(CatalogueTest::doc(1), $k1['secret']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('catalogue_seq');
        try {
            $store->accept($json, $sig);
        } finally {
            $this->assertSame('<html>', file_get_contents($this->paths->catalogueJson()));
            $this->assertFileDoesNotExist($this->paths->state() . '/catalogue_seq');
        }
    }

    public function test_damaged_state_file_refuses_every_catalogue(): void
    {
        $k1 = Signer::keypair(1);
        $store = $this->store($k1['public']);
        file_put_contents($this->paths->state() . '/catalogue_seq', 'junk');
        [$json, $sig] = $this->signed(CatalogueTest::doc(1), $k1['secret']);
        $this->expectException(\RuntimeException::class);
        try {
            $store->accept($json, $sig);
        } finally {
            $this->assertFileDoesNotExist($this->paths->catalogueJson());
        }
    }

    public function test_failed_key_save_leaves_the_held_catalogue_alone(): void
    {
        $k1 = Signer::keypair(1);
        $k2 = Signer::keypair(2);
        $store = $this->store($k1['public']);
        // A folder where hub_keys.json should be makes the key save fail.
        mkdir($this->paths->state() . '/hub_keys.json');
        $doc = CatalogueTest::doc(2);
        $doc['next_key'] = [
            'public_key' => B64::encode($k2['public']),
            'sig' => Signer::sign(Crypto::CTX_HUB_KEY, $k2['public'], $k1['secret']),
        ];
        [$json, $sig] = $this->signed($doc, $k1['secret']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hub_keys.json');
        set_error_handler(static fn(): bool => true);
        try {
            $store->accept($json, $sig);
        } finally {
            restore_error_handler();
            $this->assertFileDoesNotExist($this->paths->catalogueJson());
            $this->assertFileDoesNotExist($this->paths->state() . '/catalogue_seq');
            $this->assertNull($store->heldSeq());
        }
    }

    public function test_is_removed_matches_hash_or_filename_ignoring_case(): void
    {
        $k1 = Signer::keypair(1);
        $store = $this->store($k1['public']);
        $doc = CatalogueTest::doc(1);
        $doc['removed'][] = ['sha256' => str_repeat('b', 64), 'filename' => 'Ünïcode Map.SD7', 'reason' => 'x', 'removed_at' => 1];
        [$json, $sig] = $this->signed($doc, $k1['secret']);
        $store->accept($json, $sig);

        $sha = str_repeat('a', 64);
        $this->assertTrue($store->isRemoved($sha, 'anything.sd7'));
        $this->assertTrue($store->isRemoved(str_repeat('c', 64), 'OLD.sd7'));
        $this->assertTrue($store->isRemoved(str_repeat('c', 64), 'old.SD7'));
        $this->assertTrue($store->isRemoved(str_repeat('c', 64), 'ünÏcode map.sd7'));
        $this->assertTrue($store->isRemoved(str_repeat('b', 64), 'other.sd7'));
        $this->assertFalse($store->isRemoved(str_repeat('c', 64), 'other.sd7'));
        $this->assertFalse($store->isRemoved(strtoupper($sha) === $sha ? 'x' : strtoupper($sha), 'other.sd7'));
    }
}
