<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("MAINTENANCE_JOBS:EDIT:ASSET_BLOCKS") or !(isset($_POST['assets_id']) or isset($_POST['assetHolds_id']))) die("404");

$modes = [];
foreach (["assetHolds_assignMode", "assetHolds_dispatchMode"] as $field) {
    if (!isset($_POST[$field])) continue;
    if (!array_key_exists($_POST[$field], ASSET_HOLD_MODES)) finish(false, ["code" => "PARAM-ERROR", "message" => "Unknown hold mode"]);
    $modes[$field] = $_POST[$field];
}
if (isset($_POST['assetHolds_overridable'])) {
    if ($_POST['assetHolds_overridable'] != 1 && $_POST['assetHolds_overridable'] != 0) finish(false, ["code" => "PARAM-ERROR", "message" => "Overridable must be 1 or 0"]);
    $modes['assetHolds_overridable'] = intval($_POST['assetHolds_overridable']);
}
if (isset($_POST['assetHolds_reason'])) $modes['assetHolds_reason'] = mb_substr(strip_tags($_POST['assetHolds_reason']), 0, 2000);

if (isset($_POST['assetHolds_id'])) {
    $DBLIB->where("assetHolds_id", $_POST['assetHolds_id']);
    $DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
    $DBLIB->where("assetHolds_deleted", 0);
    $hold = $DBLIB->getOne("assetHolds", ["assetHolds_id", "assets_id"]);
    if (!$hold) finish(false, ["code" => "NOT-FOUND", "message" => "Hold not found"]);
    if (count($modes) < 1) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);
    $DBLIB->where("assetHolds_id", $hold['assetHolds_id']);
    if (!$DBLIB->update("assetHolds", $modes) and $DBLIB->getLastErrno() != 0) finish(false);
    $bCMS->auditLog("EDIT-HOLD", "assetHolds", json_encode($modes), $AUTH->data['users_userid'], null, null, $hold['assetHolds_id']);
    finish(true, null, ["assetHolds_id" => $hold['assetHolds_id']]);
}

//A new hold, on an asset the business owns
$DBLIB->where("assets.assets_id", $_POST['assets_id']);
$DBLIB->where("assets.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("assets.assets_deleted", 0);
$asset = $DBLIB->getOne("assets", ["assets_id"]);
if (!$asset) finish(false, ["code" => "NOT-FOUND", "message" => "Asset not found"]);
$id = $DBLIB->insert("assetHolds", $modes + [
    "instances_id" => $AUTH->data['instance']['instances_id'],
    "assets_id" => $asset['assets_id'],
    "users_userid" => $AUTH->data['users_userid'],
]);
if (!$id) finish(false);
$bCMS->auditLog("NEW-HOLD", "assetHolds", json_encode($modes), $AUTH->data['users_userid'], null, null, $id);
finish(true, null, ["assetHolds_id" => $id]);

/** @OA\Post(
 *     path="/assets/holds/save.php",
 *     summary="Put an Asset on Hold, or Change a Hold",
 *     description="A hold on an asset has a rule, of allow, warn or block, for adding the asset to a project (assign) and for sending it out (dispatch). Send assets_id to put an asset on hold, or assetHolds_id to change one
Requires Instance Permission MAINTENANCE_JOBS:EDIT:ASSET_BLOCKS
",
 *     operationId="saveAssetHold",
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
 *         name="assets_id",
 *         in="query",
 *         description="Asset ID, to put it on hold",
 *         required="false",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 *     @OA\Parameter(
 *         name="assetHolds_id",
 *         in="query",
 *         description="Hold ID, to change it",
 *         required="false",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 *     @OA\Parameter(
 *         name="assetHolds_assignMode",
 *         in="query",
 *         description="ALLOW, WARN or BLOCK adding the asset to a project",
 *         required="false",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 *     @OA\Parameter(
 *         name="assetHolds_dispatchMode",
 *         in="query",
 *         description="ALLOW, WARN or BLOCK sending the asset out",
 *         required="false",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 *     @OA\Parameter(
 *         name="assetHolds_overridable",
 *         in="query",
 *         description="1 if whoever is sending the asset out can sign off a block, 0 if it can't be overridden",
 *         required="false",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 *     @OA\Parameter(
 *         name="assetHolds_reason",
 *         in="query",
 *         description="Why the asset is on hold",
 *         required="false",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 * )
 */
