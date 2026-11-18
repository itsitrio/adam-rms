<?php
require_once __DIR__ . '/../../apiHeadSecure.php';
require_once __DIR__ . '/../../../common/libs/reports/liabilityRequests.php';

//Deletes a liability - the assets bought with it are left as they are, just no longer linked to it
if (!$AUTH->instancePermissionCheck("FINANCE:LIABILITIES:EDIT")) finish(false, ["code" => "AUTH-ERROR", "message" => "No auth for action"]);
if (!isset($_POST['liabilities_id'])) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);
$liability = liabilityFind($_POST['liabilities_id']);
$DBLIB->where("liabilities_id", $liability['liabilities_id']);
if (!$DBLIB->update("liabilities", ["liabilities_deleted" => 1])) finish(false, ["code" => "UPDATE-FAIL", "message" => "Could not delete the liability"]);
$DBLIB->where("liabilities_id", $liability['liabilities_id']);
$DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->update("assets", ["liabilities_id" => null]);
$bCMS->auditLog("DELETE", "liabilities", "Deleted " . $liability['liabilities_name'], $AUTH->data['users_userid'], null, null, $liability['liabilities_id']);
finish(true);

/** @OA\Post(
 *     path="/reports/liabilities/delete.php",
 *     summary="Delete Liability",
 *     description="Delete a loan, finance or credit the business owes
Requires Instance Permission FINANCE:LIABILITIES:EDIT
",
 *     operationId="deleteLiability",
 *     tags={"reports"},
 *     @OA\Response(response="200", description="Success", @OA\MediaType(mediaType="application/json", @OA\Schema(type="object", @OA\Property(property="result", type="boolean", description="Whether the request was successful")))),
 *     @OA\Parameter(name="liabilities_id", in="query", description="The liability", required="true", @OA\Schema(type="number")),
 * )
 */
