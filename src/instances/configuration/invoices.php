<?php
require_once __DIR__ . '/../../common/headSecure.php';

$PAGEDATA['pageConfig'] = ["TITLE" => "Business Invoice Settings", "BREADCRUMB" => false];

if (!$AUTH->instancePermissionCheck("BUSINESS:BUSINESS_SETTINGS:VIEW")) die($TWIG->render('404.twig', $PAGEDATA));


//The newest project, to preview the settings on
$DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects_deleted", 0);
$DBLIB->orderBy("projects_id", "DESC");
$PAGEDATA['previewProject'] = $AUTH->instancePermissionCheck("PROJECTS:VIEW") ? $DBLIB->getOne("projects", ["projects_id", "projects_name"]) : null;

echo $TWIG->render('instances/configuration/instances_configuration_invoices.twig', $PAGEDATA);
?>
