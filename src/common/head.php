<?php

/**
 * This file is used by every page. It is included in every page and contains the following:
 * - Database connection
 * - Twig setup
 * - Error handling
 * - Sentry error reporting
 * - Global functions ("bCMS" class)
 * - Config Variables 
 */
require_once(__DIR__ . '/../../vendor/autoload.php'); //Composer
require_once __DIR__ . '/libs/Config/Config.php';

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Aws\CloudFront\CloudFrontClient;
use Aws\Exception\AwsException;
use Twig\Extra\String\StringExtension;

//TWIG
$TWIGLOADER = new \Twig\Loader\FilesystemLoader([__DIR__ . '/../']);
if (getenv('DEV_MODE') == "true") {
    $TWIG = new \Twig\Environment($TWIGLOADER, array(
        'debug' => true,
        'auto_reload' => true,
        'charset' => 'utf-8'
    ));
    $TWIG->addExtension(new \Twig\Extension\DebugExtension());
} else {
    $TWIG = new \Twig\Environment($TWIGLOADER, array(
        'debug' => false,
        'auto_reload' => false,
        'cache' => '/tmp/',
        'charset' => 'utf-8'
    ));
}
$TWIG->addExtension(new StringExtension());

if (getenv('DEV_MODE') == "true") {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ERROR | E_PARSE);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}

/* DATBASE CONNECTION */
try {
    $DBLIB = new MysqliDb([
        'host' => getenv('DB_HOSTNAME'),
        'username' => getenv('DB_USERNAME'), //CREATE INSERT SELECT UPDATE DELETE
        'password' => getenv('DB_PASSWORD'),
        'db' => getenv('DB_DATABASE'),
        'port' => getenv('DB_PORT') ?: 3306,
        //'prefix' => 'adamrms_',
        'charset' => 'utf8mb4'
    ]);
} catch (Exception $e) {
    // TODO use twig for this
    if (getenv('DEV_MODE') == "true") {
        echo "Could not connect to database: " . $e->getMessage() . "\n\n\nPlease doulbe check you have setup environment variables correctly for the database connection.";
        exit;
    } else {
        echo "Could not connect to database";
        exit;
    }
}

$CONFIGCLASS = new Config;
$CONFIG = $CONFIGCLASS->getConfigArray();
if (count($CONFIGCLASS->CONFIG_MISSING_VALUES) > 0) {
    $update = false;
    if (isset($_POST['settingUpConfigUsingConfigFormTwig']) and $_POST['settingUpConfigUsingConfigFormTwig'] == "true") {
        $update = $CONFIGCLASS->formArrayProcess($_POST);
    }
    if ($update !== true)
        die($TWIG->render('common/libs/Config/configForm.twig', ["form" => $CONFIGCLASS->formArrayBuild(), "errors" => is_array($update) ? $update : []]));
    else {
        header("Location: " . $CONFIG['ROOTURL'] . "?");
        exit;
    }
}

// Set the timezone
date_default_timezone_set($CONFIG['TIMEZONE']);

// Include the bCMS class, which contains useful functions 
require_once __DIR__ . '/libs/bCMS/bCMS.php';
require_once __DIR__ . '/libs/bCMS/receivables.php';
$GLOBALS['bCMS'] = new bCMS;

if (getenv('DEV_MODE') != "true" and $CONFIG['ERRORS_PROVIDERS_SENTRY'] and strlen($CONFIG['ERRORS_PROVIDERS_SENTRY']) > 0) {
    Sentry\init([
        'dsn' => $CONFIG['ERRORS_PROVIDERS_SENTRY'],
        'release' => $bCMS->getVersionNumber(),
        'sample_rate' => 1.0,
    ]);
}

// TODO move these functions to a class
function generateNewTag()
{
    global $DBLIB;
    //Get highest current tag - sort numerically so A-10000 ranks above A-9999
    $DBLIB->orderBy("CAST(SUBSTRING(assets_tag, 3) AS UNSIGNED)", "DESC");
    $DBLIB->where("assets_tag", 'A-%', 'like');
    $tag = $DBLIB->getone("assets", ["assets_tag"]);
    if ($tag) {
        if (is_numeric(str_replace("A-", "", $tag["assets_tag"]))) {
            $value = intval(str_replace("A-", "", $tag["assets_tag"])) + 1;
            if ($value <= 9999)
                $value = sprintf('%04d', $value);
            return "A-" . $value;
        } else
            return "A-0001";
    } else
        return "A-0001";
}
/** What a hold does at one stage of an asset's life on a project, from least to most strict */
const ASSET_HOLD_MODES = ["ALLOW" => "Allow", "WARN" => "Warn", "BLOCK" => "Block"];
/** The stages a hold has a rule for: adding the asset to a project, and sending it out (a status that leaves the warehouse) */
const ASSET_HOLD_STAGES = ["assign" => "Adding to a project", "dispatch" => "Sending out"];
/** What a status says about where the gear is. Reaching a status that leaves the warehouse is sending it out, and going past one has to say whether it went out; a status that returns the gear to the warehouse is where the gear that went out comes back to */
const ASSET_STATUS_EFFECTS = ["LEAVES_WAREHOUSE" => "Gear leaves the warehouse", "RETURNS_TO_WAREHOUSE" => "Gear returns to the warehouse"];

