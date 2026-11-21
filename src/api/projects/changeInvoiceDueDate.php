<?php
require_once __DIR__ . '/../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("PROJECTS:EDIT:PAYMENT_COLLECTION") or !isset($_POST['projects_id']) or !isset($_POST['action'])) die("404");
if (!in_array($_POST['action'], ["lock", "unlock"], true)) finish(false, ["code" => "PARAM-ERROR", "message" => "Unknown action"]);

$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("projects.projects_id", $_POST['projects_id']);
$project = $DBLIB->getOne("projects");
if (!$project) finish(false, ["code" => "NOT-FOUND", "message" => "Project not found"]);

if ($_POST['action'] == "unlock") {
    if ($project['projects_invoiceDueDate'] === null) finish(true, null, ["locked" => false]);
    $DBLIB->where("projects.projects_id", $project['projects_id']);
    if (!$DBLIB->update("projects", ["projects_invoiceIssueDate" => null, "projects_invoiceDueDate" => null, "projects_invoiceTermsDays" => null])) finish(false);
    $bCMS->auditLog("UNLOCK-INVOICE-DUE-DATE", "projects", "Unlocked the invoice due date of " . $project['projects_invoiceDueDate'], $AUTH->data['users_userid'], null, $project['projects_id']);
    finish(true, null, ["locked" => false]);
}

//Locking a project that's already locked changes nothing - it has to be unlocked first, so the date a client was given can't move by accident
$dueDate = null;
if (isset($_POST['projects_invoiceDueDate']) and $_POST['projects_invoiceDueDate'] !== '') {
    $date = DateTime::createFromFormat("Y-m-d", $_POST['projects_invoiceDueDate']);
    if (!$date or $date->format("Y-m-d") !== $_POST['projects_invoiceDueDate']) finish(false, ["code" => "PARAM-ERROR", "message" => "The due date must be a date"]);
    $dueDate = $date->format("Y-m-d");
}
$locked = projectLockInvoiceDueDate($project, $dueDate);
if (is_string($locked)) finish(false, ["code" => "PARAM-ERROR", "message" => $locked]);
finish(true, null, ["locked" => true] + $locked);

/** @OA\Post(
 *     path="/projects/changeInvoiceDueDate.php",
 *     summary="Lock or Unlock the Invoice Due Date",
 *     description="Lock in the date a project's invoice is due (and the date it was issued and the payment terms it was issued on), so that changes to the project's dates or the business's payment terms don't move it, or unlock it again
Requires Instance Permission PROJECTS:EDIT:PAYMENT_COLLECTION
",
 *     operationId="changeInvoiceDueDate",
 *     tags={"projects"},
 *     @OA\Response(
 *         response="200",
 *         description="Success",
 *         @OA\MediaType(
 *             mediaType="application/json",
 *             @OA\Schema(ref="#/components/schemas/SimpleResponse"),
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
 *         name="action",
 *         in="query",
 *         description="lock or unlock",
 *         required="true",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 *     @OA\Parameter(
 *         name="projects_invoiceDueDate",
 *         in="query",
 *         description="When locking, the date to lock in (YYYY-MM-DD) - otherwise the date worked out from the project's dates and the business's payment terms",
 *         required="false",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 * )
 */
