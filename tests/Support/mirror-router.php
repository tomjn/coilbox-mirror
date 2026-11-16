<?php

declare(strict_types=1);

// Router for mirror folders in the integration tests. PHP's built-in server ignores Range headers, and Apache
// honours them, so this answers a Range request for a static file with 206 like Apache does. It logs each one
// to the server log as "range 206 <header> <path>". Everything else falls through to the built-in server.

$root = realpath($_SERVER['DOCUMENT_ROOT']);
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$file = realpath($root . $path);
$range = $_SERVER['HTTP_RANGE'] ?? '';

if (
    $file === false
    || !str_starts_with($file, $root . '/')
    || !is_file($file)
    || str_ends_with($file, '.php')
    || preg_match('/^bytes=(\d+)-(\d*)$/', $range, $m) !== 1
) {
    return false;
}

$size = filesize($file);
$first = (int) $m[1];
$last = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
if ($first > $last) {
    http_response_code(416);
    header("Content-Range: bytes */$size");
    return true;
}

error_log("range 206 $range $path");
http_response_code(206);
header('Content-Type: application/octet-stream');
header("Content-Range: bytes $first-$last/$size");
header('Content-Length: ' . ($last - $first + 1));
$in = fopen($file, 'rb');
fseek($in, $first);
echo stream_get_contents($in, $last - $first + 1);
fclose($in);
return true;
