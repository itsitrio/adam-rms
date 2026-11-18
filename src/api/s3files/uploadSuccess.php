<?php
require_once __DIR__ . '/../apiHeadSecure.php';
if ($CONFIG['FILES_ENABLED'] !== "Enabled") {
    finish(false, ["code" => null, "message" => "File uploads are disabled"]);
}
if (!$bCMS->s3SubTypeIsInInstance($_POST['typeid'], $_POST['subtype'] ?? null)) finish(false, ["code" => null, "message" => "Record not found"]);

// Only a key generateSignatureUppy.php gave this user, for this business, can be registered, so a file can't point at another business's object
$key = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
$pending = $_SESSION['s3UploadKeys'][$key] ?? null;
if (!is_array($pending) or $pending['users_userid'] != $AUTH->data['users_userid'] or $pending['instances_id'] != $AUTH->data['instance']['instances_id'] or $pending['expires'] < time()) {
    finish(false, ["code" => "PARAM-ERROR", "message" => "Upload not recognised"]);
}

// Ask S3 how big the file is, rather than trusting the browser
$s3 = new Aws\S3\S3Client([
    'version' => 'latest',
    'region' => $CONFIGCLASS->get('AWS_S3_REGION'),
    'endpoint' => $CONFIGCLASS->get('AWS_S3_SERVER_ENDPOINT'),
    'use_path_style_endpoint' => $CONFIGCLASS->get('AWS_S3_ENDPOINT_PATHSTYLE') === 'Enabled',
    'credentials' => array(
        'key' => $CONFIGCLASS->get('AWS_S3_KEY'),
        'secret' => $CONFIGCLASS->get('AWS_S3_SECRET'),
    )
]);
try {
    $size = (int) $s3->headObject(['Bucket' => $CONFIGCLASS->get('AWS_S3_BUCKET'), 'Key' => $key])['ContentLength'];
} catch (Exception $e) {
    finish(false, ["code" => null, "message" => "File not found"]);
}
$DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
$storageCapacity = $DBLIB->getvalue("instances", "instances_storageLimit");
if ($storageCapacity > 0 and $bCMS->s3StorageUsed($AUTH->data['instance']['instances_id']) + $size > $storageCapacity) {
    unset($_SESSION['s3UploadKeys'][$key]);
    try {
        $s3->deleteObject(['Bucket' => $CONFIGCLASS->get('AWS_S3_BUCKET'), 'Key' => $key]);
    } catch (Exception $e) {
        //The file is left in the bucket, but isn't registered so doesn't count towards the business's storage
    }
    finish(false, ["code" => null, "message" => "Storage limit reached"]);
}

$fileData = [
    "s3files_extension" => pathinfo($key, PATHINFO_EXTENSION),
    "s3files_path" => pathinfo($key, PATHINFO_DIRNAME),
    "s3files_meta_size" => $size,
    "s3files_meta_type" => $bCMS->sanitizeString($_POST['typeid']),
    "s3files_meta_subType" => is_numeric($_POST['subtype']) ? $bCMS->sanitizeString($_POST['subtype']) : null,
    "users_userid" => $AUTH->data['users_userid'],
    "s3files_original_name" => $bCMS->sanitizeString($_POST['originalName']),
    "s3files_filename" => pathinfo($key, PATHINFO_FILENAME),
    "s3files_name" => pathinfo($bCMS->sanitizeString($_POST['originalName']), PATHINFO_FILENAME),
    "s3files_meta_public" => $bCMS->sanitizeString($_POST['public']),
    "instances_id" => $AUTH->data['instance']['instances_id']
];
$id = $DBLIB->insert("s3files",$fileData);
echo $DBLIB->getLastError();
if (!$id) finish(false, ["code" => null, "message" => "Error"]);
unset($_SESSION['s3UploadKeys'][$key]); //Each key is registered once
finish(true, null, ["id" => $id, "resize" => false,"url" => $CONFIG['ROOTURL'] . '/api/file/?f=' . $id]);

/** @OA\Post(
 *     path="/s3files/uploadSuccess.php", 
 *     summary="Upload Success", 
 *     description="Upload a file to S3
", 
 *     operationId="uploadSuccess", 
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
 *             ),
 *         ),
 *     ), 
 *     @OA\Parameter(
 *         name="name",
 *         in="query",
 *         description="The key generateSignatureUppy.php returned",
 *         required="true", 
 *         @OA\Schema(
 *             type="string"), 
 *         ), 
 *     @OA\Parameter(
 *         name="size",
 *         in="query",
 *         description="File Size (ignored - the size is read from S3)",
 *         required="false", 
 *         @OA\Schema(
 *             type="number"), 
 *         ), 
 *     @OA\Parameter(
 *         name="typeid",
 *         in="query",
 *         description="File Type ID",
 *         required="true", 
 *         @OA\Schema(
 *             type="number"), 
 *         ), 
 *     @OA\Parameter(
 *         name="subtype",
 *         in="query",
 *         description="File Subtype",
 *         required="false", 
 *         @OA\Schema(
 *             type="number"), 
 *         ), 
 *     @OA\Parameter(
 *         name="orignalName",
 *         in="query",
 *         description="Original File Name",
 *         required="true", 
 *         @OA\Schema(
 *             type="string"), 
 *         ), 
 *     @OA\Parameter(
 *         name="public",
 *         in="query",
 *         description="Public File",
 *         required="true", 
 *         @OA\Schema(
 *             type="boolean"), 
 *         ), 
 * )
 */