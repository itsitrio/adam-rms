<?php
require_once __DIR__ . '/../common/headSecure.php';
require_once __DIR__ . '/../common/libs/reports/engine.php';

//One report - shown on the page, printed (?format=print), or downloaded (?format=csv or ?format=xlsx)
$definitions = reportDefinitions();
$key = (isset($_GET['report']) and is_string($_GET['report'])) ? $_GET['report'] : "";
if (!isset($definitions[$key]) or !$AUTH->instancePermissionCheck($definitions[$key]['permission'])) die($TWIG->render('404.twig', $PAGEDATA));
$definition = $definitions[$key];

$ctx = new ReportContext($AUTH->data['instance']);
$PAGEDATA['form'] = reportReadParams($ctx, $definition, $_GET);
$PAGEDATA['report'] = reportBuild($ctx, $definition);
$PAGEDATA['definition'] = $definition;
$PAGEDATA['reportKey'] = $key;
$PAGEDATA['DOCUMENTS'] = documentSettings();
$PAGEDATA['periodLabel'] = reportPeriodLabel($ctx, $definition, $PAGEDATA['DOCUMENTS']['dateFormat']);
$PAGEDATA['generated'] = date($PAGEDATA['DOCUMENTS']['dateFormat'] . " " . $PAGEDATA['DOCUMENTS']['timeFormat']);
$PAGEDATA['groups'] = REPORT_GROUPS;
//The link to this report with the same options, for the print and download buttons
$PAGEDATA['query'] = http_build_query(array_intersect_key($_GET, array_flip(["report", "period", "start", "end", "asAt", "horizon", "inactive", "detail", "client"])));
$PAGEDATA['pageConfig'] = ["TITLE" => $definition['title'], "BREADCRUMB" => false];

$format = (isset($_GET['format']) and is_string($_GET['format'])) ? $_GET['format'] : "page";
$meta = ["title" => $definition['title'], "business" => $AUTH->data['instance']['instances_name'], "period" => $PAGEDATA['periodLabel'], "generated" => $PAGEDATA['generated']];
$filename = preg_replace('/[^a-z0-9-]+/', '-', strtolower($key . "-" . ($ctx->start ?? $ctx->asAt) . ($ctx->end ? "-to-" . $ctx->end : "")));
$bCMS->auditLog("VIEW-REPORT", "reports", $key . " " . $format . " " . $PAGEDATA['periodLabel'], $AUTH->data['users_userid']);

if ($format == "csv") {
    reportSendCsv($ctx, $PAGEDATA['report'], $meta, $filename);
    exit;
}
if ($format == "xlsx") {
    reportSendXlsx($ctx, $PAGEDATA['report'], $meta, $filename);
    exit;
}
if ($format == "print") {
    $PAGEDATA['INSTANCELOGO'] = false;
    if ($AUTH->data['instance']['instances_logo']) {
        try {
            $PAGEDATA['INSTANCELOGO'] = $bCMS->s3DataUri($AUTH->data['instance']['instances_logo']);
        } catch (Throwable $e) {
            $PAGEDATA['INSTANCELOGO'] = false;
        }
    }
    echo $TWIG->render('reports/reports_print.twig', $PAGEDATA);
    exit;
}
echo $TWIG->render('reports/reports_report.twig', $PAGEDATA);
