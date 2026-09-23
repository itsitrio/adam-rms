<?php
/**
 * API
 * \assets\swap.php
 * updates the asset associated with a given asset Assignment
 *
 * Arguments:
 *  - assetsAssignments_id: an Asset Assignment ID
 *  - assets_id: the asset to replace in the assignment
 */

require_once __DIR__ . '/../../apiHeadSecure.php';

if (!$AUTH->instancePermissionCheck("PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_AND_UNASSIGN")) die("404");
if (!(isset($_POST['assetsAssignments_id'])) || !(isset($_POST['assets_id']))) finish(false);

$DBLIB->where("assetsAssignments.assetsAssignments_id", $_POST['assetsAssignments_id']);
$DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects_dates_deliver_start",NULL,"IS NOT");
$DBLIB->where("projects_dates_deliver_end",NULL,"IS NOT");
$DBLIB->join("assetsAssignments", "assetsAssignments.assets_id = assets.assets_id");
$DBLIB->join("projects", "assetsAssignments.projects_id = projects.projects_id");
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("projects.projects_archived", 0);
$DBLIB->where('assets_deleted', 0);
$currentAsset = $DBLIB->getone("assets");
if (!$currentAsset) finish(false);

// Check Asset is free and available
$DBLIB->where("assets.assets_id", $_POST['assets_id']);
$DBLIB->where("assets.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("assets.assetTypes_id", $currentAsset["assetTypes_id"]);
$DBLIB->where ("(assets.assets_endDate IS NULL OR assets.assets_endDate >= '" . date ("Y-m-d H:i:s") . "')");
$DBLIB->where('assets.assets_deleted', 0);
$assetToSwap = $DBLIB->getone("assets", ["assets_id", "assets_quantity"]);
if (!$assetToSwap) finish(false);

//The replacement has to be able to cover every unit the assignment took
$quantity = (intval($currentAsset['assetsAssignments_quantity']) > 0 ? intval($currentAsset['assetsAssignments_quantity']) : 1);
$availability = assetAvailableQuantity($assetToSwap, $currentAsset["projects_dates_deliver_start"], $currentAsset["projects_dates_deliver_end"]);
$flagsBlocks = assetFlagsAndBlocks($_POST['assets_id']);

if ($availability['available'] >= $quantity and $flagsBlocks['COUNT']['BLOCK'] < 1) {
    $DBLIB->where('assetsAssignments_id', $currentAsset['assetsAssignments_id']);
    $assignment = $DBLIB->update("assetsAssignments", ["assets_id" => $_POST['assets_id']],1);
    finish(true);
} else finish(false);

/** @OA\Post(
 *     path="/projects/assets/swap.php", 
 *     summary="Swap Asset Assignment", 
 *     description="Swap an asset in a project  
Requires Instance Permission PROJECTS:PROJECT_ASSETS:CREATE:ASSIGN_AND_UNASSIGN
", 
 *     operationId="swapAssetAssignment", 
 *     tags={"project_assets"}, 
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
 *         name="assetsAssignments_id",
 *         in="query",
 *         description="Asset Assignment ID",
 *         required="true", 
 *         @OA\Schema(
 *             type="number"), 
 *         ), 
 *     @OA\Parameter(
 *         name="assets_id",
 *         in="query",
 *         description="Project ID",
 *         required="true", 
 *         @OA\Schema(
 *             type="number"), 
 *         ), 
 * )
 */