/**
 * Every active hold on an asset, from the maintenance jobs it's on and the holds put on it directly, each with the
 * rule it has for adding the asset to a project (assign) and for sending it out (dispatch).
 *
 * A maintenance job's assign rule comes from its flag and block settings: a block blocks, a flag warns.
 *
 * @return array[] Each with source (JOB or HOLD), maintenanceJobs_id or assetHolds_id, title, description, assign,
 *                 dispatch and overridable (whether the dispatch rule can be signed off by whoever is sending it)
 */
function assetHolds($assetid)
{
    global $DBLIB;
    $holds = [];
    $DBLIB->where("maintenanceJobs.maintenanceJobs_deleted", 0);
    $DBLIB->where("(maintenanceJobs.maintenanceJobs_blockAssets = 1 OR maintenanceJobs.maintenanceJobs_flagAssets = 1 OR maintenanceJobs.maintenanceJobs_dispatchMode != 'ALLOW')");
    $DBLIB->where("(FIND_IN_SET(?, maintenanceJobs.maintenanceJobs_assets) > 0)", [$assetid]);
    $DBLIB->join("maintenanceJobsStatuses", "maintenanceJobs.maintenanceJobsStatuses_id=maintenanceJobsStatuses.maintenanceJobsStatuses_id", "LEFT");
    $DBLIB->orderBy("maintenanceJobs.maintenanceJobs_priority", "DESC");
    $jobs = $DBLIB->get('maintenanceJobs', null, ["maintenanceJobs.maintenanceJobs_id", "maintenanceJobs.maintenanceJobs_faultDescription", "maintenanceJobs.maintenanceJobs_title", "maintenanceJobs.maintenanceJobs_flagAssets", "maintenanceJobs.maintenanceJobs_blockAssets", "maintenanceJobs.maintenanceJobs_dispatchMode", "maintenanceJobs.maintenanceJobs_dispatchOverridable", "maintenanceJobsStatuses.maintenanceJobsStatuses_name"]);
    foreach ($jobs ?: [] as $job) {
        $holds[] = $job + [
            "source" => "JOB", "title" => $job["maintenanceJobs_title"], "description" => $job["maintenanceJobs_faultDescription"],
            "assign" => $job["maintenanceJobs_blockAssets"] == 1 ? "BLOCK" : ($job["maintenanceJobs_flagAssets"] == 1 ? "WARN" : "ALLOW"),
            "dispatch" => array_key_exists($job["maintenanceJobs_dispatchMode"], ASSET_HOLD_MODES) ? $job["maintenanceJobs_dispatchMode"] : "ALLOW",
            "overridable" => $job["maintenanceJobs_dispatchOverridable"] == 1,
        ];
    }
    $DBLIB->where("assetHolds.assets_id", $assetid);
    $DBLIB->where("assetHolds.assetHolds_deleted", 0);
    $DBLIB->orderBy("assetHolds.assetHolds_id", "ASC");
    foreach ($DBLIB->get("assetHolds") ?: [] as $hold) {
        $holds[] = $hold + [
            "source" => "HOLD", "maintenanceJobs_id" => null, "title" => "On hold", "description" => $hold["assetHolds_reason"],
            "maintenanceJobs_title" => "On hold", "maintenanceJobs_faultDescription" => $hold["assetHolds_reason"],
            "assign" => array_key_exists($hold["assetHolds_assignMode"], ASSET_HOLD_MODES) ? $hold["assetHolds_assignMode"] : "ALLOW",
            "dispatch" => array_key_exists($hold["assetHolds_dispatchMode"], ASSET_HOLD_MODES) ? $hold["assetHolds_dispatchMode"] : "ALLOW",
            "overridable" => $hold["assetHolds_overridable"] == 1,
        ];
    }
    return $holds;
}

/**
 * The flags and blocks on an asset that apply when adding it to a project - its holds with a WARN (a flag) or a BLOCK
 * for that stage. Everything that decides whether an asset can be assigned, or shows a flag or block, goes through this.
 */
