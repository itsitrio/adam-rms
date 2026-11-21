<?php
require_once __DIR__ . '/common/headSecure.php';

use Money\Currency;
use Money\Money;

$PAGEDATA['pageConfig'] = ["TITLE" => "Receivables", "BREADCRUMB" => false];

if (!$AUTH->instancePermissionCheck("FINANCE:PAYMENTS_LEDGER:VIEW")) die($TWIG->render('404.twig', $PAGEDATA));

//Won projects that have ended, or whose payment is already due, and still owe something - what's been earned but not yet collected
$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("projectsStatuses.projectsStatuses_pipelineStage", "WON");
$DBLIB->where("projectsTypes.projectsTypes_config_finance", 1);
$DBLIB->where("(" . projectLatestDateSql() . " < '" . date("Y-m-d H:i:s") . "' OR projects.projects_paymentDueDate < '" . date("Y-m-d") . "' OR projects.projects_invoiceDueDate < '" . date("Y-m-d") . "')");
$DBLIB->join("projectsStatuses", "projects.projectsStatuses_id=projectsStatuses.projectsStatuses_id", "LEFT");
$DBLIB->join("projectsTypes", "projects.projectsTypes_id=projectsTypes.projectsTypes_id", "LEFT");
$DBLIB->join("clients", "projects.clients_id=clients.clients_id", "LEFT");
$projects = $DBLIB->get("projects", null, ["projects.projects_id", "projects.projects_name", "projects.projects_dates_deliver_end", "projects.projects_dates_deliver_start", "projects.projects_dates_use_start", "projects.projects_dates_use_end", "projects.projects_paymentDueDate", "projects.projects_invoiceDueDate", "projects.projects_collectionStatus", "clients.clients_id", "clients.clients_name", "projectsStatuses.projectsStatuses_name", "projectsStatuses.projectsStatuses_foregroundColour", "projectsStatuses.projectsStatuses_backgroundColour"]);

usort($projects, function ($a, $b) { return strcmp(projectLatestDate($a) ?? "", projectLatestDate($b) ?? ""); }); //Oldest first, whichever date a project's latest is

$currency = new Currency($AUTH->data['instance']['instances_config_currency']);
$PAGEDATA['states'] = array_diff_key(PROJECT_COLLECTION_STATES, ["PAID" => 0]);
$PAGEDATA['totals'] = array_map(function () use ($currency) { return new Money(0, $currency); }, $PAGEDATA['states']);
//Not "projects", which the sidebar uses for its list of projects
$PAGEDATA['receivables'] = array_map(function () { return []; }, $PAGEDATA['states']);
foreach ($projects as $project) {
    $project['balance'] = projectBalance($project['projects_id'], $currency);
    $project['dueDate'] = projectPaymentDueDate($project, $AUTH->data['instance']['instances_config_paymentDueDays']);
    $project['state'] = projectCollectionState($project, $project['balance'] === null ? null : $project['balance']['outstanding'], $project['dueDate']);
    if ($project['state'] == "PAID") continue;
    $project['daysPastDue'] = ($project['dueDate'] !== null and $project['dueDate'] < date("Y-m-d")) ? intval((strtotime(date("Y-m-d")) - strtotime($project['dueDate'])) / 86400) : 0;
    if ($project['balance'] !== null) $PAGEDATA['totals'][$project['state']] = $PAGEDATA['totals'][$project['state']]->add($project['balance']['outstanding']);
    $PAGEDATA['receivables'][$project['state']][] = $project;
}

echo $TWIG->render('receivables.twig', $PAGEDATA);
