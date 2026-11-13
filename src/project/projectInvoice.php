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

//The business's own text for the cover page and the start of the document, with its formatting and placeholders
$PAGEDATA['documentPlaceholders'] = [
    "{project}" => $PAGEDATA['project']['projects_name'], "{client}" => (string) ($PAGEDATA['project']['clients_name'] ?? ""),
    "{business}" => $AUTH->data['instance']['instances_name'], "{manager}" => (string) ($PAGEDATA['project']['projects_manager_name'] ?? ""),
    "{managerEmail}" => (string) ($PAGEDATA['project']['projects_manager_email'] ?? ""), "{document}" => $PAGEDATA['GET']['fileType'],
    "{date}" => date($PAGEDATA['DOCUMENTS']['dateFormat']), "{number}" => (string) $PAGEDATA['project']['projects_id'],
    "{reference}" => strtr($PAGEDATA['DOCUMENTS']['reference'][$PAGEDATA['documentType']] ?: "v{version}", ["{project}" => $PAGEDATA['project']['projects_id'], "{version}" => $fileNumber]),
];
foreach (["cover" => "COVERTEXT", "intro" => "INTROTEXT"] as $setting => $key) {
    $PAGEDATA[$key] = documentFormattedText($PAGEDATA['DOCUMENTS'][$setting][$PAGEDATA['documentType']], $PAGEDATA['documentPlaceholders'], $PAGEDATA['DOCUMENTS']['contentWidth'], $PAGEDATA['DOCUMENTS']['accentColour'] ?: "#000000");
}
//The footer for this type of document, formatted the same way. The quote and invoice footers are stored as cleaned HTML
$footer = $PAGEDATA['DOCUMENTS']['deliveryNoteFooter'];
if ($PAGEDATA['documentType'] == "quote") $footer = html_entity_decode(strip_tags((string) $AUTH->data['instance']['instances_quoteTerms']), ENT_QUOTES | ENT_HTML5);
elseif ($PAGEDATA['documentType'] == "invoice") $footer = html_entity_decode(strip_tags(str_replace(["</p>", "<br/>"], "\n", (string) $AUTH->data['instance']['instances_termsAndPayment'])), ENT_QUOTES | ENT_HTML5);
$PAGEDATA['FOOTERTEXT'] = documentFormattedText(trim($footer), $PAGEDATA['documentPlaceholders'], $PAGEDATA['DOCUMENTS']['contentWidth'] - 10, $PAGEDATA['DOCUMENTS']['accentColour'] ?: "#000000");

echo $TWIG->render('project/pdf.twig', $PAGEDATA);