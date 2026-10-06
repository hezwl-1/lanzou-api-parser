<?php
declare(strict_types=1);

$target = trim((string) ($_GET['url'] ?? ''));
if ($target === '' || !filter_var($target, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit('invalid download url');
}

$host = strtolower((string) parse_url($target, PHP_URL_HOST));
if ($host === '' || !preg_match('/(?:^|\.)dmpdmp\.com$|(?:^|\.)lanzouc\.com$/i', $host)) {
    http_response_code(403);
    exit('download host is not allowed');
}

if (!function_exists('curl_init')) {
    http_response_code(500);
    exit('curl extension is required');
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $target,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_ENCODING => '',
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/124.0 Mobile Safari/537.36',
    CURLOPT_HTTPHEADER => [
        'Accept: */*',
        'Referer: https://wwbvf.lanzouu.com/',
    ],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 120,
]);

$body = curl_exec($ch);
$error = curl_error($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$contentLength = (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
if (PHP_VERSION_ID < 80500) {
    curl_close($ch);
}

if ($body === false || $status < 200 || $status >= 300) {
    http_response_code($status > 0 ? $status : 502);
    exit($error !== '' ? $error : 'upstream download failed');
}

header('Content-Type: ' . ($contentType !== '' ? $contentType : 'application/octet-stream'));
if ($contentLength > 0) {
    header('Content-Length: ' . $contentLength);
}
header('Content-Disposition: attachment');
header('Cache-Control: no-store');
echo $body;
