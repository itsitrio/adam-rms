<?php
require_once __DIR__ . '/../apiHeadSecure.php';
require_once __DIR__ . '/../../common/libs/bCMS/projectFinance.php';
use Money\Currency;
use Money\Money;
use Money\Currencies\ISOCurrencies;
use Money\Parser\DecimalMoneyParser;

//Edit a line on a project's finance tab - a payment received, sales item, additional hire or staff cost. Its type can't be changed
if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_PAYMENTS:CREATE")) finish(false, ["code" => "AUTH-ERROR", "message" => "No auth for action"]);
if (!isset($_POST['formData']) or !is_array($_POST['formData'])) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);

$array = [];
foreach ($_POST['formData'] as $item) {
    if (!is_array($item) or !isset($item['name']) or !is_string($item['name'])) continue;
    $array[$item['name']] = is_string($item['value'] ?? null) ? $item['value'] : "";
}
if (!isset($array['payments_id'])) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);

$DBLIB->join("projects", "payments.projects_id=projects.projects_id", "LEFT");
$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("payments.payments_deleted", 0);
$DBLIB->where("payments.payments_id", intval($array['payments_id']));
$payment = $DBLIB->getOne("payments", ["payments.payments_id", "payments.projects_id", "payments.payments_type", "payments.payments_amount", "payments.payments_quantity"]);
if (!$payment) finish(false, ["code" => "PARAM-ERROR", "message" => "Could not find the line"]);

$moneyParser = new DecimalMoneyParser(new ISOCurrencies());
$currency = $AUTH->data['instance']['instances_config_currency'];
$update = [];

if (!isset($array['payments_amount']) or !preg_match('/^\d+(\.\d+)?$/', trim($array['payments_amount']))) finish(false, ["code" => "PARAM-ERROR", "message" => "The amount must be a number"]);
$update['payments_amount'] = $moneyParser->parse(trim($array['payments_amount']), $currency)->getAmount();
$update['payments_comment'] = $array['payments_comment'] ?? "";

if ($payment['payments_type'] == 1) {
    //A payment received
    if (isset($array['payments_date']) and strtotime($array['payments_date']) !== false) $update['payments_date'] = date("Y-m-d H:i:s", strtotime($array['payments_date']));
    if (isset($array['payments_reference'])) $update['payments_reference'] = $array['payments_reference'];
    if (isset($array['payments_method'])) $update['payments_method'] = $array['payments_method'];
    $update['payments_quantity'] = 1;
} else {
    //A sales item, additional hire or staff cost
    $quantity = trim($array['payments_quantity'] ?? "");
    if ($quantity === "") $quantity = "1";
    if (!ctype_digit($quantity)) finish(false, ["code" => "PARAM-ERROR", "message" => "The quantity must be a whole number"]);
    $update['payments_quantity'] = intval($quantity);
    if (isset($array['payments_supplier'])) $update['payments_supplier'] = $array['payments_supplier'];
    //What one unit costs the business - only changed by someone who can see it
    if (isset($array['payments_cost']) and $AUTH->instancePermissionCheck("FINANCE:PAYMENTS_LEDGER:VIEW")) {
        $cost = trim($array['payments_cost']);
        if ($cost === "") $update['payments_cost'] = null;
        else {
            if (!preg_match('/^\d+(\.\d+)?$/', $cost)) finish(false, ["code" => "PARAM-ERROR", "message" => "The cost must be a number"]);
            $update['payments_cost'] = $moneyParser->parse($cost, $currency)->getAmount();
        }
    }
}

$projectFinanceCacher = new projectFinanceCacher($payment['projects_id']);

$DBLIB->where("payments_id", $payment['payments_id']);
if (!$DBLIB->update("payments", $update)) finish(false, ["code" => "UPDATE-FAIL", "message" => "Could not save the line"]);

//Take the old total off the project's running totals and put the new one on
$oldTotal = (new Money($payment['payments_amount'], new Currency($currency)))->multiply($payment['payments_quantity']);
$newTotal = (new Money($update['payments_amount'], new Currency($currency)))->multiply($update['payments_quantity']);
$projectFinanceCacher->adjustPayment($payment['payments_type'], $oldTotal, true);
$projectFinanceCacher->adjustPayment($payment['payments_type'], $newTotal, false);

$bCMS->auditLog("UPDATE", "payments", json_encode($update), $AUTH->data['users_userid'], null, $payment['projects_id'], $payment['payments_id']);

if ($projectFinanceCacher->save()) finish(true);
else finish(false, ["message" => "Finance Cacher Save failed"]);

/** @OA\Post(
 *     path="/projects/editPayment.php",
 *     summary="Edit Payment",
 *     description="Edit a payment received, sales item, additional hire or staff cost on a project. Its type can't be changed.
Requires Instance Permission PROJECTS:PROJECT_PAYMENTS:CREATE (and FINANCE:PAYMENTS_LEDGER:VIEW to change our cost)
",
 *     operationId="editPayment",
 *     tags={"projects"},
 *     @OA\Response(response="200", description="Success", @OA\MediaType(mediaType="application/json", @OA\Schema(type="object",
 *         @OA\Property(property="result", type="boolean", description="Whether the request was successful"),
 *     ))),
 *     @OA\Parameter(name="formData", in="query", description="Form data: payments_id, payments_amount, payments_comment, and payments_quantity, payments_supplier and payments_cost for sales, additional hire and staff lines, or payments_date, payments_reference and payments_method for payments received", required="true", @OA\Schema(type="object")),
 * )
 */
