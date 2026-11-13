<?php
require_once __DIR__ . '/../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("BUSINESS:BUSINESS_SETTINGS:EDIT")) finish(false, ["code" => "AUTH-ERROR", "message" => "No auth for action"]);

//Only what was sent is changed, so the settings page and the generate PDF form can each save their own part
$settings = documentSettingsFromInput(documentSettingsStored(), $_POST);
if (!is_array($settings)) finish(false, ["code" => "PARAM-ERROR", "message" => $settings]);

//The tick boxes to start with when generating one type of document
if (isset($_POST['defaultsType'])) {
    $settings['defaults'] = documentDefaultsFromInput($settings['defaults'], $_POST);
    if ($settings['defaults'] === false) finish(false, ["code" => "PARAM-ERROR", "message" => "Document type not recognised"]);
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
 *         name="intro_invoice",
 *         in="query",
 *         description="Text for the start of an invoice (also intro_quote and intro_deliveryNote). signature_ (the label of a signature line at the end, empty for none) and reference_ (the reference to print, where {project} is the project's number and {version} the document's version) work the same way",
 *         required="false", 
 *         @OA\Schema(
 *             type="string"), 
 *         ), 
 *     @OA\Parameter(
 *         name="logoAlign",
 *         in="query",
 *         description="left, center or right. Also logoSize (small, medium or large), accentColour (#RRGGBB, or empty), dateFormat and timeFormat (PHP date formats from the lists the settings page offers)",
 *         required="false", 
 *         @OA\Schema(
 *             type="string"), 
 *         ), 
 *     @OA\Parameter(
 *         name="label_reference",
 *         in="query",
 *         description="Wording to use in place of Our Reference. Each label on the documents has one, listed on the settings page. Empty for the default",
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
