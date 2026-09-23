<?php
require_once __DIR__ . '/../apiHeadSecure.php';
require_once __DIR__ . '/../../common/libs/bCMS/projectFinance.php';
use Money\Currency;
use Money\Money;
use Money\Currencies\ISOCurrencies;
use Money\Parser\DecimalMoneyParser;

if (!$AUTH->instancePermissionCheck("ASSETS:EDIT")) die("Sorry - you can't access this page");
$array = [];

foreach ($_POST as $key=>$value) {
    if ($key == "formData") continue;
    if ($value == '') $value = null;
    $array[$key] = $value;
}
foreach ($_POST['formData'] as $item) {
    if ($item['value'] == '') $item['value'] = null;
    $array[$item['name']] = $item['value'];
}
if (strlen($array['assets_id']) < 1) finish(false, ["code" => "PARAM-ERROR", "message"=> "No data for action"]);
$currencies = new ISOCurrencies();
$moneyParser = new DecimalMoneyParser($currencies);
//Several different forms post here, each sending only its own fields, so only parse an override the
//request actually sent. Creating the key regardless would put a null into the update below and clear
//a stored override that nobody asked to change. A field that was sent empty is already null by now,
//and still means "clear this override".
foreach (['assets_value', 'assets_dayRate', 'assets_weekRate'] as $moneyField) {
    if (!array_key_exists($moneyField, $array)) continue;
    $array[$moneyField] = ($array[$moneyField] == null ? null : $moneyParser->parse($array[$moneyField], $AUTH->data['instance']['instances_config_currency'])->getAmount());
}

$DBLIB->where("assets_id", $array['assets_id']);
$DBLIB->where("assets.instances_id",$AUTH->data['instance']["instances_id"]);
$DBLIB->join("assetTypes","assets.assetTypes_id=assetTypes.assetTypes_id","LEFT");
$asset = $DBLIB->getone("assets", ['assets.assets_id','assets.assets_dayRate','assets.assets_tag','assets.assets_weekRate','assets.assets_mass','assets.assets_value','assets.assets_unserialized','assets.assets_quantity','assetTypes.assetTypes_mass','assetTypes.assetTypes_value',"assetTypes.assetTypes_dayRate","assetTypes.assetTypes_weekRate"]);
if (!$asset) finish(false, ["code" => "PARAM-ERROR", "message" => "Could not find asset"]);

//Only unserialized assets hold more than a single unit, so turning serialization back on resets the stock to one
if (isset($array['assets_unserialized'])) $array['assets_unserialized'] = ($array['assets_unserialized'] == 1 or $array['assets_unserialized'] === "true" or $array['assets_unserialized'] === "on" ? 1 : 0);
$unserialized = (isset($array['assets_unserialized']) ? $array['assets_unserialized'] : $asset['assets_unserialized']);
if (isset($array['assets_quantity']) or isset($array['assets_unserialized'])) {
    if ($unserialized == 1) {
        $array['assets_quantity'] = (isset($array['assets_quantity']) ? intval($array['assets_quantity']) : intval($asset['assets_quantity']));
        if ($array['assets_quantity'] < 1) finish(false, ["code" => "PARAM-ERROR", "message" => "An unserialized asset must hold at least one unit"]);
    } else $array['assets_quantity'] = 1;
    //Stock can't drop below what's already committed to projects, or those projects would be short
    if ($array['assets_quantity'] < intval($asset['assets_quantity'])) {
        $DBLIB->where("assetsAssignments.assets_id", $asset['assets_id']);
        $DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
        $DBLIB->join("projects", "assetsAssignments.projects_id=projects.projects_id", "LEFT");
        $DBLIB->where("projects.projects_deleted", 0);
        $DBLIB->where("(projects.projects_dates_deliver_end IS NULL OR projects.projects_dates_deliver_end >= '" . date('Y-m-d H:i:s') . "')");
        $assignments = $DBLIB->get("assetsAssignments", null, ["assetsAssignments.projects_id", "assetsAssignments.assetsAssignments_quantity"]);
        $committed = [];
        foreach ($assignments as $assignment) {
            if (!isset($committed[$assignment['projects_id']])) $committed[$assignment['projects_id']] = 0;
            $committed[$assignment['projects_id']] += (intval($assignment['assetsAssignments_quantity']) > 0 ? intval($assignment['assetsAssignments_quantity']) : 1);
        }
        if (count($committed) > 0 and $array['assets_quantity'] < max($committed)) finish(false, ["code" => "PARAM-ERROR", "message" => "This asset already has " . max($committed) . " units assigned to a current project - remove them from that project before reducing the quantity held"]);
    }
}

if (isset($array['assets_tag']) and $array['assets_tag'] != $asset['assets_tag']) {
    $DBLIB->where("assets.instances_id",$AUTH->data['instance']['instances_id']);
    $DBLIB->where("assets.assets_tag", $array['assets_tag']);
    $DBLIB->where("assets.assets_deleted", 0); //Deleted assets can't be restored, so can be used
    $duplicateAssetTag = $DBLIB->getValue ("assets", "count(*)");
    if ($duplicateAssetTag > 0) finish(false,["message" => "Sorry that Asset Tag is a duplicate of one already in your Business"]);
}

