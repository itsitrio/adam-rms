<?php
require_once __DIR__ . '/../common/headSecure.php';
if (!$AUTH->instancePermissionCheck("PROJECTS:VIEW") or !isset($_GET['id'])) die($TWIG->render('404.twig', $PAGEDATA));
$QUOTE_OMIT_HIDDEN_ASSIGNMENTS = (!isset($_GET['type']) or $_GET['type'] != "deliveryNote"); //Hidden assignments are left off quotes and invoices, but delivery notes list everything
require_once __DIR__ . '/../api/projects/data.php'; //Where most of the data comes from

$PAGEDATA['GET'] = $_GET;
$PAGEDATA['GET']['generate'] = true;

$isQuote = $_GET['type'] == "quote";
$isDeliveryNote = $_GET['type'] == "deliveryNote";
$PAGEDATA['GET']['quote'] = $isQuote;
$PAGEDATA['GET']['deliveryNote'] = $isDeliveryNote;

$typeId = 20;
$PAGEDATA['documentType'] = "invoice";
$PAGEDATA['GET']['fileType'] = documentName('invoice', 'Invoice');

switch ($_GET['type']) {
    case 'quote':
        $typeId = 21;
        $PAGEDATA['documentType'] = "quote";
        $PAGEDATA['GET']['fileType'] = documentName('quote', 'Quotation');
        break;
    case 'deliveryNote':
        $typeId = 22;
        $PAGEDATA['documentType'] = "deliveryNote";
        $PAGEDATA['GET']['fileType'] = documentName('deliveryNote', 'Delivery Note');
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
    $PAGEDATA['CHILDREN'] = subProjectsWithFinancials($PAGEDATA['project']['subProjects']);
    $PAGEDATA['SUMMARY'] = projectsSummary(array_merge([["project" => $PAGEDATA['project'], "FINANCIALS" => $PAGEDATA['FINANCIALS']]], $PAGEDATA['CHILDREN']));
}

if ($PAGEDATA['USERDATA']['instance']['instances_logo'] and $PAGEDATA['GET']['instancelogo']) {
    $PAGEDATA['INSTANCELOGO'] = $bCMS->s3DataUri($PAGEDATA['USERDATA']['instance']['instances_logo']);
} else $PAGEDATA['INSTANCELOGO'] = false;

$PAGEDATA['DOCUMENTS'] = documentSettings();
//A preview is shown in the page rather than downloaded or stored. The settings page previews settings before
//they are saved by posting them - they change nothing, and go through the same checks as saving them does
$PAGEDATA['PREVIEW'] = isset($_GET['preview']);
if ($PAGEDATA['PREVIEW'] and count($_POST) > 0) {
    $previewSettings = documentSettingsFromInput(documentSettingsStored(), $_POST);
    if (is_array($previewSettings)) {
        $PAGEDATA['DOCUMENTS'] = documentSettingsForDisplay($previewSettings);
        foreach (['invoice' => 'Invoice', 'quote' => 'Quotation', 'deliveryNote' => 'Delivery Note'] as $type => $name) {
            if ($type == $PAGEDATA['documentType']) $PAGEDATA['GET']['fileType'] = $previewSettings['names'][$type] !== "" ? $previewSettings['names'][$type] : $name;
        }
    }
}

echo $TWIG->render('project/pdf.twig', $PAGEDATA);