<?php
require_once __DIR__ . '/../common/headSecure.php';
require_once __DIR__ . '/../common/libs/reports/engine.php';

//The list of reports this user can run, by group
$PAGEDATA['pageConfig'] = ["TITLE" => "Reports", "BREADCRUMB" => false];
$PAGEDATA['groups'] = [];
foreach (REPORT_GROUPS as $key => $group) $PAGEDATA['groups'][$key] = array_merge($group, ["reports" => []]);
foreach (reportDefinitions() as $key => $definition) {
    if (!$AUTH->instancePermissionCheck($definition['permission'])) continue;
    $PAGEDATA['groups'][$definition['group']]['reports'][$key] = $definition;
}
$PAGEDATA['groups'] = array_filter($PAGEDATA['groups'], function ($group) {
    return count($group['reports']) > 0;
});
if (count($PAGEDATA['groups']) == 0 and !$AUTH->instancePermissionCheck("FINANCE:EXPENSES:VIEW")) die($TWIG->render('404.twig', $PAGEDATA));
echo $TWIG->render('reports/reports_index.twig', $PAGEDATA);