$DBLIB->where("assets_id", $array['assets_id']);
$DBLIB->where("assets.instances_id",$AUTH->data['instance']["instances_id"]);
$result = $DBLIB->update("assets", array_intersect_key($array, array_flip(['assets_linkedTo', 'assetTypes_id', 'assets_notes', 'assets_tag', 'asset_definableFields_1', 'asset_definableFields_2', 'asset_definableFields_3', 'asset_definableFields_4', 'asset_definableFields_5', 'asset_definableFields_6', 'asset_definableFields_7', 'asset_definableFields_8', 'asset_definableFields_9', 'asset_definableFields_10', 'assets_value', 'assets_dayRate', 'assets_weekRate', 'assets_mass', 'assets_storageLocation', 'assets_unserialized', 'assets_quantity'])));
if (!$result) finish(false, ["code" => "UPDATE-FAIL", "message"=> "Could not update asset"]);
else {
    //What an asset contributes to a project's cached finances comes from these four overrides, so only
    //a request that sent one of them can have changed those figures. Anything the request left out keeps
    //its stored value, rather than being read as an override that's just been cleared.
    $financeFields = ['assets_mass', 'assets_value', 'assets_dayRate', 'assets_weekRate'];
    $newAsset = $asset;
    $financeChanged = false;
    foreach ($financeFields as $financeField) {
        if (!array_key_exists($financeField, $array)) continue;
        $newAsset[$financeField] = $array[$financeField];
        $financeChanged = true;
    }
    if ($financeChanged) {
        $DBLIB->where("assets_id",$array['assets_id']);
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
            $projectFinanceCacher->adjust('projectsFinanceCache_mass',($asset['assets_mass'] !== null ? $asset['assets_mass'] : $asset['assetTypes_mass']) * $quantity,true);
            $projectFinanceCacher->adjust('projectsFinanceCache_value',(new Money(($asset['assets_value'] !== null ? $asset['assets_value'] : $asset['assetTypes_value']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($quantity),true);

            //Add new mass and value
            $projectFinanceCacher->adjust('projectsFinanceCache_mass',($newAsset['assets_mass'] !== null ? $newAsset['assets_mass'] : $asset['assetTypes_mass']) * $quantity,false);
            $projectFinanceCacher->adjust('projectsFinanceCache_value',(new Money(($newAsset['assets_value'] !== null ? $newAsset['assets_value'] : $asset['assetTypes_value']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($quantity),false);

            if ($assignment['assetsAssignments_customPrice'] > 0) {
                //Old price stands so ignore it
            } else {
                $oldPrice = new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']));
                $oldPrice = $oldPrice->add((new Money(($asset['assets_dayRate'] !== null ? $asset['assets_dayRate'] : $asset['assetTypes_dayRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($priceMaths['days']));
                $oldPrice = $oldPrice->add((new Money(($asset['assets_weekRate'] !== null ? $asset['assets_weekRate'] : $asset['assetTypes_weekRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($priceMaths['weeks']));
                $oldPrice = $oldPrice->multiply($quantity);
                //Price is now manually calculated
                $price = new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']));
                $price = $price->add((new Money(($newAsset['assets_dayRate'] !== null ? $newAsset['assets_dayRate'] : $asset['assetTypes_dayRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($priceMaths['days']));
                $price = $price->add((new Money(($newAsset['assets_weekRate'] !== null ? $newAsset['assets_weekRate'] : $asset['assetTypes_weekRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($priceMaths['weeks']));
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
    $bCMS->auditLog("EDIT-ASSET", "assets", json_encode($array), $AUTH->data['users_userid'],null, $array['assets_id']);
    finish(true);
}

/** @OA\Post(
 *     path="/assets/editAsset.php", 
 *     summary="Edit an Asset", 
 *     description="Edits an asset's data  
Requires Instance Permission ASSETS:EDIT
", 
 *     operationId="editAsset", 
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
 *                     property="message", 
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
 *         description="The data to update the asset with",
 *         required="true", 
 *         @OA\Schema(
 *             type="object", 
 *             @OA\Property(
 *                 property="assets_id", 
 *                 type="integer", 
 *                 description="The ID of the asset to update",
 *             ),
 *             @OA\Property(
 *                 property="assets_value", 
 *                 type="number", 
 *                 description="The value of the asset",
 *             ),
 *             @OA\Property(
 *                 property="assets_dayRate", 
 *                 type="number", 
 *                 description="The day rate of the asset",
 *             ),
 *             @OA\Property(
 *                 property="assets_weekRate", 
 *                 type="number", 
 *                 description="The week rate of the asset",
 *             ),
 *             @OA\Property(
 *                 property="assets_tag", 
 *                 type="string", 
 *                 description="The tag of the asset",
 *             ),
 *             @OA\Property(
 *                 property="assets_mass", 
 *                 type="number", 
 *                 description="The weight of the asset",
 *             ),
 *             @OA\Property(
 *                 property="assets_linkedTo", 
 *                 type="integer", 
 *                 description="The ID of the asset this asset is linked to",
 *             ),
 *             @OA\Property(
 *                 property="assetTypes_id", 
 *                 type="integer", 
 *                 description="The ID of the asset type",
 *             ),
 *             @OA\Property(
 *                 property="assets_notes", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="asset_definableFields_1", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="asset_definableFields_2", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="asset_definableFields_3", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="asset_definableFields_4", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="asset_definableFields_5", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="asset_definableFields_6", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="asset_definableFields_7", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="asset_definableFields_8", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="asset_definableFields_9", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="asset_definableFields_10", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *              @OA\Property(
 *                 property="assets_storageLocation", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *              @OA\Property(
 *                 property="assets_unserialized", 
 *                 type="boolean", 
 *                 description="Whether the asset is unserialized - held as a quantity of interchangeable units rather than as a single tracked item",
 *             ),
 *              @OA\Property(
 *                 property="assets_quantity", 
 *                 type="integer", 
 *                 description="Number of units held, for unserialized assets. Cannot be lower than the number of units already assigned to a current project",
 *             ),
 *         ),
 *     ), 
 * )
 */