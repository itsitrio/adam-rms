<?php
// Test-only: a stand-in for an S3 bucket, run with PHP's built-in server (`php -S host:port fakeS3.php`), so the
// e2e suite can check files going through AdamRMS. It speaks just enough path-style S3 for the AWS SDK: PUT, GET
// (with a byte range or If-None-Match), HEAD and DELETE of /<bucket>/<key>. Signatures aren't checked. Objects are
// kept in FAKE_S3_DIR. GET /_log returns the requests made so far, and DELETE /_log clears them.
$dir = getenv('FAKE_S3_DIR') ?: sys_get_temp_dir() . '/adamrms-e2e-fake-s3';
if (!is_dir($dir)) mkdir($dir, 0777, true);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$method = $_SERVER['REQUEST_METHOD'];
$logFile = $dir . '/_log.json';

if ($path === '/_log') {
    if ($method === 'DELETE') file_put_contents($logFile, '[]');
    header('Content-Type: application/json');
    echo is_file($logFile) ? file_get_contents($logFile) : '[]';
    return true;
}
$log = is_file($logFile) ? json_decode(file_get_contents($logFile), true) : [];
$log[] = ["method" => $method, "path" => $path, "range" => $_SERVER['HTTP_RANGE'] ?? null];
file_put_contents($logFile, json_encode($log));

function s3Error($status, $code)
{
    http_response_code($status);
    header('Content-Type: application/xml');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Error><Code>$code</Code><Message>$code</Message></Error>";
    return true;
}

if (!preg_match('#^/([^/]+)/(.+)$#', $path, $match)) return s3Error(400, 'InvalidRequest');
$file = $dir . '/' . $match[1] . '/' . hash('sha256', $match[2]);

if ($method === 'PUT') {
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0777, true);
    $body = file_get_contents('php://input');
    file_put_contents($file, $body);
    file_put_contents($file . '.json', json_encode(["key" => $match[2], "type" => $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? null]));
    header('ETag: "' . md5($body) . '"');
    return true;
}
if ($method === 'DELETE') {
    @unlink($file);
    @unlink($file . '.json');
    http_response_code(204);
    return true;
}
if ($method !== 'GET' and $method !== 'HEAD') return s3Error(405, 'MethodNotAllowed');
if (!is_file($file)) return s3Error(404, 'NoSuchKey');

$body = file_get_contents($file);
$meta = json_decode(file_get_contents($file . '.json'), true);
$etag = '"' . md5($body) . '"';
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? null) === $etag) {
    http_response_code(304);
    header('ETag: ' . $etag);
    return true;
}
header('ETag: ' . $etag);
header('Content-Type: ' . ($meta['type'] ?: 'binary/octet-stream'));
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($file)) . ' GMT');
$size = strlen($body);
if (isset($_SERVER['HTTP_RANGE']) and preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'], $range)) {
    $start = $range[1] === '' ? max(0, $size - intval($range[2])) : intval($range[1]);
    $end = ($range[1] === '' or $range[2] === '') ? $size - 1 : min(intval($range[2]), $size - 1);
    if ($start > $end or $start >= $size) return s3Error(416, 'InvalidRange');
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
    $body = substr($body, $start, $end - $start + 1);
}
header('Content-Length: ' . strlen($body));
if ($method === 'GET') echo $body;
return true;
