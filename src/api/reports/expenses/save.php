<?php
require_once __DIR__ . '/../../apiHeadSecure.php';
require_once __DIR__ . '/../../../common/libs/reports/moneyInput.php';

use Money\Currencies\ISOCurrencies;
use Money\Parser\DecimalMoneyParser;

//Adds an overhead, or changes one if expenses_id is sent
if (!$AUTH->instancePermissionCheck("FINANCE:EXPENSES:EDIT")) finish(false, ["code" => "AUTH-ERROR", "message" => "No auth for action"]);

$text = function ($key, $limit) {
    if (!isset($_POST[$key]) or !is_string($_POST[$key])) return null;
    $value = trim($_POST[$key]);
    return $value === "" ? null : mb_substr($value, 0, $limit);
};
$date = $_POST['expenses_date'] ?? null;
if (!is_string($date) or !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) or !checkdate(intval(substr($date, 5, 2)), intval(substr($date, 8, 2)), intval(substr($date, 0, 4)))) finish(false, ["code" => "PARAM-ERROR", "message" => "Enter the date of the expense"]);
$category = $text('expenses_category', 100);
if ($category === null) finish(false, ["code" => "PARAM-ERROR", "message" => "Choose a category for the expense"]);
$amount = moneyInputParse($_POST['expenses_amount'] ?? null, $AUTH->data['instance']['instances_config_currency']);
if ($amount === false) finish(false, ["code" => "PARAM-ERROR", "message" => "The amount must be an amount, like 250.00"]);
if ($amount === null) finish(false, ["code" => "PARAM-ERROR", "message" => "Enter the amount, like 250.00"]);

$data = [
    "expenses_date" => $date,
    "expenses_category" => $category,
    "expenses_description" => $text('expenses_description', 500),
    "expenses_supplier" => $text('expenses_supplier', 200),
    "expenses_reference" => $text('expenses_reference', 200),
    "expenses_amount" => $amount,
];

if (isset($_POST['expenses_id']) and $_POST['expenses_id'] !== "") {
    $DBLIB->where("expenses_id", intval($_POST['expenses_id']));
    $DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
    $DBLIB->where("expenses_deleted", 0);
    if (!$DBLIB->getOne("expenses", ["expenses_id"])) finish(false, ["code" => "PARAM-ERROR", "message" => "Could not find the expense"]);
    $DBLIB->where("expenses_id", intval($_POST['expenses_id']));
    $DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
    if (!$DBLIB->update("expenses", $data)) finish(false, ["code" => "UPDATE-FAIL", "message" => "Could not save the expense"]);
    $bCMS->auditLog("EDIT-EXPENSE", "expenses", json_encode($data), $AUTH->data['users_userid'], null, null, intval($_POST['expenses_id']));
    finish(true, null, ["expenses_id" => intval($_POST['expenses_id'])]);
}

$data['instances_id'] = $AUTH->data['instance']['instances_id'];
$data['users_userid'] = $AUTH->data['users_userid'];
$id = $DBLIB->insert("expenses", $data);
if (!$id) finish(false, ["code" => "INSERT-FAIL", "message" => "Could not save the expense"]);
$bCMS->auditLog("INSERT", "expenses", json_encode($data), $AUTH->data['users_userid'], null, null, $id);
finish(true, null, ["expenses_id" => $id]);

/** @OA\Post(
 *     path="/reports/expenses/save.php",
 *     summary="Save Expense",
 *     description="Add an overhead to the business, or change one
Requires Instance Permission FINANCE:EXPENSES:EDIT
",
 *     operationId="saveExpense",
 *     tags={"reports"},
 *     @OA\Response(response="200", description="Success", @OA\MediaType(mediaType="application/json", @OA\Schema(type="object",
 *         @OA\Property(property="result", type="boolean", description="Whether the request was successful"),
 *         @OA\Property(property="response", type="object", description="The expense's ID", @OA\Property(property="expenses_id", type="number")),
 *     ))),
 *     @OA\Parameter(name="expenses_id", in="query", description="The expense to change - leave out to add one", required="false", @OA\Schema(type="number")),
 *     @OA\Parameter(name="expenses_date", in="query", description="Date (Y-m-d)", required="true", @OA\Schema(type="string")),
 *     @OA\Parameter(name="expenses_category", in="query", description="Category", required="true", @OA\Schema(type="string")),
 *     @OA\Parameter(name="expenses_amount", in="query", description="Amount, as a decimal", required="true", @OA\Schema(type="string")),
 *     @OA\Parameter(name="expenses_supplier", in="query", description="Supplier", required="false", @OA\Schema(type="string")),
 *     @OA\Parameter(name="expenses_reference", in="query", description="Reference", required="false", @OA\Schema(type="string")),
 *     @OA\Parameter(name="expenses_description", in="query", description="Description", required="false", @OA\Schema(type="string")),
 * )
 */
