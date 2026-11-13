<?php
require_once __DIR__ . '/../../apiHeadSecure.php';
require_once __DIR__ . '/../../../common/libs/bCMS/projectFinance.php';

use Money\Currency;
use Money\Money;
use Money\Currencies\ISOCurrencies;
use Money\Parser\DecimalMoneyParser;

if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:EDIT:QUOTE_SECTIONS_AND_LINES")) die("404");
if (!isset($_POST['projectsQuoteLines_id'])) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);

$DBLIB->where("projectsQuoteLines.projectsQuoteLines_id", $_POST['projectsQuoteLines_id']);
$DBLIB->where("projectsQuoteLines.projectsQuoteLines_deleted", 0);
$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->join("projects", "projectsQuoteLines.projects_id=projects.projects_id", "LEFT");
$line = $DBLIB->getone("projectsQuoteLines", ["projectsQuoteLines.*"]);
if (!$line) finish(false, ["message" => "Cannot find note line"]);

$update = [];
if (isset($_POST['projectsQuoteLines_text'])) {
    if (strlen(trim($_POST['projectsQuoteLines_text'])) < 1) finish(false, ["message" => "A note line needs some text"]);
    $update['projectsQuoteLines_text'] = substr(trim($_POST['projectsQuoteLines_text']), 0, 1000);
}
if (isset($_POST['projectsQuoteSections_id'])) {
    if (intval($_POST['projectsQuoteSections_id']) > 0) {
        $DBLIB->where("projectsQuoteSections_id", $_POST['projectsQuoteSections_id']);
        $DBLIB->where("projects_id", $line['projects_id']);
        $DBLIB->where("projectsQuoteSections_deleted", 0);
        $section = $DBLIB->getone("projectsQuoteSections", ["projectsQuoteSections_id"]);
        if (!$section) finish(false, ["message" => "Cannot find section"]);
        $update['projectsQuoteSections_id'] = $section['projectsQuoteSections_id'];
    } else $update['projectsQuoteSections_id'] = null;
}
if (isset($_POST['projectsQuoteLines_quantity'])) {
    if (intval($_POST['projectsQuoteLines_quantity']) < 1) finish(false, ["message" => "Quantity must be at least one"]);
    $update['projectsQuoteLines_quantity'] = intval($_POST['projectsQuoteLines_quantity']);
}
if (isset($_POST['projectsQuoteLines_price'])) {
    try {
        $moneyParser = new DecimalMoneyParser(new ISOCurrencies());
        $update['projectsQuoteLines_price'] = $moneyParser->parse(strlen(trim($_POST['projectsQuoteLines_price'])) > 0 ? trim($_POST['projectsQuoteLines_price']) : "0", new Currency($AUTH->data['instance']['instances_config_currency']))->getAmount();
    } catch (Exception $e) {
        finish(false, ["message" => "Price isn't a valid amount"]);
    }
}
if (count($update) < 1) finish(true);

$DBLIB->where("projectsQuoteLines_id", $line['projectsQuoteLines_id']);
if (!$DBLIB->update("projectsQuoteLines", $update)) finish(false, ["message" => "Could not update note line"]);

//Move the project's cached total from the line's old price to its new one
$oldTotal = (new Money($line['projectsQuoteLines_price'], new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply(max(1, intval($line['projectsQuoteLines_quantity'])));
$line = array_merge($line, $update);
$newTotal = (new Money($line['projectsQuoteLines_price'], new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply(max(1, intval($line['projectsQuoteLines_quantity'])));
$projectFinanceCacher = new projectFinanceCacher($line['projects_id']);
if (!$oldTotal->equals($newTotal)) $projectFinanceCacher->adjust('projectsFinanceCache_equipmentSubTotal', $newTotal->subtract($oldTotal));
$bCMS->auditLog("UPDATE", "projectsQuoteLines", json_encode($update), $AUTH->data['users_userid'], null, $line['projects_id']);

if ($projectFinanceCacher->save()) finish(true);
else finish(false, ["message" => "Finance Cacher Save failed"]);

/** @OA\Post(
 *     path="/projects/quote/editLine.php",
 *     summary="Edit Quote Note Line",
 *     description="Change a quote note line's text, section, quantity or price. Only the fields given are changed
Requires Instance Permission PROJECTS:PROJECT_ASSETS:EDIT:QUOTE_SECTIONS_AND_LINES
",
 *     operationId="editQuoteLine",
 *     tags={"project_quote"},
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
 *         name="projectsQuoteLines_id",
 *         in="query",
 *         description="Quote Note Line ID",
 *         required="true",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 *     @OA\Parameter(
 *         name="projectsQuoteLines_text",
 *         in="query",
 *         description="Text of the line",
 *         required="false",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 *     @OA\Parameter(
 *         name="projectsQuoteSections_id",
 *         in="query",
 *         description="Section to show the line in, or -1 to show it at the end of the quote",
 *         required="false",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 *     @OA\Parameter(
 *         name="projectsQuoteLines_quantity",
 *         in="query",
 *         description="Quantity",
 *         required="false",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 *     @OA\Parameter(
 *         name="projectsQuoteLines_price",
 *         in="query",
 *         description="Price of one unit as a decimal (e.g. 12.50)",
 *         required="false",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 * )
 */
