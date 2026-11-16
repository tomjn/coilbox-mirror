<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\Listing;
use Mirror\Tests\Support\TempMirror;
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
