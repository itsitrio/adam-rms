<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->data['instance']) finish(false, ["code" => "AUTH-ERROR", "message" => "No business selected"]);
if (!isset($_POST['usersPersonas_id'])) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);

//Projects already using the persona keep showing it until another is chosen, so their quotes don't change to the user's own name
$DBLIB->where("usersPersonas_id", $_POST['usersPersonas_id']);
$DBLIB->where("users_userid", $AUTH->data['users_userid']);
$DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("usersPersonas_deleted", 0);
$persona = $DBLIB->getOne("usersPersonas", ["usersPersonas_id"]);
if (!$persona) finish(false, ["code" => "PARAM-ERROR", "message" => "Persona not found"]);

$DBLIB->where("usersPersonas_id", $persona['usersPersonas_id']);
if (!$DBLIB->update("usersPersonas", ["usersPersonas_deleted" => 1])) finish(false);
$bCMS->auditLog("DELETE", "usersPersonas", null, $AUTH->data['users_userid'], null, null, $persona['usersPersonas_id']);
finish(true);

/** @OA\Post(
 *     path="/account/personas/delete.php",
 *     summary="Delete Persona",
 *     description="Delete one of the signed-in user's personas in the current business. It can no longer be chosen for a project, but projects already using it keep it.",
 *     operationId="deletePersona",
 *     tags={"account"},
 *     @OA\Response(
 *         response="200",
 *         description="Success",
 *         @OA\MediaType(
 *             mediaType="application/json",
 *             @OA\Schema(ref="#/components/schemas/SimpleResponse"),
 *         ),
 *     ),
 *     @OA\Parameter(
 *         name="usersPersonas_id",
 *         in="query",
 *         description="Persona ID",
 *         required="true",
 *         @OA\Schema(type="number"),
 *     ),
 * )
 */