function assetFlagsAndBlocks($assetid)
{
    $return = ["BLOCK" => [], "FLAG" => [], "COUNT" => ["BLOCK" => 0, "FLAG" => 0]];
    foreach (assetHolds($assetid) as $hold) {
        if ($hold["assign"] == "BLOCK") {
            $return['BLOCK'][] = $hold;
            $return['COUNT']['BLOCK'] += 1;
        } elseif ($hold["assign"] == "WARN") {
            $return['FLAG'][] = $hold;
            $return['COUNT']['FLAG'] += 1;
        }
    }
    return $return;
}

/**
 * Whether an asset can be sent out: its holds with a rule for the dispatch stage.
 *
 * @return array ["mode" => the strictest rule (ALLOW, WARN or BLOCK), "overridable" => whether every blocking hold
 *               can be signed off, "holds" => the holds that aren't ALLOW]
 */
function assetDispatchHolds($assetid)
{
    $result = ["mode" => "ALLOW", "overridable" => true, "holds" => []];
    foreach (assetHolds($assetid) as $hold) {
        if ($hold["dispatch"] == "ALLOW") continue;
        $result["holds"][] = $hold;
        if ($hold["dispatch"] == "BLOCK") {
            $result["mode"] = "BLOCK";
            if (!$hold["overridable"]) $result["overridable"] = false;
        } elseif ($result["mode"] == "ALLOW") $result["mode"] = "WARN";
    }
    return $result;
}

/**
 * Check that some assets can be sent out before an assignment is given a status that takes them out of the warehouse.
 * Finishes the request with the reason if they can't: a hold that can't be overridden, or holds that need someone to
 * confirm (PARAM holdOverride) that they're happy to send the asset anyway - the packer has tested it, say.
 *
 * @param int[] $assetIds The assets whose assignments are changing to a status that leaves the warehouse
 * @param int|null $projectId The project, for the audit log
 */
function assetDispatchHoldsCheckOrFinish($assetIds, $projectId = null)
{
    global $AUTH, $bCMS, $DBLIB;
    $problems = [];
    $needsSignOff = false;
    $absolute = false;
    foreach (array_unique($assetIds) as $assetId) {
        $check = assetDispatchHolds($assetId);
        if ($check["mode"] == "ALLOW") continue;
        $problems[$assetId] = $check;
        if ($check["mode"] == "BLOCK" and !$check["overridable"]) $absolute = true;
        $needsSignOff = true;
    }
    if (!$needsSignOff) return;
    $tags = [];
    foreach ($problems as $assetId => $check) {
        $DBLIB->where("assets_id", $assetId);
        $tags[$assetId] = $bCMS->aTag($DBLIB->getValue("assets", "assets_tag"));
    }
    $describe = function ($holds) { return implode("; ", array_map(function ($hold) { return $hold["title"] . ($hold["description"] ? " - " . strip_tags($hold["description"]) : ""); }, $holds)); };
    $summary = [];
    foreach ($problems as $assetId => $check) $summary[] = ["assets_id" => $assetId, "assets_tag" => $tags[$assetId], "mode" => $check["mode"], "overridable" => $check["overridable"], "holds" => array_map(function ($hold) { return ["title" => $hold["title"], "description" => $hold["description"], "mode" => $hold["dispatch"], "overridable" => $hold["overridable"]]; }, $check["holds"])];
    $messages = [];
    foreach ($problems as $assetId => $check) $messages[] = $tags[$assetId] . ": " . $describe($check["holds"]);
    if ($absolute) finish(false, ["code" => "HOLD-BLOCKED", "message" => "Can't be sent out: " . implode(" | ", $messages), "holds" => $summary]);
    if (empty($_POST['holdOverride'])) {
        $blocked = count(array_filter($problems, function ($check) { return $check["mode"] == "BLOCK"; })) > 0;
        finish(false, ["code" => $blocked ? "HOLD-BLOCKED-OVERRIDABLE" : "HOLD-WARNING", "message" => ($blocked ? "Shouldn't be sent out: " : "Held: ") . implode(" | ", $messages), "holds" => $summary]);
    }
    //Someone has signed them off - say who did and what they were told
    $bCMS->auditLog("SIGN-OFF-HOLD", "projects", "Sent out despite: " . implode(" | ", $messages), $AUTH->data['users_userid'], null, $projectId);
}

