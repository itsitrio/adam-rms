<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:EDIT:QUOTE_SECTIONS_AND_LINES")) die("404");
if (!isset($_POST['projectsQuoteSections_id'])) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);

$DBLIB->where("projectsQuoteSections.projectsQuoteSections_id", $_POST['projectsQuoteSections_id']);
$DBLIB->where("projectsQuoteSections.projectsQuoteSections_deleted", 0);
$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->join("projects", "projectsQuoteSections.projects_id=projects.projects_id", "LEFT");
$section = $DBLIB->getone("projectsQuoteSections", ["projectsQuoteSections.projectsQuoteSections_id", "projectsQuoteSections.projects_id"]);
if (!$section) finish(false, ["message" => "Cannot find section"]);

$DBLIB->where("projectsQuoteSections_id", $section['projectsQuoteSections_id']);
if (!$DBLIB->update("projectsQuoteSections", ["projectsQuoteSections_deleted" => 1])) finish(false, ["message" => "Could not delete section"]);

//Its note lines go to the end of the quote. Units allocated to it go back under their categories, as only sections that haven't been deleted are shown
$DBLIB->where("projectsQuoteSections_id", $section['projectsQuoteSections_id']);
$DBLIB->update("projectsQuoteLines", ["projectsQuoteSections_id" => null]);

$bCMS->auditLog("DELETE", "projectsQuoteSections", $section['projectsQuoteSections_id'], $AUTH->data['users_userid'], null, $section['projects_id']);
finish(true);

/** @OA\Post(
 *     path="/projects/quote/deleteSection.php",
 *     summary="Delete Quote Section",
 *     description="Remove a quote section. Its assets go back under their asset categories, and its note lines to the end of the quote
Requires Instance Permission PROJECTS:PROJECT_ASSETS:EDIT:QUOTE_SECTIONS_AND_LINES
",
 *     operationId="deleteQuoteSection",
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
 *         name="projectsQuoteSections_id",
 *         in="query",
 *         description="Quote Section ID",
 *         required="true",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 * )
 */
