<?php
require_once __DIR__ . '/../../apiHeadSecure.php';
require_once __DIR__ . '/../../../common/libs/bCMS/projectFinance.php';

use Money\Currency;
use Money\Money;
use Money\Currencies\ISOCurrencies;
use Money\Parser\DecimalMoneyParser;

if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:EDIT:QUOTE_SECTIONS_AND_LINES")) die("404");
if (!isset($_POST['projects_id']) or !isset($_POST['projectsQuoteLines_text']) or strlen(trim($_POST['projectsQuoteLines_text'])) < 1) finish(false, ["code" => "PARAM-ERROR", "message" => "A note line needs some text"]);

$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("projects.projects_id", $_POST['projects_id']);
$project = $DBLIB->getone("projects", ["projects_id"]);
if (!$project) finish(false, ["message" => "Cannot find project"]);

$sectionId = null;
if (isset($_POST['projectsQuoteSections_id']) and intval($_POST['projectsQuoteSections_id']) > 0) {
    $DBLIB->where("projectsQuoteSections_id", $_POST['projectsQuoteSections_id']);
    $DBLIB->where("projects_id", $project['projects_id']);
    $DBLIB->where("projectsQuoteSections_deleted", 0);
    $section = $DBLIB->getone("projectsQuoteSections", ["projectsQuoteSections_id"]);
    if (!$section) finish(false, ["message" => "Cannot find section"]);
    $sectionId = $section['projectsQuoteSections_id'];
}

$quantity = (isset($_POST['projectsQuoteLines_quantity']) ? intval($_POST['projectsQuoteLines_quantity']) : 1);
if ($quantity < 1) finish(false, ["message" => "Quantity must be at least one"]);
try {
    $moneyParser = new DecimalMoneyParser(new ISOCurrencies());
    $price = $moneyParser->parse((isset($_POST['projectsQuoteLines_price']) and strlen(trim($_POST['projectsQuoteLines_price'])) > 0) ? trim($_POST['projectsQuoteLines_price']) : "0", new Currency($AUTH->data['instance']['instances_config_currency']));
} catch (Exception $e) {
    finish(false, ["message" => "Price isn't a valid amount"]);
}

$DBLIB->where("projects_id", $project['projects_id']);
$DBLIB->where("projectsQuoteLines_deleted", 0);
$rank = $DBLIB->getValue("projectsQuoteLines", "MAX(projectsQuoteLines_rank)");

$line = $DBLIB->insert("projectsQuoteLines", [
    "projects_id" => $project['projects_id'],
    "projectsQuoteSections_id" => $sectionId,
    "projectsQuoteLines_text" => substr(trim($_POST['projectsQuoteLines_text']), 0, 1000),
    "projectsQuoteLines_quantity" => $quantity,
    "projectsQuoteLines_price" => $price->getAmount(),
    "projectsQuoteLines_rank" => ($rank === null ? 0 : intval($rank) + 1),
]);
if (!$line) finish(false, ["message" => "Could not add note line"]);

//A priced line is charged alongside the equipment
$projectFinanceCacher = new projectFinanceCacher($project['projects_id']);
if (!$price->isZero()) $projectFinanceCacher->adjust('projectsFinanceCache_equipmentSubTotal', $price->multiply($quantity));
$bCMS->auditLog("INSERT", "projectsQuoteLines", $line, $AUTH->data['users_userid'], null, $project['projects_id']);

if ($projectFinanceCacher->save()) finish(true, null, ["projectsQuoteLines_id" => $line]);
else finish(false, ["message" => "Finance Cacher Save failed"]);

/** @OA\Post(
 *     path="/projects/quote/newLine.php",
 *     summary="New Quote Note Line",
 *     description="Add a note line to a project's quote. A line can carry a price, which is added to the project's equipment total
Requires Instance Permission PROJECTS:PROJECT_ASSETS:EDIT:QUOTE_SECTIONS_AND_LINES
",
 *     operationId="newQuoteLine",
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
 *         name="projects_id",
 *         in="query",
 *         description="Project ID",
 *         required="true",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 *     @OA\Parameter(
 *         name="projectsQuoteLines_text",
 *         in="query",
 *         description="Text of the line",
 *         required="true",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 *     @OA\Parameter(
 *         name="projectsQuoteSections_id",
 *         in="query",
 *         description="Section to show the line in - leave out to show it at the end of the quote",
 *         required="false",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 *     @OA\Parameter(
 *         name="projectsQuoteLines_quantity",
 *         in="query",
 *         description="Quantity, defaults to 1",
 *         required="false",
 *         @OA\Schema(
 *             type="number"),
 *         ),
 *     @OA\Parameter(
 *         name="projectsQuoteLines_price",
 *         in="query",
 *         description="Price of one unit as a decimal (e.g. 12.50), defaults to 0",
 *         required="false",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 * )
 */