/**
 * Before assignments are given a status, check whether the change sends gear out of the warehouse.
 *
 * - Reaching a status that leaves the warehouse is sending it out, so the holds on the assets are checked.
 * - Skipping past one - reaching a status later in the business's order, whatever it is, from before it or from no
 *   status at all - might have been sent out or might not, and whoever is doing it has to say which (POST wentOut:
 *   1 or 0): either the gear went, and the system needs to know, or it stayed on the shelf and they are tidying up
 *   the statuses. That's asked whether or not there's a hold. If it did go out the holds can't stop it - it's
 *   happened, and it's logged with them - but if it didn't and the status is still one that has it out, the holds
 *   are checked as for sending it out.
 * - Assets already on a status that leaves the warehouse aren't asked about or checked again.
 *
 * Finishes the request with the question, or the reason it can't, if there's one.
 *
 * @param int[] $assignmentIds The assignments being changed
 * @param int $newStatusId The status they're being changed to
 * @param int|null $projectId The project, for the audit log
 */
function assetDispatchHoldsForStatusChange($assignmentIds, $newStatusId, $projectId = null)
{
    global $DBLIB, $AUTH, $bCMS;
    if (count($assignmentIds) < 1) return;
    $DBLIB->where("assetsAssignmentsStatus_id", $newStatusId);
    $new = $DBLIB->getOne("assetsAssignmentsStatus", ["assetsAssignmentsStatus_order", "assetsAssignmentsStatus_effect", "assetsAssignmentsStatus_name", "instances_id"]);
    if (!$new) return;
    $leaves = $new["assetsAssignmentsStatus_effect"] == "LEAVES_WAREHOUSE";
    $leavesOrders = [];
    if (!$leaves) {
        $DBLIB->where("instances_id", $new["instances_id"]);
        $DBLIB->where("assetsAssignmentsStatus_deleted", 0);
        $DBLIB->where("assetsAssignmentsStatus_effect", "LEAVES_WAREHOUSE");
        $leavesOrders = array_column($DBLIB->get("assetsAssignmentsStatus", null, ["assetsAssignmentsStatus_order"]) ?: [], "assetsAssignmentsStatus_order");
        if (count($leavesOrders) < 1) return; //Nothing the business has set up sends gear out
    }
    $DBLIB->where("assetsAssignments.assetsAssignments_id", $assignmentIds, "IN");
    $DBLIB->join("assetsAssignmentsStatus", "assetsAssignments.assetsAssignmentsStatus_id=assetsAssignmentsStatus.assetsAssignmentsStatus_id", "LEFT");
    $DBLIB->join("assets", "assetsAssignments.assets_id=assets.assets_id", "LEFT");
    $assignments = $DBLIB->get("assetsAssignments", null, ["assetsAssignments.assets_id", "assets.assets_tag", "assetsAssignmentsStatus.assetsAssignmentsStatus_order AS oldOrder", "assetsAssignmentsStatus.assetsAssignmentsStatus_effect AS oldEffect"]) ?: [];
    $sending = []; //Assets being sent out
    $skipped = []; //Assets moved past a status that sends them out
    foreach ($assignments as $assignment) {
        if ($assignment["oldEffect"] == "LEAVES_WAREHOUSE") continue; //Already out
        if ($leaves) {
            $sending[] = $assignment["assets_id"];
            continue;
        }
        $oldOrder = $assignment["oldOrder"] === null ? -1 : intval($assignment["oldOrder"]);
        $passes = array_filter($leavesOrders, function ($order) use ($oldOrder, $new) { return $order > $oldOrder && $order <= $new["assetsAssignmentsStatus_order"]; });
        if (count($passes) > 0) $skipped[$assignment["assets_id"]] = $assignment["assets_tag"];
    }
    if (count($skipped) > 0) {
        if (!isset($_POST['wentOut']) or ($_POST['wentOut'] !== '1' and $_POST['wentOut'] !== '0')) {
            finish(false, ["code" => "DISPATCH-SKIPPED", "message" => "Skipped sending out: " . implode(", ", array_map(function ($tag) use ($bCMS) { return $bCMS->aTag($tag); }, $skipped)) . " - say whether it went out or stayed in the warehouse", "assets" => array_map(function ($id, $tag) use ($bCMS) { return ["assets_id" => $id, "assets_tag" => $bCMS->aTag($tag)]; }, array_keys($skipped), array_values($skipped))]);
        }
        $wentOut = $_POST['wentOut'] === '1';
        $tags = implode(", ", array_map(function ($tag) use ($bCMS) { return $bCMS->aTag($tag); }, $skipped));
        $holdNotes = [];
        foreach ($skipped as $assetId => $tag) {
            $check = assetDispatchHolds($assetId);
            if (count($check["holds"]) > 0) $holdNotes[] = $bCMS->aTag($tag) . " was held: " . implode("; ", array_map(function ($hold) { return $hold["title"] . ($hold["description"] ? " - " . strip_tags($hold["description"]) : ""); }, $check["holds"]));
        }
        $bCMS->auditLog($wentOut ? "WENT-OUT-SKIPPED-DISPATCH" : "STAYED-IN-SKIPPED-DISPATCH", "projects", $tags . " was moved straight to " . $new["assetsAssignmentsStatus_name"] . ", skipping being sent out - " . ($wentOut ? "it did go out" : "it stayed in the warehouse") . (($wentOut && count($holdNotes) > 0) ? ". " . implode(" | ", $holdNotes) : ""), $AUTH->data['users_userid'], null, $projectId);
        //Still on a status that has the gear out, though they say it didn't go: the holds apply as for sending it out
        if (!$wentOut and $new["assetsAssignmentsStatus_effect"] != "RETURNS_TO_WAREHOUSE") $sending = array_keys($skipped);
    }
    assetDispatchHoldsCheckOrFinish($sending, $projectId);
}

