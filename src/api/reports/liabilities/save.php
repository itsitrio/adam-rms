<?php
require_once __DIR__ . '/../../apiHeadSecure.php';
require_once __DIR__ . '/../../../common/libs/reports/liabilityRequests.php';

//Adds a loan, finance or credit the business owes, or changes one if liabilities_id is sent
if (!$AUTH->instancePermissionCheck("FINANCE:LIABILITIES:EDIT")) finish(false, ["code" => "AUTH-ERROR", "message" => "No auth for action"]);

$name = liabilityText('liabilities_name', 200);
if ($name === null) finish(false, ["code" => "PARAM-ERROR", "message" => "Give the liability a name"]);
$type = $_POST['liabilities_type'] ?? "";
if (!is_string($type) or !isset(LIABILITY_TYPES[$type])) finish(false, ["code" => "PARAM-ERROR", "message" => "Choose what kind of liability this is"]);
$frequency = $_POST['liabilities_repaymentFrequency'] ?? "MONTHLY";
if (!is_string($frequency) or !isset(LIABILITY_FREQUENCIES[$frequency])) finish(false, ["code" => "PARAM-ERROR", "message" => "Choose how often it's repaid"]);
$rate = isset($_POST['liabilities_interestRate']) && is_string($_POST['liabilities_interestRate']) ? trim($_POST['liabilities_interestRate']) : "";
if ($rate !== "" and (!preg_match('/^\d{1,3}(\.\d{1,3})?$/', $rate) or floatval($rate) > 999)) finish(false, ["code" => "PARAM-ERROR", "message" => "The interest rate must be a percentage, like 6.5"]);

//A member of the business who lent the money
$member = null;
if (isset($_POST['users_userid']) and $_POST['users_userid'] !== "") {
    $DBLIB->join("instancePositions", "userInstances.instancePositions_id=instancePositions.instancePositions_id", "LEFT");
    $DBLIB->where("instancePositions.instances_id", $AUTH->data['instance']['instances_id']);
    $DBLIB->where("userInstances.users_userid", intval($_POST['users_userid']));
    $DBLIB->where("userInstances.userInstances_deleted", 0);
    if (!$DBLIB->getOne("userInstances", ["userInstances.users_userid"])) finish(false, ["code" => "PARAM-ERROR", "message" => "That person isn't a member of the business"]);
    $member = intval($_POST['users_userid']);
}

$data = [
    "liabilities_name" => $name,
    "liabilities_type" => $type,
    "liabilities_lender" => liabilityText('liabilities_lender', 200),
    "users_userid" => $member,
    "liabilities_amount" => liabilityMoney('liabilities_amount', "amount borrowed", true),
    "liabilities_startDate" => liabilityDate('liabilities_startDate', "start date", true),
    "liabilities_interestRate" => $rate === "" ? null : $rate,
    "liabilities_repaymentAmount" => liabilityMoney('liabilities_repaymentAmount', "repayment amount"),
    "liabilities_repaymentFrequency" => $frequency,
    "liabilities_firstRepaymentDate" => liabilityDate('liabilities_firstRepaymentDate', "first repayment date"),
    "liabilities_endDate" => liabilityDate('liabilities_endDate', "end date"),
    "liabilities_reference" => liabilityText('liabilities_reference', 200),
    "liabilities_notes" => liabilityText('liabilities_notes', 5000),
];
if ($data['liabilities_endDate'] !== null and $data['liabilities_endDate'] < $data['liabilities_startDate']) finish(false, ["code" => "PARAM-ERROR", "message" => "The end date is before the start date"]);

if (isset($_POST['liabilities_id']) and $_POST['liabilities_id'] !== "") {
    $liability = liabilityFind($_POST['liabilities_id']);
    $DBLIB->where("liabilities_id", $liability['liabilities_id']);
    if (!$DBLIB->update("liabilities", $data)) finish(false, ["code" => "UPDATE-FAIL", "message" => "Could not save the liability"]);
    $bCMS->auditLog("EDIT-LIABILITY", "liabilities", json_encode($data), $AUTH->data['users_userid'], null, null, $liability['liabilities_id']);
    finish(true, null, ["liabilities_id" => $liability['liabilities_id']]);
}
$data['instances_id'] = $AUTH->data['instance']['instances_id'];
$id = $DBLIB->insert("liabilities", $data);
if (!$id) finish(false, ["code" => "INSERT-FAIL", "message" => "Could not save the liability"]);
$bCMS->auditLog("INSERT", "liabilities", json_encode($data), $AUTH->data['users_userid'], null, null, $id);
finish(true, null, ["liabilities_id" => $id]);

/** @OA\Post(
 *     path="/reports/liabilities/save.php",
 *     summary="Save Liability",
 *     description="Add a loan, finance or credit the business owes, or change one
Requires Instance Permission FINANCE:LIABILITIES:EDIT
",
 *     operationId="saveLiability",
 *     tags={"reports"},
 *     @OA\Response(response="200", description="Success", @OA\MediaType(mediaType="application/json", @OA\Schema(type="object",
 *         @OA\Property(property="result", type="boolean", description="Whether the request was successful"),
 *         @OA\Property(property="response", type="object", @OA\Property(property="liabilities_id", type="number")),
 *     ))),
 *     @OA\Parameter(name="liabilities_id", in="query", description="The liability to change - leave out to add one", required="false", @OA\Schema(type="number")),
 *     @OA\Parameter(name="liabilities_name", in="query", description="Name", required="true", @OA\Schema(type="string")),
 *     @OA\Parameter(name="liabilities_type", in="query", description="LOAN, ASSET_FINANCE, VENDOR_CREDIT, MEMBER_LOAN or OTHER", required="true", @OA\Schema(type="string")),
 *     @OA\Parameter(name="liabilities_amount", in="query", description="Amount borrowed, as a decimal", required="true", @OA\Schema(type="string")),
 *     @OA\Parameter(name="liabilities_startDate", in="query", description="Start date (Y-m-d)", required="true", @OA\Schema(type="string")),
 *     @OA\Parameter(name="liabilities_lender", in="query", description="Who it's owed to", required="false", @OA\Schema(type="string")),
 *     @OA\Parameter(name="users_userid", in="query", description="The member of the business who lent it", required="false", @OA\Schema(type="number")),
 *     @OA\Parameter(name="liabilities_interestRate", in="query", description="Yearly interest rate, as a percentage", required="false", @OA\Schema(type="string")),
 *     @OA\Parameter(name="liabilities_repaymentAmount", in="query", description="Regular repayment, as a decimal", required="false", @OA\Schema(type="string")),
 *     @OA\Parameter(name="liabilities_repaymentFrequency", in="query", description="WEEKLY, MONTHLY, QUARTERLY or YEARLY", required="false", @OA\Schema(type="string")),
 *     @OA\Parameter(name="liabilities_firstRepaymentDate", in="query", description="First repayment date (Y-m-d)", required="false", @OA\Schema(type="string")),
 *     @OA\Parameter(name="liabilities_endDate", in="query", description="Date it should be paid off by (Y-m-d)", required="false", @OA\Schema(type="string")),
 *     @OA\Parameter(name="liabilities_reference", in="query", description="Reference or account number", required="false", @OA\Schema(type="string")),
 *     @OA\Parameter(name="liabilities_notes", in="query", description="Notes", required="false", @OA\Schema(type="string")),
 * )
 */
