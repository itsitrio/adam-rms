<?php
/**
 * API
 * \projects\assets\setQuantity.php
 * Changes how many units of an unserialized asset an assignment takes
 *
 * Arguments:
 *  - assetsAssignments_id: the assignment to change
 *  - assetsAssignments_quantity: the number of units it should take
 */

require_once __DIR__ . '/../../apiHeadSecure.php';
require_once __DIR__ . '/../../../common/libs/bCMS/projectFinance.php';

use Money\Currency;
use Money\Money;

if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_AND_UNASSIGN") or !isset($_POST['assetsAssignments_id']) or !isset($_POST['assetsAssignments_quantity'])) die("404");

$quantity = intval($_POST['assetsAssignments_quantity']);
if ($quantity < 1) finish(false, ["message" => "Quantity must be at least one"]);

$DBLIB->where("assetsAssignments.assetsAssignments_id", $_POST['assetsAssignments_id']);
$DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
$DBLIB->where("projects.instances_id", $AUTH->data['instance_ids'], 'IN');
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("assets.assets_deleted", 0);
$DBLIB->join("projects", "assetsAssignments.projects_id=projects.projects_id", "LEFT");
$DBLIB->join("assets", "assetsAssignments.assets_id=assets.assets_id", "LEFT");
$DBLIB->join("assetTypes", "assets.assetTypes_id=assetTypes.assetTypes_id", "LEFT");
$assignment = $DBLIB->getone("assetsAssignments", ["assetsAssignments.assetsAssignments_id", "assetsAssignments.assetsAssignments_quantity", "assetsAssignments.assetsAssignments_customPrice", "assetsAssignments.assetsAssignments_discount", "assetsAssignments.projects_id", "projects.projects_dates_deliver_start", "projects.projects_dates_deliver_end", "assets.assets_id", "assets.assets_unserialized", "assets.assets_quantity", "assets.assets_dayRate", "assets.assets_weekRate", "assets.assets_mass", "assets.assets_value", "assetTypes.assetTypes_dayRate", "assetTypes.assetTypes_weekRate", "assetTypes.assetTypes_mass", "assetTypes.assetTypes_value"]);
if (!$assignment) finish(false, ["message" => "Could not find assignment"]);
if ($assignment['assets_unserialized'] != 1 and $quantity != 1) finish(false, ["message" => "Only unserialized assets can be assigned in quantities - assign another asset of this type instead"]);

$oldQuantity = (intval($assignment['assetsAssignments_quantity']) > 0 ? intval($assignment['assetsAssignments_quantity']) : 1);
if ($quantity == $oldQuantity) finish(true); //Nothing to do, and nothing to adjust the project's finances by

//Taking more units means there have to be that many spare, counting everything else this assignment's project already holds
if ($quantity > $oldQuantity) {
    $availability = assetAvailableQuantity($assignment, $assignment['projects_dates_deliver_start'], $assignment['projects_dates_deliver_end'], $assignment['projects_id'], $assignment['assetsAssignments_id']);
    if ($availability['available'] < $quantity) finish(false, ["message" => "Only " . $availability['available'] . " of the " . $availability['quantity'] . " units held are available"]);
}

$DBLIB->where("assetsAssignments_id", $assignment['assetsAssignments_id']);
if (!$DBLIB->update("assetsAssignments", ["assetsAssignments_quantity" => $quantity])) finish(false, ["message" => "Could not update assignment"]);

//Everything an assignment contributes is per unit, so the project's cached finances move by the difference
$projectFinanceHelper = new projectFinance();
$priceMaths = $projectFinanceHelper->durationMaths($assignment['projects_id']);
$projectFinanceCacher = new projectFinanceCacher($assignment['projects_id']);
$difference = $quantity - $oldQuantity;

$projectFinanceCacher->adjust('projectsFinanceCache_mass', ($assignment['assets_mass'] !== null ? $assignment['assets_mass'] : $assignment['assetTypes_mass']) * abs($difference), $difference < 0);
$projectFinanceCacher->adjust('projectsFinanceCache_value', (new Money(($assignment['assets_value'] !== null ? $assignment['assets_value'] : $assignment['assetTypes_value']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply(abs($difference)), $difference < 0);

if ($assignment['assetsAssignments_customPrice'] > 0) {
    $price = new Money($assignment['assetsAssignments_customPrice'], new Currency($AUTH->data['instance']['instances_config_currency']));
} else {
    $price = new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']));
    $price = $price->add((new Money(($assignment['assets_dayRate'] !== null ? $assignment['assets_dayRate'] : $assignment['assetTypes_dayRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($priceMaths['days']));
    $price = $price->add((new Money(($assignment['assets_weekRate'] !== null ? $assignment['assets_weekRate'] : $assignment['assetTypes_weekRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($priceMaths['weeks']));
}
$price = $price->multiply(abs($difference));
$projectFinanceCacher->adjust('projectsFinanceCache_equipmentSubTotal', $price, $difference < 0);
if ($assignment['assetsAssignments_discount'] > 0) $projectFinanceCacher->adjust('projectsFinanceCache_equiptmentDiscounts', $price->subtract($price->multiply(1 - ($assignment['assetsAssignments_discount'] / 100))), $difference < 0);

$bCMS->auditLog("EDIT-ASSIGNMENT-QUANTITY", "assetsAssignments", $assignment['assetsAssignments_id'], $AUTH->data['users_userid'], null, $assignment['projects_id']);

if ($projectFinanceCacher->save()) finish(true, null, ["assetsAssignments_quantity" => $quantity]);
else finish(false, ["message" => "Finance Cacher Save failed"]);

/** @OA\Post(
 *     path="/projects/assets/setQuantity.php", 
 *     summary="Set Asset Assignment Quantity", 
 *     description="Changes how many units of an unserialized asset an assignment takes  
Requires Instance Permission PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_AND_UNASSIGN
", 
 *     operationId="setAssetAssignmentQuantity", 
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
 *         response="404", 
 *         description="Permission Error",
 *     ), 
 *     @OA\Parameter(
 *         name="assetsAssignments_id",
 *         in="query",
 *         description="Asset Assignment ID",
 *         required="true", 
 *         @OA\Schema(
 *             type="number"), 
 *         ), 
 *     @OA\Parameter(
 *         name="assetsAssignments_quantity",
 *         in="query",
 *         description="Number of units the assignment should take",
 *         required="true", 
 *         @OA\Schema(
 *             type="number"), 
 *         ), 
 * )
 */