/**
 * The stages of the sales pipeline a project status can belong to (projectsStatuses_pipelineStage). Only Won projects
 * count as revenue; Lead and Opportunity are still being chased; Lost and Cancelled have fallen through.
 */
const PROJECT_PIPELINE_STAGES = ["LEAD" => "Lead", "OPPORTUNITY" => "Opportunity", "WON" => "Won", "LOST" => "Lost", "CANCELLED" => "Cancelled"];

/**
 * Work out how many units of an asset are free to assign between two dates.
 *
 * A serialized asset is one row holding one unit, so this returns 1 when it's free and 0 when
 * it clashes - exactly what the old "is there a clashing assignment?" check did. An unserialized
 * asset holds a quantity of interchangeable units instead, so what's free is its stock less
 * everything already committed to projects overlapping these dates.
 *
 * This counts assignments only - a caller choosing assets to put on a project also needs to check
 * assetFlagsAndBlocks() for maintenance jobs blocking the asset.
 *
 * @param array $asset The asset, including at least assets_id and assets_quantity
 * @param string $deliverStart Start of the period to check, as a MySQL datetime
 * @param string $deliverEnd End of the period to check, as a MySQL datetime
 * @param int|null $projectsId A project whose own assignments count towards the total even if its
 *                             status releases assets - the project the units are wanted for
 * @param int|null $ignoreAssignmentsId An assignment to leave out of the total, used when changing
 *                                      an existing assignment rather than making a new one
 * @return array ["quantity" => units held, "assigned" => units committed, "available" => units free,
 *               "assignments" => the clashing assignments found]
 */
