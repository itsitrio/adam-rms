<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("MAINTENANCE_JOBS:EDIT:ASSET_BLOCKS") or !isset($_POST['assetHolds_id'])) die("404");

$DBLIB->where("assetHolds_id", $_POST['assetHolds_id']);
$DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("assetHolds_deleted", 0);
$hold = $DBLIB->getOne("assetHolds", ["assetHolds_id"]);
if (!$hold) finish(false, ["code" => "NOT-FOUND", "message" => "Hold not found"]);

$DBLIB->where("assetHolds_id", $hold['assetHolds_id']);
if (!$DBLIB->update("assetHolds", ["assetHolds_deleted" => 1])) finish(false);
$bCMS->auditLog("DELETE-HOLD", "assetHolds", "Took the asset off hold", $AUTH->data['users_userid'], null, null, $hold['assetHolds_id']);
finish(true);

/** @OA\Post(
 *     path="/assets/holds/delete.php",
 *     summary="Take an Asset off Hold",
 *     description="Remove a hold from an asset
Requires Instance Permission MAINTENANCE_JOBS:EDIT:ASSET_BLOCKS
",
 *     operationId="deleteAssetHold",
 *     tags={"assets"},
 *     @OA\Response(
 *         response="200",
 *         description="Success",
 *         @OA\MediaType(
 *             mediaType="application/json",
 *             @OA\Schema(ref="#/components/schemas/SimpleResponse"),
 *         ),
 *     ),
 *     @OA\Response(
 *         response="404",
 *         description="Permission Error",
 *     ),
 *     @OA\Parameter(
 *         name="assetHolds_id",
 *         in="query",
 *         description="Hold ID",
 *         required="true",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 * )
 */
