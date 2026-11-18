<?php
require_once __DIR__ . '/../../apiHeadSecure.php';
require_once __DIR__ . '/../../../common/libs/reports/moneyInput.php';

use Money\Currencies\ISOCurrencies;
use Money\Parser\DecimalMoneyParser;

//What a repair cost the business - empty clears it
if (!$AUTH->instancePermissionCheck("MAINTENANCE_JOBS:EDIT:COST")) finish(false, ["code" => "AUTH-ERROR", "message" => "No auth for action"]);
if (!isset($_POST['maintenanceJobs_id']) or !isset($_POST['maintenanceJobs_cost']) or !is_string($_POST['maintenanceJobs_cost'])) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);

$cost = moneyInputParse($_POST['maintenanceJobs_cost'], $AUTH->data['instance']['instances_config_currency']);
if ($cost === false) finish(false, ["code" => "PARAM-ERROR", "message" => "The cost must be an amount, like 250.00"]);
$DBLIB->where("maintenanceJobs.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("maintenanceJobs.maintenanceJobs_deleted", 0);
$DBLIB->where("maintenanceJobs_id", intval($_POST['maintenanceJobs_id']));
if (!$DBLIB->getOne("maintenanceJobs", ["maintenanceJobs_id"])) finish(false, ["code" => "PARAM-ERROR", "message" => "Could not find the job"]);
$DBLIB->where("maintenanceJobs.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("maintenanceJobs_id", intval($_POST['maintenanceJobs_id']));
if (!$DBLIB->update("maintenanceJobs", ["maintenanceJobs_cost" => $cost])) finish(false, ["code" => "UPDATE-FAIL", "message" => "Could not save the cost"]);
$bCMS->auditLog("CHANGE-COST", "maintenanceJobs", "Set the cost to " . ($cost === null ? "nothing" : $cost), $AUTH->data['users_userid'], null, null, intval($_POST['maintenanceJobs_id']));
finish(true);

/** @OA\Post(
 *     path="/maintenance/job/changeCost.php",
 *     summary="Change Cost",
 *     description="Set what a maintenance job cost the business
Requires Instance Permission MAINTENANCE_JOBS:EDIT:COST
",
 *     operationId="changeJobCost",
 *     tags={"maintenanceJobs"},
 *     @OA\Response(response="200", description="Success", @OA\MediaType(mediaType="application/json", @OA\Schema(type="object",
 *         @OA\Property(property="result", type="boolean", description="Whether the request was successful"),
 *     ))),
 *     @OA\Parameter(name="maintenanceJobs_id", in="query", description="The job", required="true", @OA\Schema(type="number")),
 *     @OA\Parameter(name="maintenanceJobs_cost", in="query", description="Cost, as a decimal - empty to clear it", required="true", @OA\Schema(type="string")),
 * )
 */
