<?php
require_once __DIR__ . '/../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:EDIT:QUOTE_SECTIONS_AND_LINES")) finish(false, ["code" => "AUTH-ERROR", "message" => "No auth for action"]);
if (!isset($_POST['projects_id']) or !isset($_POST['projects_publishedNames'])) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);

$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("projects.projects_id", $_POST['projects_id']);
$project = $DBLIB->getOne("projects", ["projects_id"]);
if (!$project) finish(false, ["code" => "NOT-FOUND", "message" => "Project not found"]);

$update = ["projects_publishedNames" => ($_POST['projects_publishedNames'] == 1 ? 1 : 0)];
$DBLIB->where("projects.projects_id", $project['projects_id']);
if (!$DBLIB->update("projects", $update)) finish(false);

$bCMS->auditLog("UPDATE-PUBLISHED-NAMES", "projects", json_encode($update), $AUTH->data['users_userid'], null, $project['projects_id']);
finish(true);

/** @OA\Post(
 *     path="/projects/changePublishedNames.php",
 *     summary="Show All Assets by their Published Name",
 *     description="Show every asset on a project's documents by its published line item name, where it has one, instead of what it really is. When this is off, only the lines set to are.
Requires Instance Permission PROJECTS:PROJECT_ASSETS:EDIT:QUOTE_SECTIONS_AND_LINES
",
 *     operationId="changePublishedNames",
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
 *         response="default",
 *         description="Error",
 *         @OA\MediaType(
 *             mediaType="application/json",
 *             @OA\Schema(ref="#/components/schemas/SimpleResponse"),
 *         ),
 *     ),
 *     @OA\Parameter(
 *         name="projects_id",
 *         in="query",
 *         description="Project ID",
 *         required="true",
 *         @OA\Schema(
 *             type="integer"),
 *         ),
 *     @OA\Parameter(
 *         name="projects_publishedNames",
 *         in="query",
 *         description="1 to show every asset by its published name, 0 to show only the lines set to",
 *         required="true",
 *         @OA\Schema(
 *             type="integer"),
 *         ),
 * )
 */