function assetAvailableQuantity($asset, $deliverStart, $deliverEnd, $projectsId = null, $ignoreAssignmentsId = null)
{
    global $DBLIB;
    $quantity = (isset($asset['assets_quantity']) ? intval($asset['assets_quantity']) : 1);
    if ($quantity < 1) $quantity = 1; //Pre-migration rows, and anything that's somehow been zeroed, hold one unit

    $DBLIB->where("assetsAssignments.assets_id", $asset['assets_id']);
    $DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
    if ($ignoreAssignmentsId !== null) $DBLIB->where("assetsAssignments.assetsAssignments_id", $ignoreAssignmentsId, "!=");
    $DBLIB->join("projects", "assetsAssignments.projects_id=projects.projects_id", "LEFT");
    $DBLIB->join("projectsStatuses", "projects.projectsStatuses_id=projectsStatuses.projectsStatuses_id", "LEFT");
    $DBLIB->where("projects.projects_deleted", 0);
    if ($projectsId !== null) $DBLIB->where("(projects.projects_id = '" . intval($projectsId) . "' OR projectsStatuses.projectsStatuses_assetsReleased = 0)");
    else $DBLIB->where("projectsStatuses.projectsStatuses_assetsReleased", 0);
    $DBLIB->where("((projects_dates_deliver_start >= '" . $deliverStart . "' AND projects_dates_deliver_start <= '" . $deliverEnd . "') OR (projects_dates_deliver_end >= '" . $deliverStart . "' AND projects_dates_deliver_end <= '" . $deliverEnd . "') OR (projects_dates_deliver_end >= '" . $deliverEnd . "' AND projects_dates_deliver_start <= '" . $deliverStart . "'))");
    $assignments = $DBLIB->get("assetsAssignments", null, ["assetsAssignments.assetsAssignments_id", "assetsAssignments.assetsAssignments_quantity", "assetsAssignments.projects_id", "projects.projects_name"]);

    $assigned = 0;
    foreach ($assignments as $assignment) {
        $assigned += (intval($assignment['assetsAssignments_quantity']) > 0 ? intval($assignment['assetsAssignments_quantity']) : 1);
    }
    return [
        "quantity" => $quantity,
        "assigned" => $assigned,
        "available" => max(0, $quantity - $assigned),
        "assignments" => $assignments,
    ];
}
function assetLatestScan($assetid)
{
    if ($assetid == null)
        return false;
    global $DBLIB;
    $DBLIB->orderBy("assetsBarcodesScans.assetsBarcodesScans_timestamp", "DESC");
    $DBLIB->where("assetsBarcodes.assets_id", $assetid);
    $DBLIB->where("assetsBarcodes.assetsBarcodes_deleted", 0);
    $DBLIB->join("assetsBarcodes", "assetsBarcodes.assetsBarcodes_id=assetsBarcodesScans.assetsBarcodes_id");
    $DBLIB->join("locationsBarcodes", "locationsBarcodes.locationsBarcodes_id=assetsBarcodesScans.locationsBarcodes_id", "LEFT");
    $DBLIB->join("assets", "assets.assets_id=assetsBarcodesScans.location_assets_id", "LEFT");
    $DBLIB->join("assetTypes", "assets.assetTypes_id=assetTypes.assetTypes_id", "LEFT");
    $DBLIB->join("locations", "locations.locations_id=locationsBarcodes.locations_id", "LEFT");
    $DBLIB->join("users", "users.users_userid=assetsBarcodesScans.users_userid");
    return $DBLIB->getone("assetsBarcodesScans", ["assetsBarcodesScans.*", "users.users_name1", "users.users_name2", "locations.locations_name", "locations.locations_id", "assets.assetTypes_id", "assetTypes.assetTypes_name", "assets.assets_tag"]);
}
/**
 * Build the asset dispatch board columns for an instance.
 * Asset assignment statuses are soft-deleted, so a status that's been deleted can still be referenced
 * by assets that were assigned to it before deletion (matched here by ID, so a later reorder of the
 * active statuses can never collide with a deleted one). An asset with no status at all (NULL) is
 * always shown in whichever status currently has order 0 - if that status has been deleted, it's
 * included here too, so those assets aren't lost from the board.
 * Columns are keyed by assetsAssignmentsStatus_id.
 * @param int $instancesId The instance that owns the statuses
 * @param array $assetsList The assigned assets to place onto the board, grouped by assetType (as built in projects/data.php)
 * @return array Board columns keyed by assetsAssignmentsStatus_id, each including an "assets" key
 */
