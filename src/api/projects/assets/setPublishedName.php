<?php
/**
 * API
 * \projects\assets\setPublishedName.php
 * Shows assignments on a project's documents by their asset's published line item name, instead of what they
 * really are - or back by what they really are. Assets without a published name are shown as they are either way.
 *
 * Arguments:
 *  - assetsAssignments: array of assignment IDs
 *  - assetsAssignments_publishedName: 1 to show them by their published name, 0 to show what they are
 */

require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:EDIT:QUOTE_SECTIONS_AND_LINES")) finish(false, ["code" => "AUTH-ERROR", "message" => "No auth for action"]);
if (!isset($_POST['assetsAssignments']) or !is_array($_POST['assetsAssignments']) or count($_POST['assetsAssignments']) < 1) finish(false, ["code" => "PARAM-ERROR", "message" => "No assets selected"]);
if (!isset($_POST['assetsAssignments_publishedName'])) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);

$DBLIB->where("assetsAssignments.assetsAssignments_id", $_POST['assetsAssignments'], "IN");
$DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->join("projects", "assetsAssignments.projects_id=projects.projects_id", "LEFT");
$assignments = $DBLIB->get("assetsAssignments", null, ["assetsAssignments.assetsAssignments_id", "assetsAssignments.projects_id"]);
if (count($assignments) != count(array_unique($_POST['assetsAssignments']))) finish(false, ["message" => "Cannot find assignment"]);

$update = ["assetsAssignments_publishedName" => ($_POST['assetsAssignments_publishedName'] == 1 ? 1 : 0)];
foreach ($assignments as $assignment) {
    $DBLIB->where("assetsAssignments_id", $assignment['assetsAssignments_id']);
    if (!$DBLIB->update("assetsAssignments", $update, 1)) finish(false, ["message" => "Could not update assignment"]);
    $bCMS->auditLog("EDIT-ASSIGNMENT-PUBLISHED-NAME", "assetsAssignments", json_encode($update), $AUTH->data['users_userid'], null, $assignment['projects_id']);
}
finish(true);

/** @OA\Post(
 *     path="/projects/assets/setPublishedName.php",
 *     summary="Show Assets by their Published Name",
 *     description="Show asset assignments on a project's documents by their asset's published line item name instead of what they really are, or back by what they really are
Requires Instance Permission PROJECTS:PROJECT_ASSETS:EDIT:QUOTE_SECTIONS_AND_LINES
",
 *     operationId="setAssetAssignmentPublishedName",
 *     tags={"project_assets"},
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
 *         name="assetsAssignments",
 *         in="query",
 *         description="Asset Assignment IDs",
 *         required="true",
 *         @OA\Schema(
 *             type="array"),
 *         ),
 *     @OA\Parameter(
 *         name="assetsAssignments_publishedName",
 *         in="query",
 *         description="1 to show them by their published name, 0 to show what they are",
 *         required="true",
 *         @OA\Schema(
 *             type="integer"),
 *         ),
 * )
 */
