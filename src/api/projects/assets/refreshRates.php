<?php
require_once __DIR__ . '/../../apiHeadSecure.php';
require_once __DIR__ . '/../../../common/libs/bCMS/projectFinance.php';
use Money\Currency;
use Money\Money;

//A project's assets are priced at the rates saved when each was added. This re-saves the assets' (or their types') current rates on all of them.
if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:EDIT:CUSTOM_PRICE")) finish(false, ["code" => "AUTH-ERROR", "message" => "No auth for action"]);
if (!isset($_POST['projects_id'])) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);
$dryRun = (isset($_POST['dryRun']) and ($_POST['dryRun'] == 1 or $_POST['dryRun'] === "true"));

$DBLIB->where("projects.projects_id", $_POST['projects_id']);
$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$project = $DBLIB->getone("projects", ["projects_id"]);
if (!$project) finish(false, ["code" => "PARAM-ERROR", "message" => "Cannot find project"]);

$projectFinanceHelper = new projectFinance();
$priceMaths = $projectFinanceHelper->durationMaths($project['projects_id']);
$currency = new Currency($AUTH->data['instance']['instances_config_currency']);

//The same assignments projects/data.php prices
$DBLIB->where("assetsAssignments.projects_id", $project['projects_id']);
$DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
$DBLIB->where("assets.assets_deleted", 0);
$DBLIB->join("assets", "assetsAssignments.assets_id=assets.assets_id", "LEFT");
$DBLIB->join("assetTypes", "assets.assetTypes_id=assetTypes.assetTypes_id", "LEFT");
$assignments = $DBLIB->get("assetsAssignments", null, ["assetsAssignments.assetsAssignments_id", "assetsAssignments.assetsAssignments_customPrice", "assetsAssignments.assetsAssignments_dayRate", "assetsAssignments.assetsAssignments_weekRate", "assetsAssignments.assetsAssignments_discount", "assetsAssignments.assetsAssignments_quantity", "assetsAssignments.assetsAssignments_excludedFromTotals", "assets.assets_dayRate", "assets.assets_weekRate", "assetTypes.assetTypes_dayRate", "assetTypes.assetTypes_weekRate"]);

//An assignment's price and discount at the given rates, the way projects/data.php works them out
$priceAt = function ($assignment, $dayRate, $weekRate) use ($priceMaths, $currency) {
    $zero = new Money(0, $currency);
    if ($assignment['assetsAssignments_excludedFromTotals'] == 1) return ["subTotal" => $zero, "discounts" => $zero];
    $quantity = (intval($assignment['assetsAssignments_quantity']) > 0 ? intval($assignment['assetsAssignments_quantity']) : 1);
    if ($assignment['assetsAssignments_customPrice'] == null) {
        $price = (new Money($dayRate, $currency))->multiply($priceMaths['days']);
        $price = $price->add((new Money($weekRate, $currency))->multiply($priceMaths['weeks']));
    } else $price = new Money($assignment['assetsAssignments_customPrice'], $currency);
    $price = $price->multiply($quantity);
    $discounted = ($assignment['assetsAssignments_discount'] > 0 ? $price->multiply(1 - ($assignment['assetsAssignments_discount'] / 100)) : $price);
    return ["subTotal" => $price, "discounts" => $price->subtract($discounted)];
};