function buildAssetsAssignmentsBoard($instancesId, $assetsList)
{
    global $DBLIB;

    $DBLIB->where("instances_id", $instancesId);
    $DBLIB->where("assetsAssignmentsStatus_deleted", 0);
    $DBLIB->orderBy("assetsAssignmentsStatus_order", "ASC");
    $statuses = $DBLIB->get("assetsAssignmentsStatus");
    $statusIds = array_column($statuses, 'assetsAssignmentsStatus_id');
    $defaultStatusId = null; //the single column NULL-status assets are placed into
    foreach ($statuses as $status) {
        if ((int)$status['assetsAssignmentsStatus_order'] === 0) {
            $defaultStatusId = $status['assetsAssignmentsStatus_id'];
            break;
        }
    }

    $missingIds = [];
    $needsDefaultColumn = false;
    foreach ($assetsList as $assetType) {
        foreach ($assetType['assets'] as $asset) {
            $statusId = $asset['assetsAssignmentsStatus_id'];
            if ($statusId !== null) {
                if (!in_array($statusId, $statusIds)) $missingIds[$statusId] = true;
            } elseif ($defaultStatusId === null) {
                $needsDefaultColumn = true;
            }
        }
    }

    if (!empty($missingIds)) {
        $DBLIB->where("instances_id", $instancesId);
        $DBLIB->where("assetsAssignmentsStatus_id", array_keys($missingIds), "IN");
        foreach ($DBLIB->get("assetsAssignmentsStatus") as $deletedStatus) {
            $statuses[] = $deletedStatus;
            $statusIds[] = $deletedStatus['assetsAssignmentsStatus_id'];
        }
    }
    if ($needsDefaultColumn) {
        $DBLIB->where("instances_id", $instancesId);
        $DBLIB->where("assetsAssignmentsStatus_order", 0);
        $DBLIB->orderBy("assetsAssignmentsStatus_id", "ASC");
        $defaultStatus = $DBLIB->getOne("assetsAssignmentsStatus");
        if ($defaultStatus) {
            $defaultStatusId = $defaultStatus['assetsAssignmentsStatus_id'];
            if (!in_array($defaultStatusId, $statusIds)) $statuses[] = $defaultStatus;
        }
    }
    usort($statuses, function ($a, $b) {
        return $a['assetsAssignmentsStatus_order'] <=> $b['assetsAssignmentsStatus_order'];
    });

    $board = [];
    foreach ($statuses as $status) {
        $tempAssets = [];
        foreach ($assetsList as $assetType) {
            foreach ($assetType['assets'] as $asset) {
                if ($asset['assetsAssignmentsStatus_id'] !== null) {
                    if ($asset['assetsAssignmentsStatus_id'] == $status['assetsAssignmentsStatus_id']) $tempAssets[] = $asset;
                } elseif ($status['assetsAssignmentsStatus_id'] == $defaultStatusId) { //assets with no status at all go in the one default column
                    $tempAssets[] = $asset;
                }
            }
        }
        foreach ($tempAssets as &$tempAsset) {
            //The holds that warn about or block this asset, at either stage, for the badges on its card
            $tempAsset['boardHolds'] = [];
            foreach (assetHolds($tempAsset['assets_id']) as $hold) {
                $mode = ($hold['assign'] == "BLOCK" or $hold['dispatch'] == "BLOCK") ? "BLOCK" : (($hold['assign'] == "WARN" or $hold['dispatch'] == "WARN") ? "WARN" : "ALLOW");
                if ($mode != "ALLOW") $tempAsset['boardHolds'][] = $hold + ["mode" => $mode];
            }
        }
        unset($tempAsset);
        $status['assets'] = $tempAssets;
        $board[$status['assetsAssignmentsStatus_id']] = $status;
    }
    return $board;
}
/**
 * Strip the "assets" key from each column of a board built by buildAssetsAssignmentsBoard().
 * Used for the copy of the board that's JSON-encoded into the page for JavaScript, which only
 * needs the status metadata - the assets themselves are already rendered from the full board and
 * would otherwise be duplicated in full in the page's HTML and in client memory for no purpose.
 * @param array $board A board as returned by buildAssetsAssignmentsBoard()
 * @return array The same board with each column's "assets" key removed
 */
function stripBoardAssets($board)
{
    $slim = [];
    foreach ($board as $key => $status) {
        unset($status['assets']);
        $slim[$key] = $status;
    }
    return $slim;
}

// Setup the "PAGEDATA" array which is used by Twig
$PAGEDATA = array('CONFIG' => $CONFIG, 'VERSION' => $bCMS->getVersionNumber());

// Setup the "MAINTENANCEJOBPRIORITIES" array which is used by Twig
$GLOBALS['MAINTENANCEJOBPRIORITIES'] = [
    1 => ["class" => "danger", "id" => 1, "text" => "Emergency"],
    2 => ["class" => "danger", "id" => 2, "text" => "Business Critical"],
    3 => ["class" => "danger", "id" => 3, "text" => "Urgent"],
    4 => ["class" => "danger", "id" => 4, "text" => "Routine - High"],
    5 => ["class" => "warning", "id" => 5, "text" => "Routine - Medium", "default" => true],
    6 => ["class" => "warning", "id" => 6, "text" => "Routine - Low"],
    7 => ["class" => "warning", "id" => 7, "text" => "Monthly-cycle Maintenance"],
    8 => ["class" => "success", "id" => 8, "text" => "Annual-cycle Maintenance"],
    9 => ["class" => "success", "id" => 9, "text" => "Long Term"],
    10 => ["class" => "info", "id" => 10, "text" => "Log only"]
];
$PAGEDATA['MAINTENANCEJOBPRIORITIES'] = $GLOBALS['MAINTENANCEJOBPRIORITIES'];


// Include Twig Extensions
require_once __DIR__ . '/libs/twigExtensions.php';

// Try to open up a session cookie
try {
    session_set_cookie_params(43200); //12hours
    session_start(); //Open up the session
} catch (Exception $e) {
    //Do Nothing
}


