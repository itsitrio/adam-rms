<?php
/**
 * API
 * \projects\assets\setHidden.php
 * Hides assignments from a project's quotes and invoices (they still show on delivery notes), and/or leaves them
 * out of the project's totals - for things like cases and accessories that only matter for picking and checking
 *
 * Arguments:
 *  - assetsAssignments: array of assignment IDs, all on the same project
 *  - assetsAssignments_hidden (optional): 1 to leave them off quotes and invoices, 0 to show them
 *  - assetsAssignments_excludedFromTotals (optional): 1 to leave them out of the project's totals, 0 to charge for them
 */

require_once __DIR__ . '/../../apiHeadSecure.php';
require_once __DIR__ . '/../../../common/libs/bCMS/projectFinance.php';

use Money\Currency;
use Money\Money;

if (!isset($_POST['assetsAssignments']) or !is_array($_POST['assetsAssignments']) or count($_POST['assetsAssignments']) < 1) finish(false, ["code" => "PARAM-ERROR", "message" => "No assets selected"]);
if (!isset($_POST['assetsAssignments_hidden']) and !isset($_POST['assetsAssignments_excludedFromTotals'])) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);
//Hiding a line changes the quote's layout, while leaving it out of the totals changes what the client is charged
if (isset($_POST['assetsAssignments_hidden']) and !$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:EDIT:QUOTE_SECTIONS_AND_LINES")) finish(false, ["code" => "AUTH-ERROR", "message" => "No auth for action"]);
if (isset($_POST['assetsAssignments_excludedFromTotals']) and !$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:EDIT:CUSTOM_PRICE")) finish(false, ["code" => "AUTH-ERROR", "message" => "No auth for action"]);

$DBLIB->where("assetsAssignments.assetsAssignments_id", $_POST['assetsAssignments'], "IN");
$DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("assets.assets_deleted", 0);
$DBLIB->join("projects", "assetsAssignments.projects_id=projects.projects_id", "LEFT");
$DBLIB->join("assets", "assetsAssignments.assets_id=assets.assets_id", "LEFT");
$DBLIB->join("assetTypes", "assets.assetTypes_id=assetTypes.assetTypes_id", "LEFT");
$assignments = $DBLIB->get("assetsAssignments", null, ["assetsAssignments.assetsAssignments_id", "assetsAssignments.projects_id", "assetsAssignments.assetsAssignments_quantity", "assetsAssignments.assetsAssignments_customPrice", "assetsAssignments.assetsAssignments_discount", "assetsAssignments.assetsAssignments_hidden", "assetsAssignments.assetsAssignments_excludedFromTotals", "assets.assets_dayRate", "assets.assets_weekRate", "assetTypes.assetTypes_dayRate", "assetTypes.assetTypes_weekRate"]);
if (count($assignments) != count(array_unique($_POST['assetsAssignments']))) finish(false, ["message" => "Cannot find assignment"]);
$projectIds = array_unique(array_column($assignments, "projects_id"));
if (count($projectIds) != 1) finish(false, ["message" => "Assets must all be on the same project"]);

$projectFinanceHelper = new projectFinance();
$priceMaths = $projectFinanceHelper->durationMaths($projectIds[0]);
$projectFinanceCacher = new projectFinanceCacher($projectIds[0]);

foreach ($assignments as $assignment) {
    $update = [];
    if (isset($_POST['assetsAssignments_hidden'])) $update['assetsAssignments_hidden'] = ($_POST['assetsAssignments_hidden'] == 1 ? 1 : 0);
    if (isset($_POST['assetsAssignments_excludedFromTotals'])) $update['assetsAssignments_excludedFromTotals'] = ($_POST['assetsAssignments_excludedFromTotals'] == 1 ? 1 : 0);
    $DBLIB->where("assetsAssignments_id", $assignment['assetsAssignments_id']);
    if (!$DBLIB->update("assetsAssignments", $update, 1)) finish(false, ["message" => "Could not update assignment"]);
    $bCMS->auditLog("EDIT-ASSIGNMENT-HIDDEN", "assetsAssignments", json_encode($update), $AUTH->data['users_userid'], null, $assignment['projects_id']);

    //Leaving an assignment out of the totals takes its price (and discount) off them, and putting it back adds them again
    if (!isset($update['assetsAssignments_excludedFromTotals']) or $update['assetsAssignments_excludedFromTotals'] == $assignment['assetsAssignments_excludedFromTotals']) continue;
    $quantity = (intval($assignment['assetsAssignments_quantity']) > 0 ? intval($assignment['assetsAssignments_quantity']) : 1);
    if ($assignment['assetsAssignments_customPrice'] > 0) {
        $price = new Money($assignment['assetsAssignments_customPrice'], new Currency($AUTH->data['instance']['instances_config_currency']));
    } else {
        $price = new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']));
        $price = $price->add((new Money(($assignment['assets_dayRate'] !== null ? $assignment['assets_dayRate'] : $assignment['assetTypes_dayRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($priceMaths['days']));
        $price = $price->add((new Money(($assignment['assets_weekRate'] !== null ? $assignment['assets_weekRate'] : $assignment['assetTypes_weekRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($priceMaths['weeks']));
    }
    $price = $price->multiply($quantity);
    $subtract = ($update['assetsAssignments_excludedFromTotals'] == 1);
    $projectFinanceCacher->adjust('projectsFinanceCache_equipmentSubTotal', $price, $subtract);
    if ($assignment['assetsAssignments_discount'] > 0) $projectFinanceCacher->adjust('projectsFinanceCache_equiptmentDiscounts', $price->subtract($price->multiply(1 - ($assignment['assetsAssignments_discount'] / 100))), $subtract);
}
if ($projectFinanceCacher->save()) finish(true);
else finish(false, ["message" => "Finance Cacher Save failed"]);

/** @OA\Post(
 *     path="/projects/assets/setHidden.php",
 *     summary="Hide Assets from Quotes or Leave Them Out of Totals",
 *     description="Hide asset assignments from a project's quotes and invoices (they still show on delivery notes), and/or leave them out of the project's totals so they aren't charged for
Requires Instance Permission PROJECTS:PROJECT_ASSETS:EDIT:QUOTE_SECTIONS_AND_LINES to hide, and PROJECTS:PROJECT_ASSETS:EDIT:CUSTOM_PRICE to leave out of totals
",
 *     operationId="setAssetAssignmentHidden",
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
 *             ),
 *         ),
 *     ),
 *     @OA\Response(
 *         response="default",
 *         description="Error",
 *         @OA\MediaType(
 *             mediaType="application/json",
 *             @OA\Schema(
 *                 type="object",
 *                 @OA\Property(
 *                     property="result",
 *                     type="array",
 *                     description="An Array containing objects of the error",
 *                     @OA\Items(type="object")
 *                 ),
 *             ),
 *         ),
 *     ),
 *     @OA\Parameter(
 *         name="assetsAssignments",
 *         in="query",
 *         description="Assignment IDs, all on the same project",
 *         required="true",
 *         @OA\Schema(
 *             type="array",
 *             @OA\Items(type="number")),
 *         ),
 *     @OA\Parameter(
 *         name="assetsAssignments_hidden",
 *         in="query",
 *         description="1 to leave them off quotes and invoices, 0 to show them",
 *         required="false",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 *     @OA\Parameter(
 *         name="assetsAssignments_excludedFromTotals",
 *         in="query",
 *         description="1 to leave them out of the project's totals, 0 to charge for them",
 *         required="false",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 * )
 */
