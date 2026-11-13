<?php
// Test-only: builds the data the quote e2e tests need. Never run this against a real database.
//   php e2e/setup/quoteFixtures.php create            - a new user and business with a parent project, two stage sub-projects and assets on them; prints JSON
//   php e2e/setup/quoteFixtures.php cache <projectId> - prints the project's cached equipment subtotal, in pence
//   php e2e/setup/quoteFixtures.php delete <instanceId> - marks the business deleted, so the super admin's other tests still find no businesses
require_once __DIR__ . '/../../src/common/libs/Auth/instanceActions.php';

$db = new PDO(
    "mysql:host=" . getenv('DB_HOSTNAME') . ";port=" . (getenv('DB_PORT') ?: 3306) . ";dbname=" . getenv('DB_DATABASE'),
    getenv('DB_USERNAME'),
    getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
function insert($table, $values) {
    global $db;
    $db->prepare("INSERT INTO " . $table . " (" . implode(",", array_keys($values)) . ") VALUES (" . implode(",", array_fill(0, count($values), "?")) . ")")
        ->execute(array_values($values));
    return intval($db->lastInsertId());
}

if (($argv[1] ?? null) == "cache") {
    $statement = $db->prepare("SELECT projectsFinanceCache_equipmentSubTotal FROM projectsFinanceCache WHERE projects_id = ? ORDER BY projectsFinanceCache_timestamp DESC, projectsFinanceCache_id DESC LIMIT 1");
    $statement->execute([$argv[2]]);
    echo json_encode(intval($statement->fetchColumn()));
    exit;
}

if (($argv[1] ?? null) == "delete") {
    $db->prepare("UPDATE instances SET instances_deleted = 1 WHERE instances_id = ?")->execute([$argv[2]]);
    exit;
}

$unique = uniqid();
$email = "quotes-" . $unique . "@example.com";
// Same password as the seeded super admin: password!
$user = insert("users", [
    "users_username" => "quotes-" . $unique, "users_name1" => "Quote", "users_name2" => "Tester", "users_email" => $email, "users_created" => date("Y-m-d H:i:s"),
    "users_salty1" => "8smqAFD9", "users_password" => "fa5a51baef12914c7f2e0e1176a030bf086d26edae298c25d5f84c90bc72ecd7", "users_salty2" => "uOhfrOCW", "users_hash" => "sha256",
    "users_emailVerified" => 1, "users_changepass" => 0,
]);
// A development server only lets in users with USE-DEV, so give them a server position with just that
$group = $db->query("SELECT positionsGroups_id FROM positionsGroups WHERE positionsGroups_name = 'E2E Developer'")->fetchColumn();
if ($group === false) $group = insert("positionsGroups", ["positionsGroups_name" => "E2E Developer", "positionsGroups_actions" => "USE-DEV"]);
$serverPosition = $db->query("SELECT positions_id FROM positions WHERE positions_displayName = 'E2E Developer'")->fetchColumn();
if ($serverPosition === false) $serverPosition = insert("positions", ["positions_displayName" => "E2E Developer", "positions_positionsGroups" => $group, "positions_rank" => 99]);
insert("userPositions", ["users_userid" => $user, "userPositions_start" => date("Y-m-d H:i:s", strtotime("-1 day")), "positions_id" => $serverPosition, "userPositions_show" => 1]);

$instance = insert("instances", ["instances_name" => "Quotes " . $unique, "instances_config_currency" => "GBP", "instances_storageEnabled" => 0]);
$position = insert("instancePositions", ["instances_id" => $instance, "instancePositions_displayName" => "Administrator", "instancePositions_rank" => 1, "instancePositions_actions" => implode(",", array_keys($instanceActions))]);
insert("userInstances", ["users_userid" => $user, "instancePositions_id" => $position, "userInstances_label" => "Tester"]);
$db->prepare("UPDATE users SET users_selectedInstanceIDLast = ? WHERE users_userid = ?")->execute([$instance, $user]);

$type = insert("projectsTypes", ["projectsTypes_name" => "Event", "instances_id" => $instance]);
$status = insert("projectsStatuses", ["projectsStatuses_name" => "Confirmed", "projectsStatuses_description" => "", "projectsStatuses_foregroundColour" => "#000000", "projectsStatuses_backgroundColour" => "#ffffff", "projectsStatuses_rank" => 1, "instances_id" => $instance]);
$client = insert("clients", ["clients_name" => "Festival Client", "instances_id" => $instance]);

// Category 1 (Conventionals) and manufacturer 1 (Unknown/Generic) come from the Phinx seeders
$micType = insert("assetTypes", ["assetTypes_name" => "Test Mic", "assetCategories_id" => 1, "manufacturers_id" => 1, "instances_id" => $instance, "assetTypes_dayRate" => 1000, "assetTypes_weekRate" => 0, "assetTypes_value" => 10000, "assetTypes_mass" => 1, "assetTypes_definableFields" => ""]);
$screenType = insert("assetTypes", ["assetTypes_name" => "Test Screen", "assetCategories_id" => 1, "manufacturers_id" => 1, "instances_id" => $instance, "assetTypes_dayRate" => 5000, "assetTypes_weekRate" => 0, "assetTypes_value" => 50000, "assetTypes_mass" => 10, "assetTypes_definableFields" => ""]);
$mics = insert("assets", ["assets_tag" => "Q" . substr($unique, -6) . "M", "assetTypes_id" => $micType, "instances_id" => $instance, "assets_unserialized" => 1, "assets_quantity" => 20]);
$screen = insert("assets", ["assets_tag" => "Q" . substr($unique, -6) . "S", "assetTypes_id" => $screenType, "instances_id" => $instance]);

$project = function ($name, $parent = null) use ($instance, $user, $status, $type, $client) {
    return insert("projects", [
        "projects_name" => $name, "instances_id" => $instance, "projects_manager" => $user, "clients_id" => $client, "projectsStatuses_id" => $status, "projectsTypes_id" => $type,
        "projects_parent_project_id" => $parent,
        "projects_dates_use_start" => "2026-10-02 09:00:00", "projects_dates_use_end" => "2026-10-02 23:00:00",
        "projects_dates_deliver_start" => "2026-10-02 08:00:00", "projects_dates_deliver_end" => "2026-10-02 23:30:00",
    ]);
};
$parent = $project("Festival");
$stageA = $project("Main Stage", $parent);
$stageB = $project("Second Stage", $parent);

// One day hire: the mics are £10 a unit and the screen £50
$micsOnStageA = insert("assetsAssignments", ["assets_id" => $mics, "projects_id" => $stageA, "assetsAssignments_quantity" => 10]);
$screenOnStageA = insert("assetsAssignments", ["assets_id" => $screen, "projects_id" => $stageA]);
$micsOnStageB = insert("assetsAssignments", ["assets_id" => $mics, "projects_id" => $stageB, "assetsAssignments_quantity" => 4]);

echo json_encode([
    "email" => $email,
    "instance" => $instance,
    "projects" => ["parent" => $parent, "stageA" => $stageA, "stageB" => $stageB],
    "assignments" => ["micsOnStageA" => $micsOnStageA, "screenOnStageA" => $screenOnStageA, "micsOnStageB" => $micsOnStageB],
]);
