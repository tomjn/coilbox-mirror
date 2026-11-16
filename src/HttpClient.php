<?php

declare(strict_types=1);

namespace Mirror;

interface HttpClient
{
    public function get(string $url): HttpResponse;

    public function postJson(string $url, string $body): HttpResponse;

    /** Downloads to $dest, resuming a shorter existing file. */
    public function download(string $url, string $dest, int $expectedSize, Deadline $deadline): DownloadResult;
}
