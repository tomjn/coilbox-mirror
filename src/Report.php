<?php

declare(strict_types=1);

namespace Mirror;

final class Report
{
    /**
     * @param array<string, mixed>|null $catalogue the held catalogue, or null
     * @param array<string, mixed> $listing the document from Listing
     * @param 'upload'|'sync' $event
     */
    public static function payload(Config $c, ?array $catalogue, array $listing, string $event, ?string $uploadId, int $now): string
    {
        $inCatalogue = [];
        foreach ($catalogue === null ? [] : Catalogue::wanted($catalogue) as $archive) {
            $inCatalogue[$archive['sha256']] = true;
        }

        $held = [];
        $unknown = [];
        foreach (Listing::entries($listing) as $file) {
            $held[$file['sha256']] = true;
            if (!isset($inCatalogue[$file['sha256']])) {
                $unknown[] = [
                    'filename' => $file['filename'],
                    'kind' => $file['kind'],
                    'size' => $file['size'],
                    'sha256' => $file['sha256'],
                ];
            }
        }
        $held = array_map('strval', array_keys($held));
        sort($held, SORT_STRING);

        return Json::encode([
            'v' => 1,
            'mirror' => $c->mirrorId,
            'event' => $event,
            'upload' => $uploadId,
            'sent_at' => $now,
            'catalogue_seq' => $catalogue['seq'] ?? null,
            'catalogue_generated_at' => $catalogue['generated_at'] ?? null,
            'held' => $held,
            'unknown' => $unknown,
            // The hub sends this mirror only these kinds. Mirrors on releases before engines send no kinds.
            'kinds' => Paths::KINDS,
        ]);
    }

    public static function body(string $payloadJson, string $secretKey): string
    {
        return Json::encode([
            'payload' => B64::encode($payloadJson),
            'sig' => B64::encode(Crypto::sign(Crypto::CTX_REPORT, $payloadJson, $secretKey)),
        ]);
    }

    /** Returns true when the hub answers with a 2xx status. */
    public static function send(HttpClient $h, Config $c, string $body): bool
    {
        return self::accepted(self::post($h, $c, $body));
    }

    public static function post(HttpClient $h, Config $c, string $body): HttpResponse
    {
        return $h->postJson($c->hubUrl . '/api/v1/mirrors/report', $body);
    }

    public static function accepted(HttpResponse $r): bool
    {
        return $r->status >= 200 && $r->status < 300;
    }

    /** True when the hub does not know this mirror, which is how it answers until a moderator approves it. */
    public static function unknownMirror(HttpResponse $r): bool
    {
        return $r->status === 403 && (Json::decodeObject($r->body)['error'] ?? null) === 'unknown_mirror';
    }
}
