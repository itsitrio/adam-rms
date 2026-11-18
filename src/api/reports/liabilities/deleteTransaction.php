<?php
require_once __DIR__ . '/../../apiHeadSecure.php';
require_once __DIR__ . '/../../../common/libs/reports/liabilityRequests.php';

if (!$AUTH->instancePermissionCheck("FINANCE:LIABILITIES:EDIT")) finish(false, ["code" => "AUTH-ERROR", "message" => "No auth for action"]);
if (!isset($_POST['liabilityTransactions_id'])) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);
$DBLIB->join("liabilities", "liabilityTransactions.liabilities_id=liabilities.liabilities_id", "LEFT");
$DBLIB->where("liabilities.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("liabilities.liabilities_deleted", 0);
$DBLIB->where("liabilityTransactions.liabilityTransactions_deleted", 0);
$DBLIB->where("liabilityTransactions.liabilityTransactions_id", intval($_POST['liabilityTransactions_id']));
$transaction = $DBLIB->getOne("liabilityTransactions", ["liabilityTransactions.liabilityTransactions_id"]);
if (!$transaction) finish(false, ["code" => "PARAM-ERROR", "message" => "Could not find the transaction"]);
$DBLIB->where("liabilityTransactions_id", $transaction['liabilityTransactions_id']);
if (!$DBLIB->update("liabilityTransactions", ["liabilityTransactions_deleted" => 1])) finish(false, ["code" => "UPDATE-FAIL", "message" => "Could not delete the transaction"]);
$bCMS->auditLog("DELETE", "liabilityTransactions", "Deleted transaction", $AUTH->data['users_userid'], null, null, $transaction['liabilityTransactions_id']);
finish(true);

/** @OA\Post(
 *     path="/reports/liabilities/deleteTransaction.php",
 *     summary="Delete Liability Transaction",
 *     description="Delete a repayment, charge or drawdown recorded against a liability
Requires Instance Permission FINANCE:LIABILITIES:EDIT
",
 *     operationId="deleteLiabilityTransaction",
 *     tags={"reports"},
 *     @OA\Response(response="200", description="Success", @OA\MediaType(mediaType="application/json", @OA\Schema(type="object", @OA\Property(property="result", type="boolean", description="Whether the request was successful")))),
 *     @OA\Parameter(name="liabilityTransactions_id", in="query", description="The transaction", required="true", @OA\Schema(type="number")),
 * )
 */
