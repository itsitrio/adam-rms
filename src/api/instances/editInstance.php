<?php
require_once __DIR__ . '/../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("BUSINESS:BUSINESS_SETTINGS:EDIT")) die("Sorry - you can't access this page");
$array = [];
if (!isset($_POST['formData'])) die("404");
foreach ($_POST['formData'] as $item) {
    if ($item['value'] == '') $item['value'] = null;
    $array[$item['name']] = $item['value'];
}

if (isset($array['instances_termsAndPayment'])) $array['instances_termsAndPayment'] = $bCMS->cleanString($array['instances_termsAndPayment']);
if (isset($array['instances_quoteTerms'])) $array['instances_quoteTerms'] = $bCMS->cleanString($array['instances_quoteTerms']);
if (array_key_exists('instances_config_massUnit', $array) and !in_array($array['instances_config_massUnit'], ["kg", "lb"], true)) unset($array['instances_config_massUnit']);
if (array_key_exists('instances_config_paymentDueDays', $array)) {
    //Days after a project ends that its payment is due
    if (!is_numeric($array['instances_config_paymentDueDays']) or intval($array['instances_config_paymentDueDays']) != $array['instances_config_paymentDueDays'] or $array['instances_config_paymentDueDays'] < 0 or $array['instances_config_paymentDueDays'] > 365) finish(false, ["code" => "PARAM-ERROR", "message" => "Payment terms must be a whole number of days between 0 and 365"]);
    $array['instances_config_paymentDueDays'] = intval($array['instances_config_paymentDueDays']);
}

if (array_key_exists('instances_config_financialYearStart', $array)) {
    //The month the business's financial year starts in
    if (!preg_match('/^\d+$/', (string) $array['instances_config_financialYearStart']) or intval($array['instances_config_financialYearStart']) < 1 or intval($array['instances_config_financialYearStart']) > 12) finish(false, ["code" => "PARAM-ERROR", "message" => "The financial year must start in a month from 1 to 12"]);
    $array['instances_config_financialYearStart'] = intval($array['instances_config_financialYearStart']);
}
if (array_key_exists('instances_config_depreciationMonths', $array)) {
    //How long assets are depreciated over, unless they have their own
    if (!preg_match('/^\d+$/', (string) $array['instances_config_depreciationMonths']) or intval($array['instances_config_depreciationMonths']) < 1 or intval($array['instances_config_depreciationMonths']) > 600) finish(false, ["code" => "PARAM-ERROR", "message" => "Depreciate assets over between 1 and 600 months"]);
    $array['instances_config_depreciationMonths'] = intval($array['instances_config_depreciationMonths']);
}

$DBLIB->where("instances_id",$AUTH->data['instance']["instances_id"]);
$result = $DBLIB->update("instances", array_intersect_key( $array, array_flip( ["instances_config_massUnit","instances_config_paymentDueDays","instances_config_financialYearStart","instances_config_depreciationMonths","instances_name","instances_address","instances_phone","instances_email","instances_website","instances_weekStartDates","instances_logo","instances_emailHeader","instances_termsAndPayment", "instances_quoteTerms", "instances_cableColours"] ) ));
echo $DBLIB->getLastError();
if (!$result) finish(false, ["code" => "UPDATE-FAIL", "message"=> "Could not update instance"]);
else {
    $bCMS->auditLog("EDIT-INSTANCE", "instances", json_encode($array), $AUTH->data['users_userid'],null, $AUTH->data['instance']["instances_id"]);
    finish(true);
}

/** @OA\Post(
 *     path="/instances/editInstance.php", 
 *     summary="Edit Instance", 
 *     description="Edit an instance  
Requires Instance Permission BUSINESS:BUSINESS_SETTINGS:EDIT
", 
 *     operationId="editInstance", 
 *     tags={"instances"}, 
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
 *                     type="array", 
 *                     description="A null Array",
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
 *             ),
 *         ),
 *     ), 
 *     @OA\Parameter(
 *         name="formData",
 *         in="query",
 *         description="The instance data",
 *         required="true", 
 *         @OA\Schema(
 *             type="object", 
 *             @OA\Property(
 *                 property="instances_name", 
 *                 type="string", 
 *                 description="The instance name",
 *             ),
 *             @OA\Property(
 *                 property="instances_address", 
 *                 type="string", 
 *                 description="The instance address",
 *             ),
 *             @OA\Property(
 *                 property="instances_phone", 
 *                 type="string", 
 *                 description="The instance phone number",
 *             ),
 *             @OA\Property(
 *                 property="instances_email", 
 *                 type="string", 
 *                 description="The instance email",
 *             ),
 *             @OA\Property(
 *                 property="instances_website", 
 *                 type="string", 
 *                 description="The instance website",
 *             ),
 *             @OA\Property(
 *                 property="instances_weekStartDates", 
 *                 type="string", 
 *                 description="When the Instance's calendar start dates are",
 *             ),
 *             @OA\Property(
 *                 property="instances_logo", 
 *                 type="number", 
 *                 description="The file id for the instance logo",
 *             ),
 *             @OA\Property(
 *                 property="instances_emailHeader", 
 *                 type="number", 
 *                 description="The file id for the instance email header image",
 *             ),
 *             @OA\Property(
 *                 property="instances_termsAndPayment", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="instances_quoteTerms", 
 *                 type="string", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="instances_cableColours", 
 *                 type="json", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="instances_publicConfig", 
 *                 type="json", 
 *                 description="undefined",
 *             ),
 *             @OA\Property(
 *                 property="instances_trustedDomains", 
 *                 type="json", 
 *                 description="undefined",
 *             ),
 *         ),
 *     ), 
 * )
 */