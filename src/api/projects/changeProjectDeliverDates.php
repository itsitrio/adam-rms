<?php
require_once __DIR__ . '/../apiHeadSecure.php';
require_once __DIR__ . '/../../common/libs/bCMS/projectFinance.php';
use Money\Currency;
use Money\Money;

if (!$AUTH->instancePermissionCheck("PROJECTS:EDIT:DATES") or !isset($_POST['projects_id'])) die("404");
$newDates = ["projects_dates_deliver_start" => date ("Y-m-d H:i:s", strtotime($_POST['projects_dates_deliver_start'])), "projects_dates_deliver_end" => date ("Y-m-d H:i:s", strtotime($_POST['projects_dates_deliver_end']))];

$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("projects.projects_id", $_POST['projects_id']);
$project = $DBLIB->getone("projects", ["projects_id","projects_dates_deliver_start","projects_dates_deliver_end","projects_dates_finances_days", "projects_dates_finances_weeks"]);
if (!$project) finish(false);

$projectFinanceHelper = new projectFinance();
$projectFinanceCacher = new projectFinanceCacher($project['projects_id']);
if ($project['projects_dates_finances_days'] !== NULL and $project['projects_dates_finances_weeks'] !== NULL) {
    // Custom price maths is set, so changing the dates will have no impact on pricing anyway
    $priceMathsOld = $projectFinanceHelper->durationMaths($project['projects_id']);
    $priceMathsNew = $priceMathsOld;
} else {
    // We need to calculate the price maths because custom price maths is not set
    $priceMathsOld = $projectFinanceHelper->durationMaths($project['projects_id']);
    $priceMathsNew = $projectFinanceHelper->durationMathsByDates($newDates['projects_dates_deliver_start'],$newDates['projects_dates_deliver_end']);
}


