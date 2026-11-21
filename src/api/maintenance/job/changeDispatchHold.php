<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("MAINTENANCE_JOBS:EDIT:ASSET_BLOCKS") or !isset($_POST['maintenanceJobs_id']) or !isset($_POST['maintenanceJobs_dispatchMode'])) die("404");

$DBLIB->where("maintenanceJobs.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("maintenanceJobs.maintenanceJobs_deleted", 0);
$DBLIB->where("maintenanceJobs_id", $_POST['maintenanceJobs_id']);
$job = $DBLIB->getOne("maintenanceJobs", ["maintenanceJobs_id", "maintenanceJobs_dispatchMode", "maintenanceJobs_dispatchOverridable"]);
if (!$job) die("404");

if (!array_key_exists($_POST['maintenanceJobs_dispatchMode'], ASSET_HOLD_MODES)) finish(false, ["code" => "PARAM-ERROR", "message" => "Unknown hold mode"]);
$update = ["maintenanceJobs_dispatchMode" => $_POST['maintenanceJobs_dispatchMode']];
if (isset($_POST['maintenanceJobs_dispatchOverridable'])) {
    if ($_POST['maintenanceJobs_dispatchOverridable'] != 1 && $_POST['maintenanceJobs_dispatchOverridable'] != 0) finish(false, ["code" => "PARAM-ERROR", "message" => "Overridable must be 1 or 0"]);
    $update["maintenanceJobs_dispatchOverridable"] = intval($_POST['maintenanceJobs_dispatchOverridable']);
}

$DBLIB->where("maintenanceJobs_id", $job['maintenanceJobs_id']);
if (!$DBLIB->update("maintenanceJobs", $update) and $DBLIB->getLastErrno() != 0) finish(false);

$bCMS->auditLog("CHANGE-DISPATCH-HOLD", "maintenanceJobs", "Sending out the job's assets: " . strtolower(ASSET_HOLD_MODES[$update['maintenanceJobs_dispatchMode']]) . (isset($update['maintenanceJobs_dispatchOverridable']) ? ($update['maintenanceJobs_dispatchOverridable'] ? ", can be signed off" : ", can't be signed off") : ""), $AUTH->data['users_userid'], null, null, $_POST['maintenanceJobs_id']);
finish(true);

/** @OA\Post(
 *     path="/maintenance/job/changeDispatchHold.php",
 *     summary="Change a Job's Rule for Sending Out Assets",
 *     description="Whether the assets a maintenance job is on can be sent out (given a status that leaves the warehouse): allowed, allowed with a warning or blocked
Requires Instance Permission MAINTENANCE_JOBS:EDIT:ASSET_BLOCKS
",
 *     operationId="changeDispatchHold",
 *     tags={"maintenance"},
 *     @OA\Response(
 *         response="200",
 *         description="Success",
 *         @OA\MediaType(
 *             mediaType="application/json",
 *             @OA\Schema(ref="#/components/schemas/SimpleResponse"),
 *         ),
 *     ),
 *     @OA\Response(
 *         response="404",
 *         description="Permission Error",
 *     ),
 *     @OA\Parameter(
 *         name="maintenanceJobs_id",
 *         in="query",
 *         description="Maintenance Job ID",
 *         required="true",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 *     @OA\Parameter(
 *         name="maintenanceJobs_dispatchMode",
 *         in="query",
 *         description="ALLOW, WARN or BLOCK",
 *         required="true",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 *     @OA\Parameter(
 *         name="maintenanceJobs_dispatchOverridable",
 *         in="query",
 *         description="1 if whoever is sending the asset out can sign off a block, 0 if it can't be overridden",
 *         required="false",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 * )
 */
