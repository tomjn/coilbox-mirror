<?php

declare(strict_types=1);

namespace Mirror\Tests\Unit;

use Mirror\B64;
use Mirror\Crypto;
use Mirror\Json;
use Mirror\ProtocolError;
use Mirror\Tests\Support\Signer;
use Mirror\Token;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokenTest extends TestCase
{
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @return array<string, mixed> */
    private static function payload(): array
    {
        return [
            'v' => 1,
            'mirror' => 'm1',
            'upload' => '7f8c-AB',
            'sha256' => self::SHA,
            'size' => 123,
            'filename' => 'x.sd7',
            'kind' => 'map',
            'start_by' => 1795000000,
        ];
    }

    private function expectError(string $code, callable $fn): void
    {
        try {
            $fn();
        } catch (ProtocolError $e) {
            $this->assertSame($code, $e->error);
            return;
        }
        $this->fail("Expected ProtocolError $code");
    }

    public function test_valid_token_returns_fields(): void
    {
        $hub = Signer::keypair(1);
        $other = Signer::keypair(2);
        $t = Token::verify(Signer::token(self::payload(), $hub['secret']), [$other['public'], $hub['public']], 'm1');
        $this->assertSame('m1', $t->mirror);
        $this->assertSame('7f8c-AB', $t->uploadId);
        $this->assertSame(self::SHA, $t->sha256);
        $this->assertSame(123, $t->size);
        $this->assertSame('x.sd7', $t->filename);
        $this->assertSame('map', $t->kind);
        $this->assertSame(1795000000, $t->startBy);
    }

    public function test_signature_from_unlisted_key_is_bad_token(): void
    {
        $hub = Signer::keypair(1);
        $stranger = Signer::keypair(3);
        $this->expectError('bad_token', fn () => Token::verify(Signer::token(self::payload(), $stranger['secret']), [$hub['public']], 'm1'));
    }

    public function test_no_listed_keys_is_bad_token(): void
    {
        $hub = Signer::keypair(1);
        $this->expectError('bad_token', fn () => Token::verify(Signer::token(self::payload(), $hub['secret']), [], 'm1'));
    }

    public function test_tampered_payload_is_bad_token(): void
    {
        $hub = Signer::keypair(1);
        $good = Signer::token(self::payload(), $hub['secret']);
        [, $sig] = explode('.', $good);
        $evil = self::payload();
        $evil['size'] = 999;
        $forged = B64::encode(Json::encode($evil)) . '.' . $sig;
        $this->expectError('bad_token', fn () => Token::verify($forged, [$hub['public']], 'm1'));
    }

    public function test_token_signed_under_catalogue_context_is_bad_token(): void
    {
        $hub = Signer::keypair(1);
        $json = Json::encode(self::payload());
        $raw = B64::encode($json) . '.' . Signer::sign(Crypto::CTX_CATALOGUE, $json, $hub['secret']);
        $this->expectError('bad_token', fn () => Token::verify($raw, [$hub['public']], 'm1'));
    }

    public function test_other_mirror_id_is_wrong_mirror(): void
    {
        $hub = Signer::keypair(1);
        $raw = Signer::token(self::payload(), $hub['secret']);
        $this->expectError('wrong_mirror', fn () => Token::verify($raw, [$hub['public']], 'm2'));
    }

    public function test_wrong_mirror_is_not_reported_for_an_invalid_signature(): void
    {
        $hub = Signer::keypair(1);
        $stranger = Signer::keypair(3);
        $this->expectError('bad_token', fn () => Token::verify(Signer::token(self::payload(), $stranger['secret']), [$hub['public']], 'm2'));
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function badPayloads(): array
    {
        $p = self::payload();
        $without = static function (string $k) use ($p): array {
            unset($p[$k]);
            return $p;
        };
        return [
            'filename with slash' => [[...$p, 'filename' => 'a/b.sd7']],
            'filename wrong extension' => [[...$p, 'filename' => 'x.zip']],
            'filename not a string' => [[...$p, 'filename' => 5]],
            'kind unknown' => [[...$p, 'kind' => 'mod']],
            'kind upper case' => [[...$p, 'kind' => 'Map']],
            'upload empty' => [[...$p, 'upload' => '']],
            'upload too long' => [[...$p, 'upload' => str_repeat('a', 65)]],
            'upload with dot' => [[...$p, 'upload' => 'a.b']],
            'upload with trailing newline' => [[...$p, 'upload' => "abc\n"]],
            'upload with slash' => [[...$p, 'upload' => '../x']],
            'upload not a string' => [[...$p, 'upload' => 12]],
            'sha upper case' => [[...$p, 'sha256' => strtoupper(self::SHA)]],
            'sha short' => [[...$p, 'sha256' => substr(self::SHA, 1)]],
            'sha with trailing newline' => [[...$p, 'sha256' => substr(self::SHA, 1) . "\n"]],
            'size zero' => [[...$p, 'size' => 0]],
            'size negative' => [[...$p, 'size' => -5]],
            'size string' => [[...$p, 'size' => '123']],
            'size fractional' => [[...$p, 'size' => 123.5]],
            'v unknown' => [[...$p, 'v' => 2]],
            'v string' => [[...$p, 'v' => '1']],
            'v true' => [[...$p, 'v' => true]],
            'start_by string' => [[...$p, 'start_by' => '1795000000']],
            'start_by float' => [[...$p, 'start_by' => 1795000000.5]],
            'mirror not a string' => [[...$p, 'mirror' => 1]],
            'extra field' => [[...$p, 'extra' => 1]],
            'missing v' => [$without('v')],
            'missing mirror' => [$without('mirror')],
            'missing upload' => [$without('upload')],
            'missing sha256' => [$without('sha256')],
            'missing size' => [$without('size')],
            'missing filename' => [$without('filename')],
            'missing kind' => [$without('kind')],
            'missing start_by' => [$without('start_by')],
        ];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('badPayloads')]
    public function test_invalid_field_is_bad_token(array $payload): void
    {
        $hub = Signer::keypair(1);
        $raw = Signer::token($payload, $hub['secret']);
        $this->expectError('bad_token', fn () => Token::verify($raw, [$hub['public']], 'm1'));
    }

    public function test_whole_number_written_as_float_is_bad_token(): void
    {
        $hub = Signer::keypair(1);
        $json = '{"v":1,"mirror":"m1","upload":"7f8c-AB","sha256":"' . self::SHA . '","size":123.0,"filename":"x.sd7","kind":"map","start_by":1795000000}';
        $raw = B64::encode($json) . '.' . Signer::sign(Crypto::CTX_TOKEN, $json, $hub['secret']);
        $this->expectError('bad_token', fn () => Token::verify($raw, [$hub['public']], 'm1'));
    }

    /** @return array<string, array{string}> */
    public static function malformedTokens(): array
    {
        $hub = Signer::keypair(1);
        $good = Signer::token(self::payload(), $hub['secret']);
        [$p, $s] = explode('.', $good);
        $json = static fn (string $body): string => B64::encode($body) . '.' . Signer::sign(Crypto::CTX_TOKEN, $body, $hub['secret']);
        return [
            'empty' => [''],
            'no dot' => [$p],
            'two dots' => [$good . '.' . $s],
            'trailing dot' => [$good . '.'],
            'empty payload' => ['.' . $s],
            'empty signature' => [$p . '.'],
            'standard alphabet padding' => [$p . '=.' . $s],
            'payload not base64url' => ['***.' . $s],
            'signature not base64url' => [$p . '.***'],
            'signature wrong length' => [$p . '.' . B64::encode('short')],
            'signed text is not JSON' => [$json('not json')],
            'signed JSON is a list' => [$json('[1,2]')],
            'signed JSON is a number' => [$json('5')],
            'surrounding whitespace' => [" $good"],
            'trailing newline' => ["$good\n"],
        ];
    }

    #[DataProvider('malformedTokens')]
    public function test_malformed_token_is_bad_token(string $raw): void
    {
        $hub = Signer::keypair(1);
        $this->expectError('bad_token', fn () => Token::verify($raw, [$hub['public']], 'm1'));
    }

    public function test_start_by_in_the_past_is_expired(): void
    {
        $hub = Signer::keypair(1);
        $t = Token::verify(Signer::token(self::payload(), $hub['secret']), [$hub['public']], 'm1');
        $t->checkStartBy(1795000000);
        $this->expectError('expired', fn () => $t->checkStartBy(1795000001));
    }
}
