<?php
require_once __DIR__ . '/../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("BUSINESS:BUSINESS_SETTINGS:EDIT")) finish(false, ["code" => "AUTH-ERROR", "message" => "No auth for action"]);

//Only what was sent is changed, so the settings page and the generate PDF form can each save their own part
$settings = documentSettings();
$text = function ($key, $limit) {
    if (!isset($_POST[$key]) or !is_string($_POST[$key])) return null;
    return mb_substr(trim($_POST[$key]), 0, $limit);
};

if (isset($_POST['pageSize'])) {
    if (!in_array($_POST['pageSize'], ["A4", "LETTER"], true)) finish(false, ["code" => "PARAM-ERROR", "message" => "Page size not recognised"]);
    $settings['pageSize'] = $_POST['pageSize'];
}
foreach (["headerText" => 100, "footerText" => 100, "deliveryNoteFooter" => 5000] as $key => $limit) {
    if ($text($key, $limit) !== null) $settings[$key] = $text($key, $limit);
}
if (isset($_POST['firstPage'])) $settings['firstPage'] = ($_POST['firstPage'] === "true" or $_POST['firstPage'] === "1");
foreach (DOCUMENT_TYPES as $type) {
    if ($text("name_" . $type, 50) !== null) $settings['names'][$type] = $text("name_" . $type, 50);
}

//The tick boxes to start with when generating one type of document
if (isset($_POST['defaultsType'])) {
    if (!in_array($_POST['defaultsType'], DOCUMENT_TYPES, true)) finish(false, ["code" => "PARAM-ERROR", "message" => "Document type not recognised"]);
    if (isset($_POST['defaultsReset'])) $settings['defaults'][$_POST['defaultsType']] = [];
    if (isset($_POST['defaults']) and is_array($_POST['defaults'])) {
        foreach (DOCUMENT_OPTIONS as $option) {
            if (isset($_POST['defaults'][$option]) and is_string($_POST['defaults'][$option])) $settings['defaults'][$_POST['defaultsType']][$option] = ($_POST['defaults'][$option] === "true" or $_POST['defaults'][$option] === "1");
        }
    }
}

$DBLIB->where("instances_id", $AUTH->data['instance']["instances_id"]);
$result = $DBLIB->update("instances", ["instances_config_documents" => json_encode($settings)]);
if (!$result) finish(false, ["code" => "UPDATE-FAIL", "message" => "Could not update document settings"]);

$bCMS->auditLog("EDIT-INSTANCE-DOCUMENTS", "instances", json_encode($settings), $AUTH->data['users_userid'], null, null, $AUTH->data['instance']["instances_id"]);
finish(true);

/** @OA\Post(
 *     path="/instances/editInstanceDocuments.php", 
 *     summary="Edit Document Settings", 
 *     description="Edit a business's settings for generated documents (quotes, invoices and delivery notes). Only the parameters sent are changed.  
Requires Instance Permission BUSINESS:BUSINESS_SETTINGS:EDIT
", 
 *     operationId="editInstanceDocuments", 
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
 *             ),
 *         ),
 *     ), 
 *     @OA\Parameter(
 *         name="pageSize",
 *         in="query",
 *         description="A4 or LETTER",
 *         required="false", 
 *         @OA\Schema(
 *             type="string"), 
 *         ), 
 *     @OA\Parameter(
 *         name="headerText",
 *         in="query",
 *         description="Text for the left of the page header, in place of the business's name",
 *         required="false", 
 *         @OA\Schema(
 *             type="string"), 
 *         ), 
 *     @OA\Parameter(
 *         name="footerText",
 *         in="query",
 *         description="Text for the right of the page footer",
 *         required="false", 
 *         @OA\Schema(
 *             type="string"), 
 *         ), 
 *     @OA\Parameter(
 *         name="firstPage",
 *         in="query",
 *         description="Whether the page header and footer are shown on the first page too",
 *         required="false", 
 *         @OA\Schema(
 *             type="boolean"), 
 *         ), 
 *     @OA\Parameter(
 *         name="deliveryNoteFooter",
 *         in="query",
 *         description="Text for the end of a delivery note",
 *         required="false", 
 *         @OA\Schema(
 *             type="string"), 
 *         ), 
 *     @OA\Parameter(
 *         name="name_invoice",
 *         in="query",
 *         description="What invoices are called (also name_quote and name_deliveryNote). Empty for the default",
 *         required="false", 
 *         @OA\Schema(
 *             type="string"), 
 *         ), 
 *     @OA\Parameter(
 *         name="defaultsType",
 *         in="query",
 *         description="invoice, quote or deliveryNote - the type of document the defaults parameter is for",
 *         required="false", 
 *         @OA\Schema(
 *             type="string"), 
 *         ), 
 *     @OA\Parameter(
 *         name="defaults",
 *         in="query",
 *         description="The options to tick by default when generating that type of document, as option name => true/false",
 *         required="false", 
 *         @OA\Schema(
 *             type="object"), 
 *         ), 
 *     @OA\Parameter(
 *         name="defaultsReset",
 *         in="query",
 *         description="Forget the defaults saved for that type of document first",
 *         required="false", 
 *         @OA\Schema(
 *             type="boolean"), 
 *         ), 
 * )
 */
