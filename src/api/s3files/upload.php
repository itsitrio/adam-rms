<?php
// Uploads a file through this server, for installations whose files go through it (FILES_TRANSFER_MODE = Server).
// The browser sends the file here; the server names it, stores it in the bucket and records it.
require_once __DIR__ . '/../apiHeadSecure.php';
if ($CONFIG['FILES_ENABLED'] !== "Enabled") {
    finish(false, ["code" => null, "message" => "File uploads are disabled"]);
}
if (!$bCMS->s3ServerTransfer()) finish(false, ["code" => null, "message" => "Files are uploaded straight to storage on this installation"]);
if (!$bCMS->s3SubTypeIsInInstance($_POST['typeid'] ?? null, $_POST['subtype'] ?? null)) finish(false, ["code" => null, "message" => "Record not found"]);

$upload = $_FILES['file'] ?? null;
if (!is_array($upload) or !is_string($upload['tmp_name'] ?? null)) finish(false, ["code" => null, "message" => "No file was sent"]);
if (in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE]) or $upload['size'] > bCMS::S3_SERVER_UPLOAD_MAX_BYTES) finish(false, ["code" => null, "message" => "Files can't be larger than 25MB"]);
if ($upload['error'] !== UPLOAD_ERR_OK or !is_uploaded_file($upload['tmp_name'])) finish(false, ["code" => null, "message" => "The file didn't arrive - please try again"]);

$extension = strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION));
if (!preg_match('/^[a-z0-9]{1,10}$/', $extension)) finish(false, ["code" => null, "message" => "The file's name needs an extension, such as .pdf"]);

$DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
$storageCapacity = $DBLIB->getvalue("instances", "instances_storageLimit");
if ($storageCapacity > 0 and $storageCapacity < $bCMS->s3StorageUsed($AUTH->data['instance']['instances_id']) + $upload['size']) {
    finish(false, ["code" => null, "message" => "Storage limit reached"]);
}

$folder = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($_POST['type'] ?? ''));
if ($folder === '') $folder = "FILE";
$key = "uploads/" . $folder . "/" . round(microtime(true) * 1000) . "-" . random_int(10000000000, PHP_INT_MAX) . "." . $extension;
try {
    $bCMS->s3ServerClient()->putObject([
        'Bucket' => $CONFIGCLASS->get('AWS_S3_BUCKET'),
        'Key' => $key,
        'SourceFile' => $upload['tmp_name'],
        'ContentType' => $bCMS->s3ContentType($extension)['type'],
    ]);
} catch (Exception $e) {
    finish(false, ["code" => null, "message" => "The file couldn't be stored - please try again"]);
}

$originalName = $bCMS->sanitizeString((string) $upload['name']);
$id = $DBLIB->insert("s3files", [
    "s3files_extension" => $extension,
    "s3files_path" => pathinfo($key, PATHINFO_DIRNAME),
    "s3files_meta_size" => $upload['size'],
    "s3files_meta_type" => $bCMS->sanitizeString($_POST['typeid']),
    "s3files_meta_subType" => is_numeric($_POST['subtype'] ?? null) ? $bCMS->sanitizeString($_POST['subtype']) : null,
    "users_userid" => $AUTH->data['users_userid'],
    "s3files_original_name" => $originalName,
    "s3files_filename" => pathinfo($key, PATHINFO_FILENAME),
    "s3files_name" => pathinfo($originalName, PATHINFO_FILENAME),
    "s3files_meta_public" => (isset($_POST['public']) and $_POST['public'] == 1) ? 1 : 0,
    "instances_id" => $AUTH->data['instance']['instances_id']
]);
if (!$id) finish(false, ["code" => null, "message" => "Error"]);
else finish(true, null, ["id" => $id, "resize" => false, "url" => $CONFIG['ROOTURL'] . '/api/file/?f=' . $id]);

/** @OA\Post(
 *     path="/s3files/upload.php",
 *     summary="Upload File",
 *     description="Upload a file through the AdamRMS server, for installations whose files go through it. Files can be up to 25MB.
",
 *     operationId="uploadFile",
 *     tags={"s3files"},
 *     @OA\Response(
 *         response="200",
 *         description="Success",
 *         @OA\MediaType(
 *             mediaType="application/json",
 *             @OA\Schema(
 *                 type="object",
 *                 @OA\Property(
 *                     property="result",
 *                     type="boolean",
 *                     description="Whether the request was successful",
 *                 ),
 *                 @OA\Property(
 *                     property="response",
 *                     type="object",
 *                     description="The new file's id and link",
 *                 ),
 *             ),
 *         ),
 *     ),
 *     @OA\Parameter(
 *         name="file",
 *         in="query",
 *         description="The file (multipart/form-data)",
 *         required="true",
 *         @OA\Schema(
 *             type="string",
 *             format="binary"),
 *         ),
 *     @OA\Parameter(
 *         name="typeid",
 *         in="query",
 *         description="File type ID",
 *         required="true",
 *         @OA\Schema(
 *             type="integer"),
 *         ),
 *     @OA\Parameter(
 *         name="subtype",
 *         in="query",
 *         description="ID of the record the file is attached to",
 *         required="false",
 *         @OA\Schema(
 *             type="integer"),
 *         ),
 *     @OA\Parameter(
 *         name="public",
 *         in="query",
 *         description="1 if the file is public",
 *         required="false",
 *         @OA\Schema(
 *             type="integer"),
 *         ),
 *     @OA\Parameter(
 *         name="type",
 *         in="query",
 *         description="Folder name for the file, e.g. ASSET-FILE",
 *         required="false",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 * )
 */
