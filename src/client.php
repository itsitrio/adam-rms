<?php
require_once __DIR__ . '/common/headSecure.php';
use Money\Currency;
use Money\Money;

if (!$AUTH->instancePermissionCheck("CLIENTS:VIEW") or !isset($_GET['id'])) die($TWIG->render('404.twig', $PAGEDATA));

$DBLIB->where("clients.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("clients.clients_deleted", 0);
$DBLIB->where("clients.clients_id", $_GET['id']);
$PAGEDATA['client'] = $DBLIB->getone("clients", ["clients_id", "clients_name", "clients_archived", "clients_address", "clients_phone", "clients_email", "clients_website", "clients_notes"]);
if (!$PAGEDATA['client']) die($TWIG->render('404.twig', $PAGEDATA));

$PAGEDATA['pageConfig'] = ["TITLE" => $PAGEDATA['client']['clients_name'], "BREADCRUMB" => false];

$currency = new Currency($AUTH->data['instance']['instances_config_currency']);
//One group per brand, plus one for the projects with no brand, each with its projects in date order
function clientBrandGroup($brand) {
    global $currency;
    return ["brand" => $brand, "projects" => [], "first" => null, "last" => null, "assets" => 0, "total" => new Money(0, $currency)];
}

$DBLIB->where("clientsBrands.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("clientsBrands.clients_id", $PAGEDATA['client']['clients_id']);
$DBLIB->where("clientsBrands.clientsBrands_deleted", 0);
$DBLIB->orderBy("clientsBrands.clientsBrands_name", "ASC");
$brands = $DBLIB->get("clientsBrands", null, ["clientsBrands_id", "clientsBrands_name", "clientsBrands_notes"]);
$groups = [];
foreach ($brands as $brand) $groups[$brand['clientsBrands_id']] = clientBrandGroup($brand);
$noBrand = clientBrandGroup(null);

$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.clients_id", $PAGEDATA['client']['clients_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->join("projectsStatuses", "projects.projectsStatuses_id=projectsStatuses.projectsStatuses_id", "LEFT");
$DBLIB->orderBy("projects.projects_dates_deliver_start", "ASC");
$DBLIB->orderBy("projects.projects_id", "ASC");
$projects = $DBLIB->get("projects", null, ["projects.projects_id", "projects.projects_name", "projects.projects_archived", "projects.clientsBrands_id", "projects.projects_dates_deliver_start", "projects.projects_dates_deliver_end", "projectsStatuses.projectsStatuses_name"]);

//Units of kit booked on each project, counted as projects/data.php counts them
$assetUnits = [];
if (count($projects) > 0) {
    $DBLIB->where("assetsAssignments.projects_id", array_column($projects, "projects_id"), "IN");
    $DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
    $DBLIB->where("assets.assets_deleted", 0);
    $DBLIB->join("assets", "assetsAssignments.assets_id=assets.assets_id", "LEFT");
    $DBLIB->groupBy("assetsAssignments.projects_id");
    foreach ($DBLIB->get("assetsAssignments", null, ["assetsAssignments.projects_id", "SUM(IF(assetsAssignments.assetsAssignments_quantity > 0, assetsAssignments.assetsAssignments_quantity, 1)) AS units"]) as $row) {
        $assetUnits[$row['projects_id']] = intval($row['units']);
    }
}

$PAGEDATA['totals'] = ["projects" => count($projects), "assets" => 0, "total" => new Money(0, $currency), "received" => new Money(0, $currency), "outstanding" => new Money(0, $currency)];
foreach ($projects as $project) {
    //The project's running totals, as the clients list uses them
    $DBLIB->where("projects_id", $project['projects_id']);
    $DBLIB->orderBy("projectsFinanceCache_timestamp", "DESC");
    $DBLIB->orderBy("projectsFinanceCache_id", "DESC");
    $finance = $DBLIB->getOne("projectsFinanceCache", ["projectsFinanceCache_equiptmentTotal", "projectsFinanceCache_salesTotal", "projectsFinanceCache_staffTotal", "projectsFinanceCache_externalHiresTotal", "projectsFinanceCache_paymentsReceived", "projectsFinanceCache_grandTotal"]);
    $project['assets'] = $assetUnits[$project['projects_id']] ?? 0;
    $project['total'] = new Money(0, $currency); //Equipment, sales, staff and sub-hires: what the client is charged
    $received = new Money(0, $currency);
    $outstanding = new Money(0, $currency);
    if ($finance) {
        $project['total'] = $project['total']->add(new Money($finance['projectsFinanceCache_equiptmentTotal'], $currency), new Money($finance['projectsFinanceCache_salesTotal'], $currency), new Money($finance['projectsFinanceCache_staffTotal'], $currency), new Money($finance['projectsFinanceCache_externalHiresTotal'], $currency));
        $received = new Money($finance['projectsFinanceCache_paymentsReceived'], $currency);
        $outstanding = new Money($finance['projectsFinanceCache_grandTotal'], $currency);
    }

    $PAGEDATA['totals']['assets'] += $project['assets'];
    $PAGEDATA['totals']['total'] = $PAGEDATA['totals']['total']->add($project['total']);
    $PAGEDATA['totals']['received'] = $PAGEDATA['totals']['received']->add($received);
    $PAGEDATA['totals']['outstanding'] = $PAGEDATA['totals']['outstanding']->add($outstanding);

    //A project whose brand has been deleted counts as having no brand
    if ($project['clientsBrands_id'] !== null and isset($groups[$project['clientsBrands_id']])) $group = &$groups[$project['clientsBrands_id']];
    else $group = &$noBrand;
    $group['projects'][] = $project;
    $group['assets'] += $project['assets'];
    $group['total'] = $group['total']->add($project['total']);
    if ($project['projects_dates_deliver_start'] !== null) {
        if ($group['first'] === null or $project['projects_dates_deliver_start'] < $group['first']) $group['first'] = $project['projects_dates_deliver_start'];
        if ($group['last'] === null or $project['projects_dates_deliver_start'] > $group['last']) $group['last'] = $project['projects_dates_deliver_start'];
    }
    unset($group);
}
$PAGEDATA['brandGroups'] = array_values($groups);
$PAGEDATA['noBrand'] = $noBrand;

echo $TWIG->render('client.twig', $PAGEDATA);
