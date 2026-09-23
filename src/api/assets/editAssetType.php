<?php
require_once __DIR__ . '/../apiHeadSecure.php';
require_once __DIR__ . '/../../common/libs/bCMS/projectFinance.php';
use Money\Currency;
use Money\Money;
use Money\Currencies\ISOCurrencies;
use Money\Parser\DecimalMoneyParser;
if (!$AUTH->instancePermissionCheck("ASSETS:ASSET_TYPES:EDIT")) die("Sorry - you can't access this page");

$array = [];
foreach ($_POST['formData'] as $item) {
    if ($item['value'] == '') $item['value'] = null;
    $array[$item['name']] = $item['value'];
}
if (strlen($array['assetTypes_id']) <1) finish(false, ["code" => "PARAM-ERROR", "message"=> "No data for action"]);
$currencies = new ISOCurrencies();
$moneyParser = new DecimalMoneyParser($currencies);
$array['assetTypes_value'] = $moneyParser->parse(($array['assetTypes_value'] ?? "0.00"), $AUTH->data['instance']['instances_config_currency'])->getAmount();
$array['assetTypes_dayRate'] = $moneyParser->parse(($array['assetTypes_dayRate'] ?? '0.00'), $AUTH->data['instance']['instances_config_currency'])->getAmount();
$array['assetTypes_weekRate'] = $moneyParser->parse(($array['assetTypes_weekRate'] ?? '0.00'), $AUTH->data['instance']['instances_config_currency'])->getAmount();

if (!$AUTH->serverPermissionCheck("ASSETS:EDIT:ANY_ASSET_TYPE")) {
    $DBLIB->where("(instances_id IS NOT NULL)");
    $DBLIB->where("instances_id",$AUTH->data['instance']["instances_id"]);
}
$DBLIB->where("assetTypes_id", $array['assetTypes_id']);
$assetType = $DBLIB->getone("assetTypes", ['assetTypes.assetTypes_mass','assetTypes.assetTypes_value',"assetTypes.assetTypes_dayRate","assetTypes.assetTypes_weekRate"]);
if (!$assetType) finish(false);

