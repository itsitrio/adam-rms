<?php
require_once __DIR__ . '/../common/headSecure.php';
require_once __DIR__ . '/../common/libs/reports/engine.php';

//One loan, finance or credit: its repayments and charges, its schedule, and the assets it paid for
if (!$AUTH->instancePermissionCheck("FINANCE:LIABILITIES:VIEW") or !isset($_GET['id'])) die($TWIG->render('404.twig', $PAGEDATA));
$ctx = new ReportContext($AUTH->data['instance']);
$found = reportLiabilities($ctx, $_GET['id']);
if (count($found) != 1) die($TWIG->render('404.twig', $PAGEDATA));
$PAGEDATA['liability'] = reportLiabilityDetail($ctx, array_values($found)[0]);
$PAGEDATA['pageConfig'] = ["TITLE" => $PAGEDATA['liability']['liabilities_name'], "BREADCRUMB" => false];
$PAGEDATA['types'] = LIABILITY_TYPES;
$PAGEDATA['frequencies'] = LIABILITY_FREQUENCIES;
$PAGEDATA['transactionTypes'] = LIABILITY_TRANSACTION_TYPES;
$PAGEDATA['members'] = reportBusinessMembers($ctx);
echo $TWIG->render('reports/reports_liability.twig', $PAGEDATA);
