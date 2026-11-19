<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("CLIENTS:EDIT") or !isset($_POST['clientsBrands_id']) or !isset($_POST['clients_id']) or !isset($_POST['clientsBrands_name'])) die("404");
if (strlen(trim($_POST['clientsBrands_name'])) < 1) finish(false, ["code" => "PARAM-ERROR", "message" => "Please give the brand a name"]);

//The brand must belong to this client, in this business
$DBLIB->where("clientsBrands.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("clientsBrands.clients_id", $_POST['clients_id']);
$DBLIB->where("clientsBrands.clientsBrands_id", $_POST['clientsBrands_id']);
$DBLIB->where("clientsBrands.clientsBrands_deleted", 0);
$brand = $DBLIB->getone("clientsBrands", ["clientsBrands_id"]);
if (!$brand) finish(false, ["code" => "PARAM-ERROR", "message" => "Brand not found"]);

$update = [
    "clientsBrands_name" => trim($_POST['clientsBrands_name']),
    "clientsBrands_notes" => (isset($_POST['clientsBrands_notes']) and strlen($_POST['clientsBrands_notes']) > 0) ? $_POST['clientsBrands_notes'] : null,
];
$DBLIB->where("clientsBrands.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("clientsBrands.clientsBrands_id", $brand['clientsBrands_id']);
if (!$DBLIB->update("clientsBrands", $update)) finish(false);

$bCMS->auditLog("EDIT", "clientsBrands", json_encode($update), $AUTH->data['users_userid'], null, null, $brand['clientsBrands_id']);
finish(true);

/** @OA\Post(
 *     path="/clients/brands/edit.php",
 *     summary="Edit Client Brand",
 *     description="Rename an event brand, or change its notes
Requires Instance Permission CLIENTS:EDIT",
 *     operationId="editClientBrand",
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
 *     @OA\Parameter(
 *         name="clientsBrands_name",
 *         in="query",
 *         description="Brand name",
 *         required="true",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 *     @OA\Parameter(
 *         name="clientsBrands_notes",
 *         in="query",
 *         description="Notes",
 *         required="false",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 * )
 */
