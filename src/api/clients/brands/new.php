<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("CLIENTS:EDIT") or !isset($_POST['clients_id']) or !isset($_POST['clientsBrands_name'])) die("404");
if (strlen(trim($_POST['clientsBrands_name'])) < 1) finish(false, ["code" => "PARAM-ERROR", "message" => "Please give the brand a name"]);

$DBLIB->where("clients.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("clients.clients_deleted", 0);
$DBLIB->where("clients.clients_id", $_POST['clients_id']);
$client = $DBLIB->getone("clients", ["clients_id"]);
if (!$client) finish(false, ["code" => "PARAM-ERROR", "message" => "Client not found"]);

$brand = $DBLIB->insert("clientsBrands", [
    "clients_id" => $client['clients_id'],
    "instances_id" => $AUTH->data['instance']['instances_id'],
    "clientsBrands_name" => trim($_POST['clientsBrands_name']),
    "clientsBrands_notes" => (isset($_POST['clientsBrands_notes']) and strlen($_POST['clientsBrands_notes']) > 0) ? $_POST['clientsBrands_notes'] : null,
]);
if (!$brand) finish(false, ["code" => "CREATE-BRAND-FAIL", "message" => "Could not create new brand"]);

$bCMS->auditLog("INSERT", "clientsBrands", null, $AUTH->data['users_userid'], null, null, $brand);
finish(true, null, ["clientsBrands_id" => $brand]);

/** @OA\Post(
 *     path="/clients/brands/new.php",
 *     summary="Create Client Brand",
 *     description="Create an event brand for a client
Requires Instance Permission CLIENTS:EDIT",
 *     operationId="createClientBrand",
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
 *                 @OA\Property(
 *                     property="response",
 *                     type="object",
 *                     description="The new brand's clientsBrands_id",
 *                 ),
 *             ),
 *         ),
 *     ),
 *     @OA\Response(
 *         response="404",
 *         description="Permission Error",
 *     ),
 *     @OA\Parameter(
 *         name="clients_id",
 *         in="query",
 *         description="Client ID",
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
