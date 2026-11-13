<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:EDIT:QUOTE_SECTIONS_AND_LINES")) die("404");
if (!isset($_POST['projectsQuoteSections_id'])) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);

$DBLIB->where("projectsQuoteSections.projectsQuoteSections_id", $_POST['projectsQuoteSections_id']);
$DBLIB->where("projectsQuoteSections.projectsQuoteSections_deleted", 0);
$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->join("projects", "projectsQuoteSections.projects_id=projects.projects_id", "LEFT");
$section = $DBLIB->getone("projectsQuoteSections", ["projectsQuoteSections.projectsQuoteSections_id", "projectsQuoteSections.projects_id", "projectsQuoteSections.projectsQuoteSections_rank"]);
if (!$section) finish(false, ["message" => "Cannot find section"]);

if (isset($_POST['projectsQuoteSections_name'])) {
    if (strlen(trim($_POST['projectsQuoteSections_name'])) < 1) finish(false, ["message" => "A section needs a name"]);
    $DBLIB->where("projectsQuoteSections_id", $section['projectsQuoteSections_id']);
    if (!$DBLIB->update("projectsQuoteSections", ["projectsQuoteSections_name" => substr(trim($_POST['projectsQuoteSections_name']), 0, 255)])) finish(false, ["message" => "Could not rename section"]);
}

if (isset($_POST['move']) and in_array($_POST['move'], ["up", "down"])) {
    //Put the project's sections in their current order, swap this one with its neighbour, then renumber them all
    $DBLIB->where("projects_id", $section['projects_id']);
    $DBLIB->where("projectsQuoteSections_deleted", 0);
    $DBLIB->orderBy("projectsQuoteSections_rank", "ASC");
    $DBLIB->orderBy("projectsQuoteSections_id", "ASC");
    $order = array_column($DBLIB->get("projectsQuoteSections", null, ["projectsQuoteSections_id"]), "projectsQuoteSections_id");
    $position = array_search($section['projectsQuoteSections_id'], $order);
    $swapWith = ($_POST['move'] == "up" ? $position - 1 : $position + 1);
    if ($position !== false and isset($order[$swapWith])) {
        $order[$position] = $order[$swapWith];
        $order[$swapWith] = $section['projectsQuoteSections_id'];
        foreach ($order as $rank => $sectionId) {
            $DBLIB->where("projectsQuoteSections_id", $sectionId);
            $DBLIB->update("projectsQuoteSections", ["projectsQuoteSections_rank" => $rank]);
        }
    }
}

$bCMS->auditLog("UPDATE", "projectsQuoteSections", json_encode($_POST), $AUTH->data['users_userid'], null, $section['projects_id']);
finish(true);

/** @OA\Post(
 *     path="/projects/quote/editSection.php",
 *     summary="Edit Quote Section",
 *     description="Rename a quote section, or move it up or down the quote
Requires Instance Permission PROJECTS:PROJECT_ASSETS:EDIT:QUOTE_SECTIONS_AND_LINES
",
 *     operationId="editQuoteSection",
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
 *     @OA\Parameter(
 *         name="projectsQuoteSections_name",
 *         in="query",
 *         description="New heading",
 *         required="false",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 *     @OA\Parameter(
 *         name="move",
 *         in="query",
 *         description="up or down",
 *         required="false",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 * )
 */
