<?php
require_once __DIR__ . '/../apiHeadSecure.php';


if (!$AUTH->instancePermissionCheck("PROJECTS:EDIT:STATUS") or !isset($_POST['projects_id'])) die("404");

$DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
$projectsStatuses = $DBLIB->get("projectsStatuses");
$projectsStatusesWithKeys = [];
foreach ($projectsStatuses as $projectStatus) {
    $projectsStatusesWithKeys[$projectStatus['projectsStatuses_id']] = $projectStatus;
}

function changeStatus($projectID, $status) {
    global $DBLIB, $AUTH, $bCMS, $projectsStatusesWithKeys;
    $DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
    $DBLIB->where("projects.projects_deleted", 0);
    $DBLIB->where("projects.projects_id", $projectID);
    $project = $DBLIB->getone("projects", ["projects_id", "projectsStatuses_id","projects_dates_deliver_start","projects_dates_deliver_end"]);
    if (!$project) finish(false);

    $thisStatus = $projectsStatusesWithKeys[$project['projectsStatuses_id']];
    $newStatus = $projectsStatusesWithKeys[$status];
    if (!$newStatus) finish(false, ["code"=>"UNKNOWN", "message"=> "Status not found"]);

    if ($newStatus["projectsStatuses_assetsReleased"] == 0) {
        //We're taking the project from a state where its assets had been released to a state where its assets are now locked down
        $DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
        $DBLIB->where("assetsAssignments.projects_id", $project['projects_id']);
        $DBLIB->join("assets","assetsAssignments.assets_id=assets.assets_id", "LEFT");
        $DBLIB->join("assetTypes", "assets.assetTypes_id=assetTypes.assetTypes_id", "LEFT");
        $assets = $DBLIB->get("assetsAssignments", null, ["assetsAssignments.assets_id", "assetsAssignments.assetsAssignments_id", "assetsAssignments.assetsAssignments_quantity", "assets.assets_unserialized", "assets.assets_quantity", "assets.assets_tag", "assetTypes.assetTypes_name"]);
        if ($assets) {
            $unavailableAssets = [];
            foreach ($assets as $asset) {
                //This project's own claim is what's being locked down, so leave it out and see whether what's left still covers the units it needs
                $quantity = (intval($asset['assetsAssignments_quantity']) > 0 ? intval($asset['assetsAssignments_quantity']) : 1);
                $availability = assetAvailableQuantity($asset, $project["projects_dates_deliver_start"], $project["projects_dates_deliver_end"], null, $asset['assetsAssignments_id']);
                if ($availability['available'] < $quantity) {
                    $clash = (count($availability['assignments']) > 0 ? $availability['assignments'][0] : []);
                    $unavailableAssets[] = [
                        "assetsAssignments_id" => (isset($clash['assetsAssignments_id']) ? $clash['assetsAssignments_id'] : null),
                        "assets_id" => $asset['assets_id'],
                        "projects_id" => (isset($clash['projects_id']) ? $clash['projects_id'] : null),
                        "assetTypes_name" => $asset['assetTypes_name'],
                        "projects_name" => (isset($clash['projects_name']) ? $clash['projects_name'] : ""),
                        "assets_tag" => $asset['assets_tag'],
                        "old_assetsAssignments_id" => $asset['assetsAssignments_id']
                    ];
                }
            }
            if (count($unavailableAssets) > 0) {
                finish(true, null, ["changed" => false, "assets" => $unavailableAssets]);
            }
        }

    }

    $DBLIB->where("projects.projects_id", $project['projects_id']);
    $projectupdate =  $DBLIB->update("projects", ["projects.projectsStatuses_id" => $status]);
    if (!$projectupdate) finish(false);
    $bCMS->auditLog("UPDATE-STATUS", "projects", "Set the status to ". $newStatus['projectsStatuses_name'], $AUTH->data['users_userid'],null, $projectID);
}

//update this project
changeStatus($_POST['projects_id'],$_POST['projectsStatuses_id']);

//Update any sub-projects that follow their parent project's status
$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("projects.projects_parent_project_id", $_POST['projects_id']);
$DBLIB->where("projects.projects_status_follow_parent", 1);
$subProjects = $DBLIB->get("projects", null, ["projects_id"]);

foreach ($subProjects as $key => $value) {
    changeStatus($value['projects_id'],$_POST['projectsStatuses_id']);
}

finish(true, null, ["changed" => true]);

/** @OA\Post(
 *     path="/projects/changeStatus.php", 
 *     summary="Change Status", 
 *     description="Change the status of a project  
Requires Instance Permission PROJECTS:EDIT:STATUS
", 
 *     operationId="changeStatus", 
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
 *         name="projects_status",
 *         in="query",
 *         description="Project Status",
 *         required="true", 
 *         @OA\Schema(
 *             type="number"), 
 *         ), 
 * )
 */