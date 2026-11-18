<?php
require_once __DIR__ . '/../../apiHeadSecure.php';
require_once __DIR__ . '/../../../common/libs/reports/liabilityRequests.php';

//Records a repayment, interest or fee, or more borrowing against a liability - or changes one if liabilityTransactions_id is sent
if (!$AUTH->instancePermissionCheck("FINANCE:LIABILITIES:EDIT")) finish(false, ["code" => "AUTH-ERROR", "message" => "No auth for action"]);
if (!isset($_POST['liabilities_id'])) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);
$liability = liabilityFind($_POST['liabilities_id']);

$type = $_POST['liabilityTransactions_type'] ?? "";
if (!is_string($type) or !isset(LIABILITY_TRANSACTION_TYPES[$type])) finish(false, ["code" => "PARAM-ERROR", "message" => "Choose what kind of transaction this is"]);
$amount = liabilityMoney('liabilityTransactions_amount', "amount", true);
$interest = $type == "REPAYMENT" ? (liabilityMoney('liabilityTransactions_interest', "interest") ?? 0) : 0;
if ($interest > $amount) finish(false, ["code" => "PARAM-ERROR", "message" => "The interest can't be more than the repayment"]);
$data = [
    "liabilities_id" => $liability['liabilities_id'],
    "liabilityTransactions_type" => $type,
    "liabilityTransactions_date" => liabilityDate('liabilityTransactions_date', "date", true),
    "liabilityTransactions_amount" => $amount,
    "liabilityTransactions_interest" => $interest,
    "liabilityTransactions_reference" => liabilityText('liabilityTransactions_reference', 200),
    "liabilityTransactions_notes" => liabilityText('liabilityTransactions_notes', 500),
];

if (isset($_POST['liabilityTransactions_id']) and $_POST['liabilityTransactions_id'] !== "") {
    $DBLIB->where("liabilityTransactions_id", intval($_POST['liabilityTransactions_id']));
    $DBLIB->where("liabilities_id", $liability['liabilities_id']);
    $DBLIB->where("liabilityTransactions_deleted", 0);
    if (!$DBLIB->getOne("liabilityTransactions", ["liabilityTransactions_id"])) finish(false, ["code" => "PARAM-ERROR", "message" => "Could not find the transaction"]);
    $DBLIB->where("liabilityTransactions_id", intval($_POST['liabilityTransactions_id']));
    if (!$DBLIB->update("liabilityTransactions", $data)) finish(false, ["code" => "UPDATE-FAIL", "message" => "Could not save the transaction"]);
    $bCMS->auditLog("EDIT-LIABILITY-TRANSACTION", "liabilityTransactions", json_encode($data), $AUTH->data['users_userid'], null, null, intval($_POST['liabilityTransactions_id']));
    finish(true, null, ["liabilityTransactions_id" => intval($_POST['liabilityTransactions_id'])]);
}
$data['users_userid'] = $AUTH->data['users_userid'];
$id = $DBLIB->insert("liabilityTransactions", $data);
if (!$id) finish(false, ["code" => "INSERT-FAIL", "message" => "Could not save the transaction"]);
$bCMS->auditLog("INSERT", "liabilityTransactions", json_encode($data), $AUTH->data['users_userid'], null, null, $id);
finish(true, null, ["liabilityTransactions_id" => $id]);

/** @OA\Post(
 *     path="/reports/liabilities/transaction.php",
 *     summary="Save Liability Transaction",
 *     description="Record a repayment, interest or a fee, or more borrowing, against a liability - or change one
Requires Instance Permission FINANCE:LIABILITIES:EDIT
",
 *     operationId="saveLiabilityTransaction",
 *     tags={"reports"},
 *     @OA\Response(response="200", description="Success", @OA\MediaType(mediaType="application/json", @OA\Schema(type="object",
 *         @OA\Property(property="result", type="boolean", description="Whether the request was successful"),
 *         @OA\Property(property="response", type="object", @OA\Property(property="liabilityTransactions_id", type="number")),
 *     ))),
 *     @OA\Parameter(name="liabilities_id", in="query", description="The liability", required="true", @OA\Schema(type="number")),
 *     @OA\Parameter(name="liabilityTransactions_id", in="query", description="The transaction to change - leave out to add one", required="false", @OA\Schema(type="number")),
 *     @OA\Parameter(name="liabilityTransactions_type", in="query", description="REPAYMENT, CHARGE (interest or a fee added) or DRAWDOWN (more borrowed)", required="true", @OA\Schema(type="string")),
 *     @OA\Parameter(name="liabilityTransactions_date", in="query", description="Date (Y-m-d)", required="true", @OA\Schema(type="string")),
 *     @OA\Parameter(name="liabilityTransactions_amount", in="query", description="Amount, as a decimal", required="true", @OA\Schema(type="string")),
 *     @OA\Parameter(name="liabilityTransactions_interest", in="query", description="For a repayment, how much of it was interest", required="false", @OA\Schema(type="string")),
 *     @OA\Parameter(name="liabilityTransactions_reference", in="query", description="Reference", required="false", @OA\Schema(type="string")),
 *     @OA\Parameter(name="liabilityTransactions_notes", in="query", description="Notes", required="false", @OA\Schema(type="string")),
 * )
 */
