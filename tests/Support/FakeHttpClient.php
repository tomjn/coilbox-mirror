<?php

declare(strict_types=1);

namespace Mirror\Tests\Support;

use Mirror\Deadline;
use Mirror\DownloadResult;
use Mirror\HttpClient;
use Mirror\HttpResponse;

/** Answers with canned responses and records what was sent. */
final class FakeHttpClient implements HttpClient
{
    /** @var array<string, HttpResponse> */
    private array $responses = [];

    /** @var list<array{string, string}> each POST as [url, body] */
    public array $posts = [];

    /** @var list<string> */
    public array $downloads = [];

    /** @var list<string> */
    public array $downloadDests = [];

    /** @var array<string, array{DownloadResult, string|null}> */
    private array $downloadOutcomes = [];

    /** Makes download($url) return $outcome, first writing $bytes to the destination when given. */
    public function onDownload(string $url, DownloadResult $outcome, ?string $bytes = null): void
    {
        $this->downloadOutcomes[$url] = [$outcome, $bytes];
    }

    public function on(string $url, HttpResponse $r): void
    {
        $this->responses[$url] = $r;
    }

    public function get(string $url, ?int $maxBytes = null): HttpResponse
    {
        $r = $this->responses[$url] ?? new HttpResponse(0, '');
        if ($maxBytes !== null && strlen($r->body) > $maxBytes) {
            return new HttpResponse(0, '');
        }
        return $r;
    }

    public function postJson(string $url, string $body): HttpResponse
    {
        $this->posts[] = [$url, $body];
        return $this->responses[$url] ?? new HttpResponse(0, '');
    }

    public function download(string $url, string $dest, int $expectedSize, Deadline $deadline): DownloadResult
    {
        $this->downloads[] = $url;
        $this->downloadDests[] = $dest;
        if (isset($this->downloadOutcomes[$url])) {
            [$outcome, $bytes] = $this->downloadOutcomes[$url];
            if ($bytes !== null) {
                file_put_contents($dest, $bytes);
            }
            return $outcome;
        }
        $r = $this->responses[$url] ?? null;
        if ($r === null || $r->status !== 200) {
            return DownloadResult::Failed;
        }
        file_put_contents($dest, $r->body);
        return DownloadResult::Complete;
    }
}
