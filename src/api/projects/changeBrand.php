<?php
require_once __DIR__ . '/../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("PROJECTS:EDIT:CLIENT") or !isset($_POST['projects_id'])) die("404");

$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("projects.projects_id", $_POST['projects_id']);
$project = $DBLIB->getone("projects", ["projects_id", "clients_id"]);
if (!$project) die("404");

//An empty clientsBrands_id removes the project's brand. Otherwise the brand must belong to the project's own client, in this business
$brand = null;
if (isset($_POST['clientsBrands_id']) and strlen($_POST['clientsBrands_id']) > 0) {
    if ($project['clients_id'] === null) finish(false, ["code" => "PARAM-ERROR", "message" => "Set the project's client first"]);
    $DBLIB->where("clientsBrands.instances_id", $AUTH->data['instance']['instances_id']);
    $DBLIB->where("clientsBrands.clients_id", $project['clients_id']);
    $DBLIB->where("clientsBrands.clientsBrands_id", $_POST['clientsBrands_id']);
    $DBLIB->where("clientsBrands.clientsBrands_deleted", 0);
    $brand = $DBLIB->getone("clientsBrands", ["clientsBrands_id", "clientsBrands_name"]);
    if (!$brand) finish(false, ["code" => "PARAM-ERROR", "message" => "Brand not found for this project's client"]);
}

$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_id", $project['projects_id']);
if (!$DBLIB->update("projects", ["projects.clientsBrands_id" => ($brand ? $brand['clientsBrands_id'] : null)])) finish(false);

$bCMS->auditLog("CHANGE-BRAND", "projects", ($brand ? "Set the brand to " . $brand['clientsBrands_name'] : "Removed the brand"), $AUTH->data['users_userid'], null, $project['projects_id']);
finish(true);

/** @OA\Post(
 *     path="/projects/changeBrand.php",
 *     summary="Change Brand",
 *     description="Tag a project with one of its client's event brands, or remove its brand
Requires Instance Permission PROJECTS:EDIT:CLIENT
",
 *     operationId="changeBrand",
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
 *     @OA\Response(
 *         response="404",
 *         description="Permission Error",
 *     ),
 *     @OA\Parameter(
 *         name="projects_id",
 *         in="query",
 *         description="Project ID",
 *         required="true",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 *     @OA\Parameter(
 *         name="clientsBrands_id",
 *         in="query",
 *         description="Brand ID, which must belong to the project's client. Empty to remove the brand.",
 *         required="false",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 * )
 */
