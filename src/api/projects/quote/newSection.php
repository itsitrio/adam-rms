<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:EDIT:QUOTE_SECTIONS_AND_LINES")) die("404");
if (!isset($_POST['projects_id']) or !isset($_POST['projectsQuoteSections_name']) or strlen(trim($_POST['projectsQuoteSections_name'])) < 1) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);

$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("projects.projects_id", $_POST['projects_id']);
$project = $DBLIB->getone("projects", ["projects_id"]);
if (!$project) finish(false, ["message" => "Cannot find project"]);

//New sections go at the end of the quote
$DBLIB->where("projects_id", $project['projects_id']);
$DBLIB->where("projectsQuoteSections_deleted", 0);
$rank = $DBLIB->getValue("projectsQuoteSections", "MAX(projectsQuoteSections_rank)");

$section = $DBLIB->insert("projectsQuoteSections", [
    "projects_id" => $project['projects_id'],
    "projectsQuoteSections_name" => substr(trim($_POST['projectsQuoteSections_name']), 0, 255),
    "projectsQuoteSections_rank" => ($rank === null ? 0 : intval($rank) + 1),
]);
if (!$section) finish(false, ["message" => "Could not create section"]);
$bCMS->auditLog("INSERT", "projectsQuoteSections", $section, $AUTH->data['users_userid'], null, $project['projects_id']);
finish(true, null, ["projectsQuoteSections_id" => $section]);

/** @OA\Post(
 *     path="/projects/quote/newSection.php",
 *     summary="New Quote Section",
 *     description="Add a custom heading to a project's quote, which assets and note lines can be placed under
Requires Instance Permission PROJECTS:PROJECT_ASSETS:EDIT:QUOTE_SECTIONS_AND_LINES
",
 *     operationId="newQuoteSection",
 *     tags={"project_quote"},
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
 *         name="projectsQuoteSections_name",
 *         in="query",
 *         description="Heading shown on the quote",
 *         required="true",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 * )
 */
