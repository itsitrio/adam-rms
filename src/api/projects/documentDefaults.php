<?php
require_once __DIR__ . '/../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("PROJECTS:VIEW")) finish(false, ["code" => "AUTH-ERROR", "message" => "No auth for action"]);
//Kept with the user's membership of the business, which a server admin looking in on a business doesn't have
if (!isset($AUTH->data['instance']['userInstances_id'])) finish(false, ["code" => "PARAM-ERROR", "message" => "You are not a member of this business, so can't save your own defaults for it"]);

$defaults = documentDefaultsFromInput(documentUserDefaults(), $_POST);
if ($defaults === false) finish(false, ["code" => "PARAM-ERROR", "message" => "Document type not recognised"]);

$DBLIB->where("userInstances_id", $AUTH->data['instance']['userInstances_id']);
$DBLIB->where("users_userid", $AUTH->data['users_userid']);
if (!$DBLIB->update("userInstances", ["userInstances_documentDefaults" => json_encode($defaults)])) finish(false, ["code" => "UPDATE-FAIL", "message" => "Could not save defaults"]);
finish(true);

/** @OA\Post(
 *     path="/projects/documentDefaults.php", 
 *     summary="Save My Document Defaults", 
 *     description="Save the options the current user wants ticked to start with when generating one type of document in the current business. They go on top of the business's own defaults.  
Requires Instance Permission PROJECTS:VIEW
", 
 *     operationId="documentDefaults", 
 *     tags={"projects"}, 
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
 *         name="defaultsType",
 *         in="query",
 *         description="invoice, quote or deliveryNote",
 *         required="true", 
 *         @OA\Schema(
 *             type="string"), 
 *         ), 
 *     @OA\Parameter(
 *         name="defaults",
 *         in="query",
 *         description="The options to tick by default when generating that type of document, as option name => true/false",
 *         required="false", 
 *         @OA\Schema(
 *             type="object"), 
 *         ), 
 *     @OA\Parameter(
 *         name="defaultsReset",
 *         in="query",
 *         description="Forget the defaults saved for that type of document first",
 *         required="false", 
 *         @OA\Schema(
 *             type="boolean"), 
 *         ), 
 * )
 */