//We're changing dates so we need to find clashes in the new dates
$DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
$DBLIB->where("assetsAssignments.projects_id", $project['projects_id']);
$DBLIB->join("assets","assetsAssignments.assets_id=assets.assets_id", "LEFT");
$DBLIB->join("assetTypes", "assets.assetTypes_id=assetTypes.assetTypes_id", "LEFT");
$assets = $DBLIB->get("assetsAssignments", null, ["assetsAssignments.assets_id", "assetsAssignments.assetsAssignments_id","assetsAssignments_customPrice","assetsAssignments_discount","assetsAssignments_quantity","assetTypes_weekRate","assetTypes_dayRate","assets_dayRate","assets_weekRate","assets_unserialized","assets_quantity","assets_tag","assetTypes_name"]);
if ($assets) {
    $unavailableAssets = [];
    foreach ($assets as $asset) {
        //This project's own claim on the asset moves with it, so leave it out and see whether what's left still covers the units it needs
        $quantity = (intval($asset['assetsAssignments_quantity']) > 0 ? intval($asset['assetsAssignments_quantity']) : 1);
        $availability = assetAvailableQuantity($asset, $newDates["projects_dates_deliver_start"], $newDates["projects_dates_deliver_end"], null, $asset['assetsAssignments_id']);
        if ($availability['available'] < $quantity) {
            $clash = (count($availability['assignments']) > 0 ? $availability['assignments'][0] : []);
            $unavailableAssets[] = [
                "assetsAssignments_id" => (isset($clash['assetsAssignments_id']) ? $clash['assetsAssignments_id'] : null),
                "assets_id" => $asset['assets_id'],
                "projects_id" => (isset($clash['projects_id']) ? $clash['projects_id'] : null),
                "assetTypes_name" => $asset['assetTypes_name'],
                "projects_name" => (isset($clash['projects_name']) ? $clash['projects_name'] : ""),
                "assets_tag" => $asset['assets_tag'],
                "old_assetsAssignments_id" => $asset['assetsAssignments_id']
            ];
        }
    }
    if (count($unavailableAssets) > 0) {
        finish(true, null, ["changed" => false, "assets" => $unavailableAssets]);
    } else {
        foreach ($assets as $asset) {
            //This change is going to go ahead so re-calculate finance
            if ($asset['assetsAssignments_customPrice'] != null) continue; //There is a custom price set - so this asset is date agnostic anyway
            //Rates are per unit, and an assignment can take several units of an unserialized asset
            $quantity = (intval($asset['assetsAssignments_quantity']) > 0 ? intval($asset['assetsAssignments_quantity']) : 1);

            $priceOriginal = new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']));
            $priceOriginal = $priceOriginal->add((new Money(($asset['assets_dayRate'] !== null ? $asset['assets_dayRate'] : $asset['assetTypes_dayRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($priceMathsOld['days']));
            $priceOriginal = $priceOriginal->add((new Money(($asset['assets_weekRate'] !== null ? $asset['assets_weekRate'] : $asset['assetTypes_weekRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($priceMathsOld['weeks']));
            $priceOriginal = $priceOriginal->multiply($quantity);
            $projectFinanceCacher->adjust('projectsFinanceCache_equipmentSubTotal', $priceOriginal,true);

            $price = new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']));
            $price = $price->add((new Money(($asset['assets_dayRate'] !== null ? $asset['assets_dayRate'] : $asset['assetTypes_dayRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($priceMathsNew['days']));
            $price = $price->add((new Money(($asset['assets_weekRate'] !== null ? $asset['assets_weekRate'] : $asset['assetTypes_weekRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($priceMathsNew['weeks']));
            $price = $price->multiply($quantity);
            $projectFinanceCacher->adjust('projectsFinanceCache_equipmentSubTotal', $price,false);

            if ($asset['assetsAssignments_discount'] > 0) {
                //Remove old discount
                $projectFinanceCacher->adjust('projectsFinanceCache_equiptmentDiscounts', $priceOriginal->subtract($priceOriginal->multiply(1 - ($asset['assetsAssignments_discount'] / 100))),true);
                //Set a new discount
                $projectFinanceCacher->adjust('projectsFinanceCache_equiptmentDiscounts', $price->subtract($price->multiply(1 - ($asset['assetsAssignments_discount'] / 100))), false);
            }
        }
    }
}

if ($projectFinanceCacher->save()) {
    $DBLIB->where("projects.projects_id", $project['projects_id']);
    $projectUpdate = $DBLIB->update("projects", $newDates);
    if (!$projectUpdate) finish(false);
    $bCMS->auditLog("CHANGE-DATE", "projects", "Set the deliver start date to ". date ("D jS M Y h:i:sa", strtotime($_POST['projects_dates_deliver_start'])) . "\nSet the deliver end date to ". date ("D jS M Y h:i:sa", strtotime($_POST['projects_dates_deliver_end'])), $AUTH->data['users_userid'],null, $_POST['projects_id']);
    finish(true, null, ["changed" => true]);
} else finish(false, ["message"=>"Cannot modify finances to change dates"]);

/** @OA\Post(
 *     path="/projects/changeProjectDeliverDates.php", 
 *     summary="Change Project Deliver Dates", 
 *     description="Change the start and end deliver dates of a project  
Requires Instance Permission PROJECTS:EDIT:DATES
", 
 *     operationId="changeProjectDeliverDates", 
 *     tags={"projects"}, 
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
 *         name="projects_id",
 *         in="query",
 *         description="Project ID",
 *         required="true", 
 *         @OA\Schema(
 *             type="number"), 
 *         ), 
 *     @OA\Parameter(
 *         name="projects_dates_deliver_start",
 *         in="query",
 *         description="Start Date/Time",
 *         required="true", 
 *         @OA\Schema(
 *             type="string"), 
 *         ), 
 *     @OA\Parameter(
 *         name="projects_dates_deliver_end",
 *         in="query",
 *         description="End Date/Time",
 *         required="true", 
 *         @OA\Schema(
 *             type="string"), 
 *         ), 
 * )
 */