<?php
require_once __DIR__ . '/../../apiHeadSecure.php';

//Personas belong to the signed-in user in the business they are using, so nobody can create or change another user's
if (!$AUTH->data['instance']) finish(false, ["code" => "AUTH-ERROR", "message" => "No business selected"]);
if (!isset($_POST['usersPersonas_name']) or !is_string($_POST['usersPersonas_name'])) finish(false, ["code" => "PARAM-ERROR", "message" => "No data for action"]);

$name = trim($_POST['usersPersonas_name']);
if ($name === "" or mb_strlen($name) > 255) finish(false, ["code" => "PARAM-ERROR", "message" => "Please enter a name of up to 255 characters"]);

$email = (isset($_POST['usersPersonas_email']) and is_string($_POST['usersPersonas_email'])) ? trim($_POST['usersPersonas_email']) : "";
if ($email === "") $email = null; //The user's own email is shown
elseif (mb_strlen($email) > 255 or !filter_var($email, FILTER_VALIDATE_EMAIL)) finish(false, ["code" => "PARAM-ERROR", "message" => "Please enter a valid email address, or leave it blank to use your own"]);

$data = ["usersPersonas_name" => $name, "usersPersonas_email" => $email];

if (isset($_POST['usersPersonas_id']) and $_POST['usersPersonas_id'] !== "") {
    $DBLIB->where("usersPersonas_id", $_POST['usersPersonas_id']);
    $DBLIB->where("users_userid", $AUTH->data['users_userid']);
    $DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
    $DBLIB->where("usersPersonas_deleted", 0);
    $persona = $DBLIB->getOne("usersPersonas", ["usersPersonas_id"]);
    if (!$persona) finish(false, ["code" => "PARAM-ERROR", "message" => "Persona not found"]);

    $DBLIB->where("usersPersonas_id", $persona['usersPersonas_id']);
    if (!$DBLIB->update("usersPersonas", $data)) finish(false);
    $bCMS->auditLog("EDIT", "usersPersonas", json_encode($data), $AUTH->data['users_userid'], null, null, $persona['usersPersonas_id']);
    finish(true, null, ["usersPersonas_id" => $persona['usersPersonas_id']]);
}

$data["users_userid"] = $AUTH->data['users_userid'];
$data["instances_id"] = $AUTH->data['instance']['instances_id'];
$personaId = $DBLIB->insert("usersPersonas", $data);
if (!$personaId) finish(false, ["code" => "CREATE-PERSONA-FAIL", "message" => "Could not create persona"]);
$bCMS->auditLog("INSERT", "usersPersonas", json_encode($data), $AUTH->data['users_userid'], null, null, $personaId);
finish(true, null, ["usersPersonas_id" => $personaId]);

/** @OA\Post(
 *     path="/account/personas/save.php",
 *     summary="Save Persona",
 *     description="Create a persona for the signed-in user in the current business, or edit one of theirs. A project can show its manager's persona in place of their name and email.",
 *     operationId="savePersona",
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
 *         description="Persona to edit - leave out to create one",
 *         required="false",
 *         @OA\Schema(type="number"),
 *     ),
 *     @OA\Parameter(
 *         name="usersPersonas_name",
 *         in="query",
 *         description="Name to show",
 *         required="true",
 *         @OA\Schema(type="string"),
 *     ),
 *     @OA\Parameter(
 *         name="usersPersonas_email",
 *         in="query",
 *         description="Email to show - leave blank to show the user's own",
 *         required="false",
 *         @OA\Schema(type="string"),
 *     ),
 * )
 */
