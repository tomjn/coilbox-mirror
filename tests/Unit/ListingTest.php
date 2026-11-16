<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\Json;
use Mirror\Listing;
use Mirror\Tests\Support\TempMirror;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListingTest extends TestCase
{
    public function test_lists_maps_and_games_with_hashes(): void
    {
        $paths = TempMirror::create();
        try {
            file_put_contents($paths->maps() . '/b.sd7', 'bbb');
            file_put_contents($paths->maps() . '/a.sd7', 'aaa');
            file_put_contents($paths->games() . '/g.sdz', 'ggg');
            touch($paths->maps() . '/a.sd7', 1795000000);

            $doc = Listing::build($paths, null, 1795000100);

            $this->assertSame(1, $doc['v']);
            $this->assertSame(1795000100, $doc['generated_at']);
            $this->assertSame(
                [['game', 'g.sdz'], ['map', 'a.sd7'], ['map', 'b.sd7']],
                array_map(static fn (array $f) => [$f['kind'], $f['filename']], $doc['files']),
            );
            $a = Listing::find($doc, 'map', 'a.sd7');
            $this->assertSame(3, $a['size']);
            $this->assertSame(hash('sha256', 'aaa'), $a['sha256']);
            $this->assertSame(1795000000, $a['mtime']);
            $this->assertNull(Listing::find($doc, 'map', 'missing.sd7'));
        } finally {
            TempMirror::destroy($paths);
        }
    }

    public function test_skips_htaccess_and_invalid_names(): void
    {
        $paths = TempMirror::create();
        try {
            file_put_contents($paths->maps() . '/.htaccess', 'x');
            file_put_contents($paths->maps() . '/notes.txt', 'x');
            file_put_contents($paths->maps() . '/ok.sd7', 'x');
            mkdir($paths->maps() . '/folder.sd7');

            $doc = Listing::build($paths, null, 1795000100);

            $this->assertSame(['ok.sd7'], array_column($doc['files'], 'filename'));
        } finally {
            TempMirror::destroy($paths);
        }
    }

    public function test_skips_empty_files_so_the_listing_stays_valid(): void
    {
        $paths = TempMirror::create();
        try {
            file_put_contents($paths->maps() . '/empty.sd7', '');
            file_put_contents($paths->maps() . '/ok.sd7', 'x');

            $doc = Listing::build($paths, null, 1795000100);

            $this->assertSame(['ok.sd7'], array_column($doc['files'], 'filename'));
            $this->assertTrue(Listing::isValidDoc($doc));
        } finally {
            TempMirror::destroy($paths);
        }
    }

    public function test_reuses_previous_hash_when_size_and_mtime_match(): void
    {
        $paths = TempMirror::create();
        try {
            file_put_contents($paths->maps() . '/a.sd7', 'aaa');
            touch($paths->maps() . '/a.sd7', 1795000000);
            $previous = ['v' => 1, 'generated_at' => 1, 'files' => [
                ['filename' => 'a.sd7', 'kind' => 'map', 'size' => 3, 'sha256' => 'fake', 'mtime' => 1795000000],
            ]];

            $doc = Listing::build($paths, $previous, 1795000100);

            $this->assertSame('fake', Listing::find($doc, 'map', 'a.sd7')['sha256']);
        } finally {
            TempMirror::destroy($paths);
        }
    }

    public function test_rehashes_when_mtime_changes(): void
    {
        $paths = TempMirror::create();
        try {
            file_put_contents($paths->maps() . '/a.sd7', 'aaa');
            touch($paths->maps() . '/a.sd7', 1795000500);
            $previous = ['v' => 1, 'generated_at' => 1, 'files' => [
                ['filename' => 'a.sd7', 'kind' => 'map', 'size' => 3, 'sha256' => 'fake', 'mtime' => 1795000000],
            ]];

            $doc = Listing::build($paths, $previous, 1795000600);

            $this->assertSame(hash('sha256', 'aaa'), Listing::find($doc, 'map', 'a.sd7')['sha256']);
        } finally {
            TempMirror::destroy($paths);
        }
    }

    public function test_add_file_updates_existing_listing(): void
    {
        $paths = TempMirror::create();
        try {
            file_put_contents($paths->maps() . '/a.sd7', 'aaa');
            touch($paths->maps() . '/a.sd7', 1795000000);
            Listing::write($paths, Listing::build($paths, null, 1795000100));

            file_put_contents($paths->maps() . '/c.sd7', 'ccc');
            file_put_contents($paths->maps() . '/a.sd7', 'aaaa');
            touch($paths->maps() . '/a.sd7', 1795000200);
            Listing::addFile($paths, 'map', 'c.sd7', 1795000300);
            Listing::addFile($paths, 'map', 'a.sd7', 1795000300);

            $doc = Listing::load($paths);
            $this->assertSame(1795000300, $doc['generated_at']);
            $this->assertSame(['a.sd7', 'c.sd7'], array_column($doc['files'], 'filename'));
            $this->assertSame(hash('sha256', 'aaaa'), Listing::find($doc, 'map', 'a.sd7')['sha256']);
            $this->assertSame(4, Listing::find($doc, 'map', 'a.sd7')['size']);
            $this->assertSame(hash('sha256', 'ccc'), Listing::find($doc, 'map', 'c.sd7')['sha256']);
            $this->assertSame([], glob($paths->root() . '/listing.json.*'));
        } finally {
            TempMirror::destroy($paths);
        }
    }

    public function test_add_file_works_without_an_existing_listing(): void
    {
        $paths = TempMirror::create();
        try {
            file_put_contents($paths->games() . '/g.sdz', 'ggg');

            Listing::addFile($paths, 'game', 'g.sdz', 1795000300);

            $doc = Listing::load($paths);
            $this->assertSame(1, $doc['v']);
            $this->assertSame(['g.sdz'], array_column($doc['files'], 'filename'));
        } finally {
            TempMirror::destroy($paths);
        }
    }

    /** @return array<string, mixed> */
    private static function entry(array $override = []): array
    {
        return $override + ['filename' => 'a.sd7', 'kind' => 'map', 'size' => 3, 'sha256' => str_repeat('a', 64), 'mtime' => 1795000000];
    }

    private static function docWith(array $files): array
    {
        return ['v' => 1, 'generated_at' => 1795000000, 'files' => $files];
    }

    public function test_is_valid_doc_accepts_a_well_formed_listing(): void
    {
        $this->assertTrue(Listing::isValidDoc(self::docWith([])));
        $this->assertTrue(Listing::isValidDoc(self::docWith([self::entry(), self::entry(['kind' => 'game', 'filename' => 'g.SDZ'])])));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badDocs(): array
    {
        return [
            'wrong version' => [['v' => 2, 'generated_at' => 1, 'files' => []]],
            'string generated_at' => [['v' => 1, 'generated_at' => '1', 'files' => []]],
            'files not a list' => [['v' => 1, 'generated_at' => 1, 'files' => ['x' => self::entry()]]],
            'files missing' => [['v' => 1, 'generated_at' => 1]],
            'entry not an array' => [self::docWith(['a.sd7'])],
            'bad filename' => [self::docWith([self::entry(['filename' => '../a.sd7'])])],
            'bad kind' => [self::docWith([self::entry(['kind' => 'mod'])])],
            'zero size' => [self::docWith([self::entry(['size' => 0])])],
            'string size' => [self::docWith([self::entry(['size' => '3'])])],
            'short hash' => [self::docWith([self::entry(['sha256' => 'abc'])])],
            'upper case hash' => [self::docWith([self::entry(['sha256' => str_repeat('A', 64)])])],
            'hash with trailing newline' => [self::docWith([self::entry(['sha256' => str_repeat('a', 64) . "\n"])])],
            'float mtime' => [self::docWith([self::entry(['mtime' => 1.5])])],
            'missing mtime' => [self::docWith([['filename' => 'a.sd7', 'kind' => 'map', 'size' => 3, 'sha256' => str_repeat('a', 64)]])],
        ];
    }

    #[DataProvider('badDocs')]
    public function test_is_valid_doc_rejects_malformed_listings(array $doc): void
    {
        $this->assertFalse(Listing::isValidDoc($doc));
    }

    public function test_max_doc_bytes_covers_worst_case_listings(): void
    {
        $names = ['x' . str_repeat("\u{2028}", 83) . '.sd7', str_repeat('"', 251) . '.sd7', str_repeat('a', 251) . '.sd7'];
        foreach ([0, 1, 5] as $count) {
            $files = [];
            for ($i = 0; $i < $count; $i++) {
                $files[] = ['filename' => $names[$i % 3], 'kind' => 'game', 'size' => PHP_INT_MAX, 'sha256' => str_repeat('f', 64), 'mtime' => PHP_INT_MAX];
            }
            $doc = ['v' => 1, 'generated_at' => PHP_INT_MAX, 'files' => $files];
            $this->assertTrue(Listing::isValidDoc($doc));
            $this->assertGreaterThanOrEqual(strlen(Json::encode($doc)), Listing::maxDocBytes($count));
        }
    }

    public function test_load_returns_null_when_an_entry_is_malformed(): void
    {
        $paths = TempMirror::create();
        try {
            file_put_contents($paths->listing(), json_encode(self::docWith([self::entry(['size' => 0])])));
            $this->assertNull(Listing::load($paths));
        } finally {
            TempMirror::destroy($paths);
        }
    }

    public function test_load_returns_null_when_missing_or_invalid(): void
    {
        $paths = TempMirror::create();
        try {
            $this->assertNull(Listing::load($paths));
            file_put_contents($paths->listing(), '[1,2');
            $this->assertNull(Listing::load($paths));
            file_put_contents($paths->listing(), '{"v":1}');
            $this->assertNull(Listing::load($paths));
        } finally {
            TempMirror::destroy($paths);
        }
    }
}
