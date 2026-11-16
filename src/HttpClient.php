<?php

declare(strict_types=1);

namespace Mirror;

interface HttpClient
{
    /** Returns status 0 and an empty body when the body is longer than $maxBytes. */
    public function get(string $url, ?int $maxBytes = null): HttpResponse;

    public function postJson(string $url, string $body): HttpResponse;

    /** Downloads to $dest, resuming a shorter existing file. */
    public function download(string $url, string $dest, int $expectedSize, Deadline $deadline): DownloadResult;
}