$before = ["subTotal" => new Money(0, $currency), "discounts" => new Money(0, $currency)];
$after = $before;
$updates = [];
foreach ($assignments as $assignment) {
    $old = $priceAt($assignment, projectFinance::assignmentRate($assignment, 'dayRate'), projectFinance::assignmentRate($assignment, 'weekRate'));
    $rates = ["assetsAssignments_dayRate" => projectFinance::currentRate($assignment, 'dayRate'), "assetsAssignments_weekRate" => projectFinance::currentRate($assignment, 'weekRate')];
    $new = $priceAt($assignment, $rates['assetsAssignments_dayRate'], $rates['assetsAssignments_weekRate']);
    foreach (["subTotal", "discounts"] as $key) {
        $before[$key] = $before[$key]->add($old[$key]);
        $after[$key] = $after[$key]->add($new[$key]);
    }
    if ($rates['assetsAssignments_dayRate'] != $assignment['assetsAssignments_dayRate'] or $rates['assetsAssignments_weekRate'] != $assignment['assetsAssignments_weekRate'] or $assignment['assetsAssignments_dayRate'] === null or $assignment['assetsAssignments_weekRate'] === null) $updates[$assignment['assetsAssignments_id']] = $rates;
}
$totals = function ($prices) {
    $total = $prices['subTotal']->subtract($prices['discounts']);
    return ["subTotal" => $prices['subTotal'], "discounts" => $prices['discounts'], "total" => $total, "formattedTotal" => apiMoney($total)];
};
$response = ["changed" => false, "assignments" => count($updates), "before" => $totals($before), "after" => $totals($after)];
if ($dryRun or count($updates) == 0) finish(true, null, $response);

foreach ($updates as $assignmentId => $rates) {
    $DBLIB->where("assetsAssignments_id", $assignmentId);
    $DBLIB->where("projects_id", $project['projects_id']);
    if (!$DBLIB->update("assetsAssignments", $rates, 1)) finish(false, ["code" => "UPDATE-FAIL", "message" => "Could not update the rates"]);
}
$projectFinanceCacher = new projectFinanceCacher($project['projects_id']);
$projectFinanceCacher->adjust('projectsFinanceCache_equipmentSubTotal', $after['subTotal']->subtract($before['subTotal']));
$projectFinanceCacher->adjust('projectsFinanceCache_equiptmentDiscounts', $after['discounts']->subtract($before['discounts']));
if (!$projectFinanceCacher->save()) finish(false, ["code" => "UPDATE-FAIL", "message" => "Finance Cacher Save failed"]);
$bCMS->auditLog("REFRESH-RATES", "assetsAssignments", json_encode(["assignments" => array_keys($updates), "totalBefore" => $before['subTotal']->subtract($before['discounts'])->getAmount(), "totalAfter" => $after['subTotal']->subtract($after['discounts'])->getAmount()]), $AUTH->data['users_userid'], null, $project['projects_id']);
$response['changed'] = true;
finish(true, null, $response);

/** @OA\Post(
 *     path="/projects/assets/refreshRates.php",
 *     summary="Update Project to Current Rates",
 *     description="Assets on a project are priced at the day and week rates in effect when each was added. This re-saves the assets' current rates on every asset assigned to the project, and updates the project's totals to match. Custom prices and discounts are left as they are.
Requires Instance Permission PROJECTS:PROJECT_ASSETS:EDIT:CUSTOM_PRICE
",
 *     operationId="refreshProjectAssetRates",
 *     tags={"project_assets"},
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
 *                 @OA\Property(
 *                     property="response",
 *                     type="object",
 *                     description="What the update changes",
 *                     @OA\Property(property="changed", type="boolean", description="Whether any rates were updated (always false for a dry run)"),
 *                     @OA\Property(property="assignments", type="number", description="How many assignments' rates are out of date"),
 *                     @OA\Property(property="before", type="object", description="The project's equipment subTotal, discounts and total (and formattedTotal) at the saved rates"),
 *                     @OA\Property(property="after", type="object", description="The project's equipment subTotal, discounts and total (and formattedTotal) at the current rates"),
 *                 ),
 *             ),
 *         ),
 *     ),
 *     @OA\Response(
 *         response="default",
 *         description="Error",
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
 *         name="dryRun",
 *         in="query",
 *         description="Set to 1 to only work out what the update would change, without saving anything",
 *         required="false",
 *         @OA\Schema(
 *             type="boolean"),
 *         ),
 * )
 */
