<?php
require_once __DIR__ . '/../apiHeadSecure.php';

if (!isset($_POST['projects_id'])) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);

$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("projects.projects_id", $_POST['projects_id']);
$project = $DBLIB->getOne("projects", ["projects_id", "projects_manager", "projects_manager_persona"]);
if (!$project) finish(false, ["code" => "PARAM-ERROR", "message" => "Project not found"]);

//Whoever can change the manager can choose how they're shown, and so can the manager themselves
if (!$AUTH->instancePermissionCheck("PROJECTS:EDIT:LEAD") and $project['projects_manager'] != $AUTH->data['users_userid']) finish(false, ["code" => "AUTH-ERROR", "message" => "No auth for action"]);

$persona = null;
if (isset($_POST['usersPersonas_id']) and $_POST['usersPersonas_id'] !== "") {
    //Only one of the manager's own personas in this business
    $DBLIB->where("usersPersonas_id", $_POST['usersPersonas_id']);
    $DBLIB->where("users_userid", $project['projects_manager']);
    $DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
    $DBLIB->where("usersPersonas_deleted", 0);
    $persona = $DBLIB->getOne("usersPersonas", ["usersPersonas_id", "usersPersonas_name"]);
    if (!$persona) finish(false, ["code" => "PARAM-ERROR", "message" => "Persona not found"]);
}

$DBLIB->where("projects_id", $project['projects_id']);
if (!$DBLIB->update("projects", ["projects_manager_persona" => $persona ? $persona['usersPersonas_id'] : null])) finish(false);

$bCMS->auditLog("CHANGE-MANAGER-PERSONA", "projects", $persona ? "Set the project manager to be shown as " . $persona['usersPersonas_name'] : "Set the project manager to be shown by their own name", $AUTH->data['users_userid'], null, $project['projects_id']);
finish(true);

/** @OA\Post(
 *     path="/projects/changeProjectManagerPersona.php",
 *     summary="Change Project Manager Persona",
 *     description="Choose which of the project manager's personas the project shows in place of their name and email, or none to show their own
Requires Instance Permission PROJECTS:EDIT:LEAD, or being the project's manager
",
 *     operationId="changeProjectManagerPersona",
 *     tags={"projects"},
 *     @OA\Response(
 *         response="200",
 *         description="Success",
 *         @OA\MediaType(
 *             mediaType="application/json",
 *             @OA\Schema(ref="#/components/schemas/SimpleResponse"),
 *         ),
 *     ),
 *     @OA\Parameter(
 *         name="projects_id",
 *         in="query",
 *         description="Project ID",
 *         required="true",
 *         @OA\Schema(type="number"),
 *     ),
 *     @OA\Parameter(
 *         name="usersPersonas_id",
 *         in="query",
 *         description="Persona ID - leave blank to show the manager's own name and email",
 *         required="false",
 *         @OA\Schema(type="number"),
 *     ),
 * )
 */
