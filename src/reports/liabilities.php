<?php
require_once __DIR__ . '/../common/headSecure.php';
require_once __DIR__ . '/../common/libs/reports/engine.php';

//The loans, finance and credit the business owes
if (!$AUTH->instancePermissionCheck("FINANCE:LIABILITIES:VIEW")) die($TWIG->render('404.twig', $PAGEDATA));
$PAGEDATA['pageConfig'] = ["TITLE" => "Loans & finance", "BREADCRUMB" => false];
$ctx = new ReportContext($AUTH->data['instance']);
$PAGEDATA['liabilities'] = [];
$PAGEDATA['total'] = $ctx->zero();
foreach (reportLiabilities($ctx) as $liability) {
    $liability = reportLiabilityDetail($ctx, $liability);
    $PAGEDATA['total'] = $PAGEDATA['total']->add($liability['position']['balance']);
    $PAGEDATA['liabilities'][] = $liability;
}
$PAGEDATA['types'] = LIABILITY_TYPES;
$PAGEDATA['frequencies'] = LIABILITY_FREQUENCIES;
$PAGEDATA['members'] = reportBusinessMembers($ctx);
echo $TWIG->render('reports/reports_liabilities.twig', $PAGEDATA);