// Include the content security policy
$CSP = [
    "default-src" => [
        ["value" => "'none'", "comment" => ""]
    ],
    "script-src" => [
        ["value" => "'self'", "comment" => ""],
        ["value" => "'unsafe-inline'", "comment" => "We have loads of inline JS"],
        ["value" => "'unsafe-eval'", "comment" => ""],
        ["value" => "https://*.adam-rms.com", "comment" => ""],
        ["value" => "https://cdnjs.cloudflare.com", "comment" => ""],
        ["value" => "https://static.cloudflareinsights.com", "comment" => ""],
        ["value" => "https://www.youtube.com", "comment" => "Training modules allow youtube embed"],
        ["value" => "https://*.ytimg.com", "comment" => "Training modules allow youtube embed"],
        ["value" => "https://js.stripe.com", "comment" => "Stripe payment pricing table"]
    ],
    "style-src" => [
        ["value" => "'unsafe-inline'", "comment" => "We have loads of inline CSS"],
        ["value" => "'self'", "comment" => ""],
        ["value" => "https://*.adam-rms.com", "comment" => ""],
        ["value" => "https://cdnjs.cloudflare.com", "comment" => ""],
        ["value" => "https://fonts.googleapis.com", "comment" => "Google fonts is used extensivley"]
    ],
    "font-src" => [
        ["value" => "'self'", "comment" => ""],
        ["value" => "data:", "comment" => ""],
        ["value" => "https://*.adam-rms.com", "comment" => ""],
        ["value" => "https://fonts.googleapis.com", "comment" => "Google fonts is used extensivley"],
        ["value" => "https://fonts.gstatic.com", "comment" => "Google fonts is used extensivley"],
        ["value" => "https://cdnjs.cloudflare.com", "comment" => "Libraries referenced in HTML"]
    ],
    "manifest-src" => [
        ["value" => "'self'", "comment" => ""],
        ["value" => "https://*.adam-rms.com", "comment" => "Show images on mobile devices like favicons"]
    ],
    "img-src" => [
        ["value" => "'self'", "comment" => ""],
        ["value" => "data:", "comment" => ""],
        ["value" => "blob:", "comment" => ""],
        ["value" => "https://*.adam-rms.com", "comment" => ""],
        ["value" => "https://cdnjs.cloudflare.com", "comment" => "Libraries referenced in HTML"],
        ["value" => "https://cloudflareinsights.com", "comment" => ""],
        ["value" => "https://*.ytimg.com", "comment" => "Training modules allow youtube embed"]
    ],
    "connect-src" => [
        ["value" => "'self'", "comment" => ""],
        ["value" => "https://*.adam-rms.com", "comment" => ""],
        ["value" => "https://sentry.io", "comment" => ""],
        ["value" => "https://cloudflareinsights.com", "comment" => ""],
        ["value" => "https://*.amazonaws.com", "comment" => "To allow S3 uploads"],
        ["value" => "https://*.r2.cloudflarestorage.com", "comment" => "Cloudflare R2 bucket uploads"],
        ["value" => "https://cdnjs.cloudflare.com", "comment" => "Browser fetches source maps from CDN scripts"],
    ],
    "frame-src" => [
        ["value" => "https://www.youtube.com", "comment" => "Training modules allow youtube embed"],
        ["value" => "https://js.stripe.com", "comment" => "Stripe payment pricing table"]
    ],
    "object-src" => [
        ["value" => "'self'", "comment" => ""],
        ["value" => "blob:", "comment" => "Inline PDFs generated by the system"]
    ],
    "worker-src" => [
        ["value" => "'self'", "comment" => ""],
        ["value" => "blob:", "comment" => "Use of camera"]
    ],
    "frame-ancestors" => [
        ["value" => "'self'", "comment" => ""]
    ],
    "report-uri" => [
        ["value" => "https://o83272.ingest.sentry.io/api/5204912/security/?sentry_key=3937ab95cc404dfa95b0e0cb91db5fc6", "comment" => "Report to sentry"]
    ]
];
if (!empty($CONFIG['ANALYTICS_CLARITY_PROJECT_ID'])) {
    // https://learn.microsoft.com/en-us/clarity/setup-and-installation/clarity-csp
    $CSP['script-src'][] = ["value" => "https://*.clarity.ms", "comment" => "Microsoft Clarity analytics"];
    $CSP['connect-src'][] = ["value" => "https://*.clarity.ms", "comment" => "Microsoft Clarity analytics"];
    $CSP['connect-src'][] = ["value" => "https://c.bing.com", "comment" => "Microsoft Clarity telemetry"];
    $CSP['img-src'][] = ["value" => "https://*.clarity.ms", "comment" => "Microsoft Clarity"];
}

if ($CONFIG['CSP_ENABLED'] === "Enabled") {
    $CSPString = "Content-Security-Policy: ";
    foreach ($CSP as $key => $value) {
        $CSPString .= $key;
        foreach ($value as $subvalue) {
            $CSPString .= " " . $subvalue['value'];
        }
        $CSPString .= ";";
    }
    header($CSPString);
}


// Include the Auth class
require_once __DIR__ . '/libs/Auth/main.php';
$GLOBALS['AUTH'] = new bID;
