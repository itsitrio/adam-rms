<?php
require_once __DIR__ . '/../common/headSecure.php';
if (!$AUTH->instancePermissionCheck("PROJECTS:VIEW") or !isset($_GET['id'])) die($TWIG->render('404.twig', $PAGEDATA));
require_once __DIR__ . '/../api/projects/data.php'; //Where most of the data comes from

$PAGEDATA['GET'] = $_GET;
$PAGEDATA['GET']['generate'] = true;

$isQuote = $_GET['type'] == "quote";
$isDeliveryNote = $_GET['type'] == "deliveryNote";
$PAGEDATA['GET']['quote'] = $isQuote;
$PAGEDATA['GET']['deliveryNote'] = $isDeliveryNote;

$typeId = 20;
$PAGEDATA['GET']['fileType'] = 'Invoice';

switch ($_GET['type']) {
    case 'quote':
        $typeId = 21;
        $PAGEDATA['GET']['fileType'] = 'Quotation';
        break;
    case 'deliveryNote':
        $typeId = 22;
        $PAGEDATA['GET']['fileType'] = 'Delivery Note';
        break;
    default:
        break;
}

$DBLIB->where("s3files_meta_type", $typeId);
$DBLIB->where("instances_id",$AUTH->data['instance']['instances_id']);
$DBLIB->where("s3files_meta_subType",$_GET['id']);
$count = $DBLIB->getValue ("s3files", "count(*)");
if ($count) $fileNumber = ($count+1);
else $fileNumber = 1;
$PAGEDATA['fileNumber'] = $fileNumber;

//Sub-projects are printed after the project, with a summary of them all at the front
$PAGEDATA['CHILDREN'] = [];
if (isset($_GET['subProjects']) and $_GET['subProjects'] and count($PAGEDATA['project']['subProjects']) > 0) {
    $sortBySupplier = function ($a, $b) {
        return $a['payments_supplier'] <=> $b['payments_supplier'];
    };
    foreach ($PAGEDATA['project']['subProjects'] as $subProject) {
        $child = ["project" => projectDetails($subProject['projects_id'])];
        if (!$child['project']) continue;
        $child['FINANCIALS'] = projectFinancials($child['project']);
        foreach (["subHire", "sales", "staff"] as $ledger) usort($child['FINANCIALS']['payments'][$ledger]['ledger'], $sortBySupplier);
        $PAGEDATA['CHILDREN'][] = $child;
    }
    usort($PAGEDATA['CHILDREN'], function ($a, $b) {
        return [$a['project']['projects_dates_deliver_start'] ?? '', $a['project']['projects_id']] <=> [$b['project']['projects_dates_deliver_start'] ?? '', $b['project']['projects_id']];
    });

    $PAGEDATA['SUMMARY'] = ["rows" => [], "totals" => null];
    foreach (array_merge([["project" => $PAGEDATA['project'], "FINANCIALS" => $PAGEDATA['FINANCIALS']]], $PAGEDATA['CHILDREN']) as $row) {
        $summaryRow = [
            "project" => $row['project'],
            "equipment" => $row['FINANCIALS']['prices']['total'],
            "other" => $row['FINANCIALS']['payments']['subTotal']->subtract($row['FINANCIALS']['prices']['total']), //Sales, staff and sub-hires
            "total" => $row['FINANCIALS']['payments']['subTotal'],
            "received" => $row['FINANCIALS']['payments']['received']['total'],
            "outstanding" => $row['FINANCIALS']['payments']['total'],
        ];
        $PAGEDATA['SUMMARY']['rows'][] = $summaryRow;
        if ($PAGEDATA['SUMMARY']['totals'] === null) $PAGEDATA['SUMMARY']['totals'] = $summaryRow;
        else foreach (["equipment", "other", "total", "received", "outstanding"] as $key) $PAGEDATA['SUMMARY']['totals'][$key] = $PAGEDATA['SUMMARY']['totals'][$key]->add($summaryRow[$key]);
    }
}

if ($PAGEDATA['USERDATA']['instance']['instances_logo'] and $PAGEDATA['GET']['instancelogo']) {
    $PAGEDATA['INSTANCELOGO'] = $bCMS->s3DataUri($PAGEDATA['USERDATA']['instance']['instances_logo']);
} else $PAGEDATA['INSTANCELOGO'] = false;

echo $TWIG->render('project/pdf.twig', $PAGEDATA);