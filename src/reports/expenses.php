<?php
require_once __DIR__ . '/../common/headSecure.php';
require_once __DIR__ . '/../common/libs/reports/engine.php';

//The business's overheads - costs that aren't part of a project - for the profit & loss
if (!$AUTH->instancePermissionCheck("FINANCE:EXPENSES:VIEW")) die($TWIG->render('404.twig', $PAGEDATA));
$PAGEDATA['pageConfig'] = ["TITLE" => "Overheads", "BREADCRUMB" => false];

$ctx = new ReportContext($AUTH->data['instance']);
$PAGEDATA['form'] = reportReadParams($ctx, ["params" => ["period"], "defaultPeriod" => "fy-to-date"], $_GET);
$PAGEDATA['expenses'] = array_reverse(reportExpenses($ctx, $ctx->start, $ctx->end));
$PAGEDATA['total'] = $ctx->zero();
foreach ($PAGEDATA['expenses'] as $expense) $PAGEDATA['total'] = $PAGEDATA['total']->add($expense['amount']);

//Categories already used, then some common ones, for the category box to suggest
$DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("expenses_deleted", 0);
$DBLIB->groupBy("expenses_category");
$used = array_column($DBLIB->get("expenses", null, ["expenses_category"]), "expenses_category");
$PAGEDATA['categories'] = array_values(array_unique(array_merge($used, ["Rent and rates", "Utilities", "Vehicles and fuel", "Insurance", "Salaries", "Software and subscriptions", "Marketing", "Professional fees", "Bank and card charges", "Consumables", "Travel", "Other"])));
echo $TWIG->render('reports/reports_expenses.twig', $PAGEDATA);
