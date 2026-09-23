<?php
/**
 * API
 * \projects\quote\allocate.php
 * Places assignments' units under the project's quote sections
 *
 * Either:
 *  - assetsAssignments (array) and projectsQuoteSections_id: put every unit of each assignment in that section,
 *    or back under its asset category if projectsQuoteSections_id is -1
 *  - assetsAssignments_id and allocations (object of projectsQuoteSections_id => number of units): split one
 *    assignment across sections. Units not given a section stay under the asset category
 */
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:EDIT:QUOTE_SECTIONS_AND_LINES")) die("404");

if (isset($_POST['assetsAssignments_id']) and isset($_POST['allocations']) and is_array($_POST['allocations'])) {
    $assignmentIds = [$_POST['assetsAssignments_id']];
    $wanted = [];
    foreach ($_POST['allocations'] as $sectionId => $quantity) {
        if (intval($quantity) < 0) finish(false, ["message" => "Quantities can't be negative"]);
        $wanted[intval($sectionId)] = intval($quantity);
    }
} elseif (isset($_POST['assetsAssignments']) and is_array($_POST['assetsAssignments']) and isset($_POST['projectsQuoteSections_id'])) {
    $assignmentIds = $_POST['assetsAssignments'];
    $wanted = (intval($_POST['projectsQuoteSections_id']) == -1 ? [] : [intval($_POST['projectsQuoteSections_id']) => null]); //null means all of the assignment's units
} else finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);
if (count($assignmentIds) < 1) finish(false, ["code" => "PARAM-ERROR", "message" => "No assets selected"]);

$DBLIB->where("assetsAssignments.assetsAssignments_id", $assignmentIds, "IN");
$DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->join("projects", "assetsAssignments.projects_id=projects.projects_id", "LEFT");
$assignments = $DBLIB->get("assetsAssignments", null, ["assetsAssignments.assetsAssignments_id", "assetsAssignments.assetsAssignments_quantity", "assetsAssignments.projects_id"]);
if (count($assignments) != count(array_unique($assignmentIds))) finish(false, ["message" => "Cannot find assignment"]);
$projectIds = array_unique(array_column($assignments, "projects_id"));
if (count($projectIds) != 1) finish(false, ["message" => "Assets must all be on the same project"]);

//Sections have to belong to the assignments' project
if (count($wanted) > 0) {
    $DBLIB->where("projectsQuoteSections_id", array_keys($wanted), "IN");
    $DBLIB->where("projects_id", $projectIds[0]);
    $DBLIB->where("projectsQuoteSections_deleted", 0);
    if ($DBLIB->getValue("projectsQuoteSections", "COUNT(*)") != count($wanted)) finish(false, ["message" => "Cannot find section"]);
}

foreach ($assignments as $assignment) {
    $quantity = (intval($assignment['assetsAssignments_quantity']) > 0 ? intval($assignment['assetsAssignments_quantity']) : 1);
    if (array_sum($wanted) > $quantity) finish(false, ["message" => "Only " . $quantity . " unit" . ($quantity != 1 ? "s are" : " is") . " assigned to split between sections"]);

    //Clear what's there, then set what's wanted. A quantity of 0 means the assignment has no units in that section
    $DBLIB->where("assetsAssignments_id", $assignment['assetsAssignments_id']);
    $DBLIB->update("projectsQuoteAllocations", ["projectsQuoteAllocations_quantity" => 0]);
    foreach ($wanted as $sectionId => $sectionQuantity) {
        if ($sectionQuantity === 0) continue;
        $DBLIB->onDuplicate(["projectsQuoteAllocations_quantity"]);
        $allocated = $DBLIB->insert("projectsQuoteAllocations", [
            "assetsAssignments_id" => $assignment['assetsAssignments_id'],
            "projectsQuoteSections_id" => $sectionId,
            "projectsQuoteAllocations_quantity" => ($sectionQuantity === null ? $quantity : $sectionQuantity),
        ]);
        if (!$allocated) finish(false, ["message" => "Could not place asset in section"]);
    }
    $bCMS->auditLog("UPDATE-QUOTE-SECTION", "assetsAssignments", json_encode($wanted), $AUTH->data['users_userid'], null, $assignment['projects_id']);
}
finish(true);

/** @OA\Post(
 *     path="/projects/quote/allocate.php",
 *     summary="Place Assets in Quote Sections",
 *     description="Place asset assignments under a project's quote sections, or split one assignment's units between several
Requires Instance Permission PROJECTS:PROJECT_ASSETS:EDIT:QUOTE_SECTIONS_AND_LINES
",
 *     operationId="allocateQuoteSection",
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
 *         name="assetsAssignments",
 *         in="query",
 *         description="Asset Assignment IDs to place all the units of",
 *         required="false",
 *         @OA\Schema(
 *             type="array",
 *             @OA\Items(type="number")),
 *         ),
 *     @OA\Parameter(
 *         name="projectsQuoteSections_id",
 *         in="query",
 *         description="Section to place them in, or -1 to show them under their asset category",
 *         required="false",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 *     @OA\Parameter(
 *         name="assetsAssignments_id",
 *         in="query",
 *         description="Asset Assignment ID to split between sections",
 *         required="false",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 *     @OA\Parameter(
 *         name="allocations",
 *         in="query",
 *         description="Number of units to show in each section, keyed by section ID",
 *         required="false",
 *         @OA\Schema(
 *             type="object"),
 *         ),
 * )
 */