$DBLIB->where("assetTypes_id",$array['assetTypes_id']);
$result = $DBLIB->update("assetTypes", array_intersect_key( $array, array_flip( ['assetTypes_name','assetCategories_id','assetTypes_productLink','manufacturers_id','assetTypes_description','assetTypes_definableFields','assetTypes_mass','assetTypes_inserted',"assetTypes_dayRate","assetTypes_weekRate","assetTypes_value"] ) ));
if (!$result) finish(false, ["code" => "UPDATE-FAIL", "message"=> "Could not update asset type"]);
else {
    $bCMS->auditLog("EDIT-ASSET-TYPE", "assetTypes", json_encode($array), $AUTH->data['users_userid'],null, $array['assetTypes_id']);

    $DBLIB->where("assets.assetTypes_id", $array['assetTypes_id']);
    $DBLIB->where("assets.instances_id",$AUTH->data['instance']["instances_id"]);
    $assets = $DBLIB->get("assets", null,['assets.assets_id','assets.assets_dayRate','assets.assets_weekRate','assets.assets_mass','assets.assets_value']);
    foreach ($assets as $asset) {
        $DBLIB->where("assets_id",$asset['assets_id']);
        $DBLIB->where("assetsAssignments_deleted",0);
        $DBLIB->join("projects","assetsAssignments.projects_id=projects.projects_id","LEFT");
        $assetAssignments = $DBLIB->get("assetsAssignments",null,['projects.projects_id','assetsAssignments_id','assetsAssignments_customPrice','assetsAssignments_discount','assetsAssignments_quantity']);
        foreach ($assetAssignments as $assignment) {
            $projectFinanceHelper = new projectFinance();
            $priceMaths = $projectFinanceHelper->durationMaths($assignment['projects_id']);
            $projectFinanceCacher = new projectFinanceCacher($assignment['projects_id']);
            //Everything an assignment contributes is per unit, so scales with the number of units taken
            $quantity = (intval($assignment['assetsAssignments_quantity']) > 0 ? intval($assignment['assetsAssignments_quantity']) : 1);
            //Remove current mass and value
            if ($asset['assets_mass'] == null) {
                $projectFinanceCacher->adjust('projectsFinanceCache_mass',$assetType['assetTypes_mass'] * $quantity,true);
                $projectFinanceCacher->adjust('projectsFinanceCache_mass',$array['assetTypes_mass'] * $quantity,false);
            }
            if ($asset['assets_value'] == null) {
                $projectFinanceCacher->adjust('projectsFinanceCache_value',(new Money($assetType['assetTypes_value'], new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($quantity),true);
                $projectFinanceCacher->adjust('projectsFinanceCache_value',(new Money($array['assetTypes_value'], new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($quantity),false);
            }

            if ($assignment['assetsAssignments_customPrice'] > 0) {
                //Old price stands so ignore it
            } else {
                $oldPrice = new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']));
                $oldPrice = $oldPrice->add((new Money(($asset['assets_dayRate'] !== null ? $asset['assets_dayRate'] : $assetType['assetTypes_dayRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($priceMaths['days']));
                $oldPrice = $oldPrice->add((new Money(($asset['assets_weekRate'] !== null ? $asset['assets_weekRate'] : $assetType['assetTypes_weekRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($priceMaths['weeks']));
                $oldPrice = $oldPrice->multiply($quantity);
                //Price is now manually calculated
                $price = new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']));
                $price = $price->add((new Money(($asset['assets_dayRate'] !== null ? $asset['assets_dayRate'] : $array['assetTypes_dayRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($priceMaths['days']));
                $price = $price->add((new Money(($asset['assets_weekRate'] !== null ? $asset['assets_weekRate'] : $array['assetTypes_weekRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($priceMaths['weeks']));
                $price = $price->multiply($quantity);

                //Remove the old price
                $projectFinanceCacher->adjust('projectsFinanceCache_equipmentSubTotal', $oldPrice,true);
                $projectFinanceCacher->adjust('projectsFinanceCache_equipmentSubTotal', $price,false);

                if ($assignment['assetsAssignments_discount'] > 0) {
                    //If there was already a discount, remove it, then add it again
                    $projectFinanceCacher->adjust('projectsFinanceCache_equiptmentDiscounts', $oldPrice->subtract($oldPrice->multiply(1 - ($assignment['assetsAssignments_discount'] / 100))),true);
                    $projectFinanceCacher->adjust('projectsFinanceCache_equiptmentDiscounts', $price->subtract($price->multiply(1 - ($assignment['assetsAssignments_discount'] / 100))), false);
                }
            }
            $projectFinanceCacher->save();
        }
    }
    finish(true);
}

/** @OA\Post(
 *     path="/assets/editAssetType.php", 
 *     summary="Edit an Asset Type", 
 *     description="Edits an asset type's data  
Requires Instance Permission ASSETS:ASSET_TYPES:EDIT
", 
 *     operationId="editAssetType", 
 *     tags={"assets"}, 
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
 *                     type="null", 
 *                     description="an empty array",
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
 *                     type="boolean", 
 *                     description="Whether the request was successful",
 *                 ),
 *                 @OA\Property(
 *                     property="error", 
 *                     type="array", 
 *                     description="An Array containing an error code and a message",
 *                 ),
 *             ),
 *         ),
 *     ), 
 *     @OA\Parameter(
 *         name="formData",
 *         in="query",
 *         description="The data to update the asset type with",
 *         required="true", 
 *         @OA\Schema(
 *             type="object", 
 *             @OA\Property(
 *                 property="assetTypes_id", 
 *                 type="integer", 
 *                 description="The ID of the asset type to update",
 *             ),
 *             @OA\Property(
 *                 property="assetTypes_name", 
 *                 type="string", 
 *                 description="The name of the asset type",
 *             ),
 *             @OA\Property(
 *                 property="assetCategories_id", 
 *                 type="integer", 
 *                 description="The ID of the asset category",
 *             ),
 *             @OA\Property(
 *                 property="assetTypes_productLink", 
 *                 type="string", 
 *                 description="Link to the website of the asset type",
 *             ),
 *             @OA\Property(
 *                 property="manufacturers_id", 
 *                 type="integer", 
 *                 description="The ID of the manufacturer",
 *             ),
 *             @OA\Property(
 *                 property="assetTypes_description", 
 *                 type="string", 
 *                 description="The description of the asset type",
 *             ),
 *             @OA\Property(
 *                 property="assetTypes_definableFields", 
 *                 type="string", 
 *                 description="A comma-separated list of 10 definable field names",
 *             ),
 *             @OA\Property(
 *                 property="assetTypes_mass", 
 *                 type="number", 
 *                 description="The weight of the asset type",
 *             ),
 *             @OA\Property(
 *                 property="assetTypes_inserted", 
 *                 type="string", 
 *                 description="The date the asset type was inserted",
 *             ),
 *             @OA\Property(
 *                 property="assetTypes_value", 
 *                 type="string", 
 *                 description="The value of the asset type",
 *             ),
 *             @OA\Property(
 *                 property="assetTypes_dayRate", 
 *                 type="number", 
 *                 description="The day rate of the asset type",
 *             ),
 *             @OA\Property(
 *                 property="assetTypes_weekRate", 
 *                 type="number", 
 *                 description="The week rate of the asset type",
 *             ),
 *         ),
 *     ), 
 * )
 */