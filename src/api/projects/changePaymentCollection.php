<?php
require_once __DIR__ . '/../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("PROJECTS:EDIT:PAYMENT_COLLECTION") or !isset($_POST['projects_id'])) die("404");

$update = [];
if (isset($_POST['projects_paymentDueDate'])) {
    if ($_POST['projects_paymentDueDate'] === '') $update['projects_paymentDueDate'] = null; //Back to the business's payment terms
    else {
        $date = DateTime::createFromFormat("Y-m-d", $_POST['projects_paymentDueDate']);
        if (!$date or $date->format("Y-m-d") !== $_POST['projects_paymentDueDate']) finish(false, ["code" => "PARAM-ERROR", "message" => "The due date must be a date"]);
        $update['projects_paymentDueDate'] = $date->format("Y-m-d");
    }
}
if (isset($_POST['projects_collectionStatus'])) {
    if ($_POST['projects_collectionStatus'] === '') $update['projects_collectionStatus'] = null; //Being paid as normal
    elseif (array_key_exists($_POST['projects_collectionStatus'], PROJECT_COLLECTION_STATUSES)) $update['projects_collectionStatus'] = $_POST['projects_collectionStatus'];
    else finish(false, ["code" => "PARAM-ERROR", "message" => "Unknown collection status"]);
}
if (count($update) < 1) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);

$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("projects.projects_id", $_POST['projects_id']);
$project = $DBLIB->getOne("projects", ["projects_id"]);
if (!$project) finish(false, ["code" => "NOT-FOUND", "message" => "Project not found"]);

$DBLIB->where("projects.projects_id", $project['projects_id']);
if (!$DBLIB->update("projects", $update)) finish(false);

$bCMS->auditLog("UPDATE-PAYMENT-COLLECTION", "projects", json_encode($update), $AUTH->data['users_userid'], null, $project['projects_id']);
finish(true);

/** @OA\Post(
 *     path="/projects/changePaymentCollection.php",
 *     summary="Change Payment Collection",
 *     description="Set when a project's payment is due, and whether it's in collections or written off
Requires Instance Permission PROJECTS:EDIT:PAYMENT_COLLECTION
",
 *     operationId="changePaymentCollection",
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
 *         name="projects_paymentDueDate",
 *         in="query",
 *         description="The date the project's payment is due (YYYY-MM-DD), or blank to use the business's payment terms",
 *         required="false",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 *     @OA\Parameter(
 *         name="projects_collectionStatus",
 *         in="query",
 *         description="COLLECTIONS, WRITTEN_OFF, or blank if the project is being paid as normal",
 *         required="false",
 *         @OA\Schema(
 *             type="string"),
 *         ),
 * )
 */
