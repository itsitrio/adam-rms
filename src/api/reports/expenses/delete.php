<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("FINANCE:EXPENSES:EDIT")) finish(false, ["code" => "AUTH-ERROR", "message" => "No auth for action"]);
if (!isset($_POST['expenses_id'])) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);

$DBLIB->where("expenses_id", intval($_POST['expenses_id']));
$DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("expenses_deleted", 0);
if (!$DBLIB->getOne("expenses", ["expenses_id"])) finish(false, ["code" => "PARAM-ERROR", "message" => "Could not find the expense"]);
$DBLIB->where("expenses_id", intval($_POST['expenses_id']));
$DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
if (!$DBLIB->update("expenses", ["expenses_deleted" => 1])) finish(false, ["code" => "UPDATE-FAIL", "message" => "Could not delete the expense"]);
$bCMS->auditLog("DELETE", "expenses", "Deleted expense", $AUTH->data['users_userid'], null, null, intval($_POST['expenses_id']));
finish(true);

/** @OA\Post(
 *     path="/reports/expenses/delete.php",
 *     summary="Delete Expense",
 *     description="Delete one of the business's overheads
Requires Instance Permission FINANCE:EXPENSES:EDIT
",
 *     operationId="deleteExpense",
 *     tags={"reports"},
 *     @OA\Response(response="200", description="Success", @OA\MediaType(mediaType="application/json", @OA\Schema(type="object",
 *         @OA\Property(property="result", type="boolean", description="Whether the request was successful"),
 *     ))),
 *     @OA\Parameter(name="expenses_id", in="query", description="The expense", required="true", @OA\Schema(type="number")),
 * )
 */
