<?php
require_once __DIR__ . '/../../apiHeadSecure.php';
require_once __DIR__ . '/../../../common/libs/reports/liabilityRequests.php';

//Records that an asset was paid for with a liability (liabilities_id), or that it wasn't (liabilities_id empty)
if (!$AUTH->instancePermissionCheck("FINANCE:LIABILITIES:EDIT")) finish(false, ["code" => "AUTH-ERROR", "message" => "No auth for action"]);
if (!isset($_POST['assets_id'])) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);
$liabilityId = (isset($_POST['liabilities_id']) and $_POST['liabilities_id'] !== "") ? liabilityFind($_POST['liabilities_id'])['liabilities_id'] : null;

$DBLIB->where("assets_id", intval($_POST['assets_id']));
$DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("assets_deleted", 0);
$asset = $DBLIB->getOne("assets", ["assets_id", "assets_tag"]);
if (!$asset) finish(false, ["code" => "PARAM-ERROR", "message" => "Could not find the asset"]);
$DBLIB->where("assets_id", $asset['assets_id']);
if (!$DBLIB->update("assets", ["liabilities_id" => $liabilityId])) finish(false, ["code" => "UPDATE-FAIL", "message" => "Could not save the asset"]);
$bCMS->auditLog("CHANGE-LIABILITY", "assets", "Paid for with liability " . ($liabilityId ?? "none"), $AUTH->data['users_userid'], null, null, $asset['assets_id']);
finish(true);

/** @OA\Post(
 *     path="/reports/liabilities/asset.php",
 *     summary="Link Asset to Liability",
 *     description="Record which loan, finance or credit paid for an asset
Requires Instance Permission FINANCE:LIABILITIES:EDIT
",
 *     operationId="linkAssetLiability",
 *     tags={"reports"},
 *     @OA\Response(response="200", description="Success", @OA\MediaType(mediaType="application/json", @OA\Schema(type="object", @OA\Property(property="result", type="boolean", description="Whether the request was successful")))),
 *     @OA\Parameter(name="assets_id", in="query", description="The asset", required="true", @OA\Schema(type="number")),
 *     @OA\Parameter(name="liabilities_id", in="query", description="The liability that paid for it - empty if none did", required="false", @OA\Schema(type="number")),
 * )
 */
