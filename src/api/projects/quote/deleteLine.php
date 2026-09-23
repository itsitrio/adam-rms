<?php
require_once __DIR__ . '/../../apiHeadSecure.php';
require_once __DIR__ . '/../../../common/libs/bCMS/projectFinance.php';

use Money\Currency;
use Money\Money;

if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:EDIT:QUOTE_SECTIONS_AND_LINES")) die("404");
if (!isset($_POST['projectsQuoteLines_id'])) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);

$DBLIB->where("projectsQuoteLines.projectsQuoteLines_id", $_POST['projectsQuoteLines_id']);
$DBLIB->where("projectsQuoteLines.projectsQuoteLines_deleted", 0);
$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->join("projects", "projectsQuoteLines.projects_id=projects.projects_id", "LEFT");
$line = $DBLIB->getone("projectsQuoteLines", ["projectsQuoteLines.*"]);
if (!$line) finish(false, ["message" => "Cannot find note line"]);

$DBLIB->where("projectsQuoteLines_id", $line['projectsQuoteLines_id']);
if (!$DBLIB->update("projectsQuoteLines", ["projectsQuoteLines_deleted" => 1])) finish(false, ["message" => "Could not delete note line"]);

$projectFinanceCacher = new projectFinanceCacher($line['projects_id']);
$total = (new Money($line['projectsQuoteLines_price'], new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply(max(1, intval($line['projectsQuoteLines_quantity'])));
if (!$total->isZero()) $projectFinanceCacher->adjust('projectsFinanceCache_equipmentSubTotal', $total, true);
$bCMS->auditLog("DELETE", "projectsQuoteLines", $line['projectsQuoteLines_id'], $AUTH->data['users_userid'], null, $line['projects_id']);

if ($projectFinanceCacher->save()) finish(true);
else finish(false, ["message" => "Finance Cacher Save failed"]);

/** @OA\Post(
 *     path="/projects/quote/deleteLine.php",
 *     summary="Delete Quote Note Line",
 *     description="Remove a note line from a project's quote
Requires Instance Permission PROJECTS:PROJECT_ASSETS:EDIT:QUOTE_SECTIONS_AND_LINES
",
 *     operationId="deleteQuoteLine",
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
 * )
 */
