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

    public function on(string $url, HttpResponse $r): void
    {
        $this->responses[$url] = $r;
    }

    public function get(string $url): HttpResponse
    {
        return $this->responses[$url] ?? new HttpResponse(0, '');
    }

    public function postJson(string $url, string $body): HttpResponse
    {
        $this->posts[] = [$url, $body];
        return $this->responses[$url] ?? new HttpResponse(0, '');
    }

    public function download(string $url, string $dest, int $expectedSize, Deadline $deadline): DownloadResult
    {
        $this->downloads[] = $url;
        $r = $this->responses[$url] ?? null;
        if ($r === null || $r->status !== 200) {
            return DownloadResult::Failed;
        }
        file_put_contents($dest, $r->body);
        return DownloadResult::Complete;
    }
}
