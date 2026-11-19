<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("CLIENTS:VIEW") or !isset($_POST['clients_id'])) die("404");

$DBLIB->where("clients.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("clients.clients_deleted", 0);
$DBLIB->where("clients.clients_id", $_POST['clients_id']);
$client = $DBLIB->getone("clients", ["clients_id"]);
if (!$client) finish(false, ["code" => "PARAM-ERROR", "message" => "Client not found"]);

$DBLIB->where("clientsBrands.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("clientsBrands.clients_id", $client['clients_id']);
$DBLIB->where("clientsBrands.clientsBrands_deleted", 0);
$DBLIB->orderBy("clientsBrands.clientsBrands_name", "ASC");
$brands = $DBLIB->get("clientsBrands", null, ["clientsBrands_id", "clients_id", "clientsBrands_name", "clientsBrands_notes"]);
finish(true, null, ["brands" => $brands]);

/** @OA\Post(
 *     path="/clients/brands/list.php",
 *     summary="List Client Brands",
 *     description="List the event brands of a client
Requires Instance Permission CLIENTS:VIEW",
 *     operationId="listClientBrands",
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
 *                     description="The client's brands, by name",
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
 * )
 */
