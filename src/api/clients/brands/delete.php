<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("CLIENTS:EDIT") or !isset($_POST['clientsBrands_id']) or !isset($_POST['clients_id'])) die("404");

//The brand must belong to this client, in this business
$DBLIB->where("clientsBrands.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("clientsBrands.clients_id", $_POST['clients_id']);
$DBLIB->where("clientsBrands.clientsBrands_id", $_POST['clientsBrands_id']);
$DBLIB->where("clientsBrands.clientsBrands_deleted", 0);
$brand = $DBLIB->getone("clientsBrands", ["clientsBrands_id", "clientsBrands_name"]);
if (!$brand) finish(false, ["code" => "PARAM-ERROR", "message" => "Brand not found"]);

//Projects keep pointing at the brand, but are counted as having no brand while it's deleted
$DBLIB->where("clientsBrands.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("clientsBrands.clientsBrands_id", $brand['clientsBrands_id']);
if (!$DBLIB->update("clientsBrands", ["clientsBrands_deleted" => 1])) finish(false);

$bCMS->auditLog("DELETE", "clientsBrands", "Deleted the brand " . $brand['clientsBrands_name'], $AUTH->data['users_userid'], null, null, $brand['clientsBrands_id']);
finish(true);

/** @OA\Post(
 *     path="/clients/brands/delete.php",
 *     summary="Delete Client Brand",
 *     description="Delete an event brand. Its projects are then counted as having no brand.
Requires Instance Permission CLIENTS:EDIT",
 *     operationId="deleteClientBrand",
 *     tags={"clients"},
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
 *     @OA\Response(
 *         response="404",
 *         description="Permission Error",
 *     ),
 *     @OA\Parameter(
 *         name="clientsBrands_id",
 *         in="query",
 *         description="Brand ID",
 *         required="true",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 *     @OA\Parameter(
 *         name="clients_id",
 *         in="query",
 *         description="The client the brand belongs to",
 *         required="true",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 * )
 */
