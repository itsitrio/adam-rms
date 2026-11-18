<?php
/*
 * Sends a file from the storage bucket, for installations whose files go through this server
 * (FILES_TRANSFER_MODE = Server). Links to it are made by $bCMS->s3URL(), after it has checked the file may be
 * seen, and carry an expiry and a signature in place of a session, as a pre-signed S3 link would.
 *  Parameters
 *      f (required) - the file id
 *      d (required) - 1 to force a download, 0 to show the file in the browser where that is safe
 *      exp (required) - when the link expires, as a unix timestamp
 *      sig (required) - the link's signature
 */
require_once __DIR__ . '/../apiHead.php';

function streamRefuse($status, $message)
{
    http_response_code($status);
    finish(false, ["code" => null, "message" => $message]);
}

if ($CONFIG['FILES_ENABLED'] !== "Enabled" or !$bCMS->s3ServerTransfer()) streamRefuse(404, "File not found");
$fileid = $_GET['f'] ?? null;
$download = $_GET['d'] ?? null;
$expires = $_GET['exp'] ?? null;
$signature = $_GET['sig'] ?? null;
if (!is_string($fileid) or !ctype_digit($fileid) or !in_array($download, ["0", "1"], true) or !is_string($expires) or !ctype_digit($expires) or !is_string($signature)) streamRefuse(403, "Invalid link");
if (!hash_equals($bCMS->s3StreamSignature($fileid, $download === "1", $expires), $signature)) streamRefuse(403, "Invalid link");
if (intval($expires) < time()) streamRefuse(403, "This link has expired - please reload the page");

$DBLIB->where("s3files_id", intval($fileid));
$DBLIB->where("(s3files_meta_deleteOn >= '" . date("Y-m-d H:i:s") . "' OR s3files_meta_deleteOn IS NULL)");
$DBLIB->where("s3files_meta_physicallyStored", 1);
$file = $DBLIB->getone("s3files", ["s3files_id", "s3files_path", "s3files_filename", "s3files_extension", "s3files_name"]);
if (!$file) streamRefuse(404, "File not found");

$contentType = $bCMS->s3ContentType($file['s3files_extension']);
// A link's file never changes, so the browser may keep it until the link expires without checking. When it does
// check (a reload), its copy is answered here from the database, without asking the bucket.
$cacheControl = "Cache-Control: private, max-age=" . max(0, intval($expires) - time()) . ", immutable";
$etag = $bCMS->s3StreamETag($file);
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) and in_array($etag, array_map(function ($tag) {
    return preg_replace('/^W\//', '', trim($tag));
}, explode(",", $_SERVER['HTTP_IF_NONE_MATCH'])), true)) {
    header_remove("Pragma");
    http_response_code(304);
    header("ETag: " . $etag);
    header($cacheControl);
    die();
}
$request = [
    'Bucket' => $CONFIGCLASS->get('AWS_S3_BUCKET'),
    'Key' => $bCMS->s3ObjectKey($file),
];
// Pass on a single byte range, so video can be scrubbed
if (isset($_SERVER['HTTP_RANGE']) and preg_match('/^bytes=\d*-\d*$/', $_SERVER['HTTP_RANGE'])) $request['Range'] = $_SERVER['HTTP_RANGE'];

$head = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD';
try {
    $client = $bCMS->s3ServerClient();
    if ($head) $object = $client->headObject($request);
    else {
        $request['@http'] = ['stream' => true];
        $object = $client->getObject($request);
    }
} catch (Aws\S3\Exception\S3Exception $e) {
    $status = $e->getStatusCode();
    if ($status === 416) {
        http_response_code($status);
        die();
    } elseif ($status === 404) streamRefuse(404, "File not found");
    else streamRefuse(502, "Couldn't fetch the file from storage");
} catch (Exception $e) {
    streamRefuse(502, "Couldn't fetch the file from storage");
}

header_remove("Pragma");
header_remove("Access-Control-Allow-Methods");
header_remove("Access-Control-Allow-Headers");
$status = $object['@metadata']['statusCode'] ?? 200;
http_response_code($status === 206 ? 206 : 200);
header("Content-Type: " . $contentType['type']);
$name = preg_replace('/[^A-Za-z0-9 _\-]/', '_', (string) $file['s3files_name']) . '.' . preg_replace('/[^A-Za-z0-9]/', '', $file['s3files_extension']);
header("Content-Disposition: " . (($download === "1" or !$contentType['inline']) ? "attachment" : "inline") . '; filename="' . $name . '"');
header("X-Content-Type-Options: nosniff");
// The file comes from this site's own origin, so stop anything in it running script with the visitor's session.
// Chrome won't show a PDF in a sandboxed page, and PDF script runs in the viewer rather than the page, so they're left out.
if ($contentType['type'] !== 'application/pdf') header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'");
header($cacheControl);
header("Accept-Ranges: bytes");
header("ETag: " . $etag);
if (isset($object['LastModified'])) header("Last-Modified: " . $object['LastModified']->format(DATE_RFC7231));
if (isset($object['ContentRange'])) header("Content-Range: " . $object['ContentRange']);
if (isset($object['ContentLength'])) header("Content-Length: " . $object['ContentLength']);
if ($head) die();

while (ob_get_level() > 0) ob_end_clean();
$body = $object['Body'];
while (!$body->eof()) {
    echo $body->read(1048576);
    flush();
}
die();

/** @OA\Get(
 *     path="/file/stream.php",
 *     summary="Stream File",
 *     description="Send a file from storage, for installations whose files go through the AdamRMS server. Links to it are returned by /file/index.php and carry their own expiry and signature, so no session is needed.
",
 *     operationId="streamFile",
 *     tags={"file_uploads"},
 *     @OA\Response(
 *         response="200",
 *         description="The file",
 *     ),
 *     @OA\Response(
 *         response="206",
 *         description="Part of the file, for a Range request",
 *     ),
 *     @OA\Response(
 *         response="403",
 *         description="The link is invalid or has expired",
 *     ),
 *     @OA\Response(
 *         response="404",
 *         description="The file doesn't exist",
 *     ),
 *     @OA\Parameter(
 *         name="f",
 *         in="query",
 *         description="File ID",
 *         required="true",
 *         @OA\Schema(
 *             type="integer"),
 *         ),
 *     @OA\Parameter(
 *         name="d",
 *         in="query",
 *         description="1 to force a download, 0 to show the file in the browser",
 *         required="true",
 *         @OA\Schema(
 *             type="integer"),
 *         ),
 *     @OA\Parameter(
 *         name="exp",
 *         in="query",
 *         description="When the link expires (unix timestamp)",
 *         required="true",
 *         @OA\Schema(
 *             type="integer"),
 *         ),
 *     @OA\Parameter(
 *         name="sig",
 *         in="query",
 *         description="Link signature",
 *         required="true",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 * )
 */
