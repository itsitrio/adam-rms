<?php
require_once __DIR__ . '/../apiHeadSecure.php';

use Money\Currencies\ISOCurrencies;
use Money\Parser\DecimalMoneyParser;

//What one unit of a sales, additional hire or staff line costs the business - empty clears it. It doesn't change what the client is charged
if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_PAYMENTS:CREATE") or !$AUTH->instancePermissionCheck("FINANCE:PAYMENTS_LEDGER:VIEW")) finish(false, ["code" => "AUTH-ERROR", "message" => "No auth for action"]);
if (!isset($_POST['payments_id']) or !isset($_POST['payments_cost']) or !is_string($_POST['payments_cost'])) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);

$DBLIB->join("projects", "payments.projects_id=projects.projects_id", "LEFT");
$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("payments.payments_deleted", 0);
$DBLIB->where("payments.payments_id", intval($_POST['payments_id']));
$payment = $DBLIB->getOne("payments", ["payments.payments_id", "payments.payments_type", "payments.projects_id"]);
if (!$payment) finish(false, ["code" => "PARAM-ERROR", "message" => "Could not find the line"]);
if ($payment['payments_type'] == 1) finish(false, ["code" => "PARAM-ERROR", "message" => "A payment received doesn't have a cost"]);

$cost = trim($_POST['payments_cost']);
if ($cost === "") $cost = null;
else {
    if (!preg_match('/^\d+(\.\d+)?$/', $cost)) finish(false, ["code" => "PARAM-ERROR", "message" => "The cost must be a number"]);
    $cost = (new DecimalMoneyParser(new ISOCurrencies()))->parse($cost, $AUTH->data['instance']['instances_config_currency'])->getAmount();
}
$DBLIB->where("payments_id", $payment['payments_id']);
if (!$DBLIB->update("payments", ["payments_cost" => $cost])) finish(false, ["code" => "UPDATE-FAIL", "message" => "Could not save the cost"]);
$bCMS->auditLog("CHANGE-COST", "payments", "Set the cost of line " . $payment['payments_id'] . " to " . ($cost === null ? "nothing" : $cost), $AUTH->data['users_userid'], null, $payment['projects_id'], $payment['payments_id']);
finish(true);

/** @OA\Post(
 *     path="/projects/changePaymentCost.php",
 *     summary="Change Payment Cost",
 *     description="Set what one unit of a sales, additional hire or staff line costs the business
Requires Instance Permissions PROJECTS:PROJECT_PAYMENTS:CREATE and FINANCE:PAYMENTS_LEDGER:VIEW
",
 *     operationId="changePaymentCost",
 *     tags={"projects"},
 *     @OA\Response(response="200", description="Success", @OA\MediaType(mediaType="application/json", @OA\Schema(type="object",
 *         @OA\Property(property="result", type="boolean", description="Whether the request was successful"),
 *     ))),
 *     @OA\Parameter(name="payments_id", in="query", description="The line", required="true", @OA\Schema(type="number")),
 *     @OA\Parameter(name="payments_cost", in="query", description="Cost of one unit, as a decimal - empty to clear it", required="true", @OA\Schema(type="string")),
 * )
 */
