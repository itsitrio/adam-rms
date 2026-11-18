<?php
/**
 * Maintenance and crew reports
 */

function reportMaintenance(ReportContext $ctx)
{
    global $CONFIG;
    $assets = [];
    foreach (reportAssets($ctx) as $asset) $assets[$asset['assets_id']] = $asset;
    $types = [];
    $jobs = [];
    $blocked = [];
    $cost = $ctx->zero();
    $openNow = 0;
    $uncosted = 0;
    foreach (reportMaintenanceJobs($ctx) as $job) {
        $jobAssets = array_values(array_filter(array_map(function ($id) use ($assets) { return $assets[$id] ?? null; }, $job['assetIds'])));
        $age = reportDaysInclusive($job['added'], $ctx->today) - 1;
        if ($job['isOpen']) $openNow++;
        if ($job['isOpen'] and $job['maintenanceJobs_blockAssets'] == 1) {
            foreach ($jobAssets as $asset) {
                $rate = $ctx->money($asset['assets_dayRate'] !== null ? $asset['assets_dayRate'] : $asset['assetTypes_dayRate'])->multiply($asset['quantityHeld']);
                $blocked[] = ["asset" => reportAssetLink($asset), "job" => ["text" => $job['maintenanceJobs_title'] ?: "Job " . $job['maintenanceJobs_id'], "href" => $CONFIG['ROOTURL'] . "/maintenance/job.php?id=" . $job['maintenanceJobs_id']],
                    "since" => $job['added'], "days" => $age, "dayRate" => $rate, "forgone" => $rate->multiply($age)];
            }
        }
        if (!$ctx->inPeriod($job['added'])) continue;
        if ($job['cost'] !== null) $cost = $cost->add($job['cost']);
        else $uncosted++;
        $jobs[] = ["job" => ["text" => $job['maintenanceJobs_title'] ?: "Job " . $job['maintenanceJobs_id'], "href" => $CONFIG['ROOTURL'] . "/maintenance/job.php?id=" . $job['maintenanceJobs_id']], "added" => $job['added'],
            "status" => $job['maintenanceJobsStatuses_name'] ?: "No status", "open" => $job['isOpen'] ? "Open" : "Closed", "age" => $job['isOpen'] ? $age : null, "assets" => count($jobAssets), "cost" => $job['cost']];
        //A job's cost is shared between the types of the assets on it
        $typeIds = array_count_values(array_map(function ($a) { return $a['assetTypes_id']; }, $jobAssets));
        if (count($typeIds) == 0) $typeIds = [0 => 1];
        foreach ($typeIds as $typeId => $count) {
            $name = $typeId ? $jobAssets[array_search($typeId, array_column($jobAssets, 'assetTypes_id'))]['assetTypes_name'] : "No asset";
            $types[$typeId] = $types[$typeId] ?? ["type" => $name, "jobs" => 0, "open" => 0, "ageTotal" => 0, "cost" => $ctx->zero(), "assets" => 0];
            $types[$typeId]['jobs']++;
            $types[$typeId]['assets'] += $count;
            if ($job['isOpen']) {
                $types[$typeId]['open']++;
                $types[$typeId]['ageTotal'] += $age;
            }
            if ($job['cost'] !== null) $types[$typeId]['cost'] = $types[$typeId]['cost']->add($job['cost']->multiply(reportFactor($count / max(1, count($jobAssets)))));
        }
    }
    $typeRows = array_values(array_map(function ($row) {
        $row['averageAge'] = $row['open'] > 0 ? intval(round($row['ageTotal'] / $row['open'])) : null;
        unset($row['ageTotal']);
        return $row;
    }, $types));
    usort($typeRows, function ($a, $b) { return [$b['jobs'], $b['cost']->getAmount()] <=> [$a['jobs'], $a['cost']->getAmount()]; });
    usort($blocked, function ($a, $b) { return $b['forgone']->compare($a['forgone']); });
    $typeColumns = [reportColumn("type", "Asset type"), reportColumn("jobs", "Jobs", "number", true), reportColumn("assets", "Assets on jobs", "number", true), reportColumn("open", "Still open", "number", true), reportColumn("averageAge", "Average days open", "number"), reportColumn("cost", "Repair cost", "money", true)];
    $jobColumns = [reportColumn("job", "Job", "link"), reportColumn("added", "Opened", "date"), reportColumn("status", "Status"), reportColumn("open", "Open or closed"), reportColumn("age", "Days open", "number"), reportColumn("assets", "Assets", "number", true), reportColumn("cost", "Cost", "money", true)];
    $blockedColumns = [reportColumn("asset", "Asset", "link"), reportColumn("job", "Job", "link"), reportColumn("since", "Blocked since", "date"), reportColumn("days", "Days", "number"), reportColumn("dayRate", "Day rate", "money"), reportColumn("forgone", "Hire at day rate while blocked", "money", true)];
    $forgone = $ctx->zero();
    foreach ($blocked as $row) $forgone = $forgone->add($row['forgone']);
    return [
        "kpis" => [
            ["label" => "Jobs opened", "value" => count($jobs), "type" => "number"],
            ["label" => "Repair cost", "value" => $cost, "type" => "money"],
            ["label" => "Open now", "value" => $openNow, "type" => "number"],
            ["label" => "Assets blocked now", "value" => count($blocked), "type" => "number", "note" => $ctx->decimal($forgone) . " of hire at day rates"],
        ],
        "sections" => [
            ["id" => "types", "title" => "By asset type", "columns" => $typeColumns, "rows" => $typeRows, "totals" => reportSumColumns($ctx, $typeColumns, $typeRows), "empty" => "No maintenance jobs were opened in this period."],
            ["id" => "blocked", "title" => "Assets blocked from projects now", "columns" => $blockedColumns, "rows" => $blocked, "totals" => reportSumColumns($ctx, $blockedColumns, $blocked), "empty" => "No open job is blocking its assets."],
            ["id" => "jobs", "title" => "Jobs opened in the period", "columns" => $jobColumns, "rows" => $jobs, "totals" => reportSumColumns($ctx, $jobColumns, $jobs)],
        ],
        "warnings" => $uncosted > 0 ? [$uncosted . " job" . ($uncosted == 1 ? " has" : "s have") . " no cost recorded. Add it on each job's page."] : [],
        "notes" => ["A job's cost is shared between the types of the assets on it. Hire at day rate is what a blocked asset would earn at its day rate for every day it's been blocked - the most it could have lost, if there had been work for it every day."],
    ];
}

function reportCrew(ReportContext $ctx)
{
    global $DBLIB;
    $DBLIB->join("projects", "crewAssignments.projects_id=projects.projects_id", "LEFT");
    $DBLIB->join("projectsStatuses", "projects.projectsStatuses_id=projectsStatuses.projectsStatuses_id", "LEFT");
    $DBLIB->join("users", "crewAssignments.users_userid=users.users_userid", "LEFT");
    $DBLIB->where("projects.instances_id", $ctx->instanceId);
    $DBLIB->where("projects.projects_deleted", 0);
    $DBLIB->where("crewAssignments.crewAssignments_deleted", 0);
    $DBLIB->where("projects.projects_dates_deliver_start", $ctx->end . " 23:59:59", "<=");
    $DBLIB->where("projects.projects_dates_deliver_end", $ctx->start . " 00:00:00", ">=");
    $DBLIB->where("(projectsStatuses.projectsStatuses_pipelineStage IS NULL OR projectsStatuses.projectsStatuses_pipelineStage NOT IN ('LOST', 'CANCELLED'))");
    $rows = $DBLIB->get("crewAssignments", null, ["crewAssignments.crewAssignments_role", "crewAssignments.crewAssignments_personName", "crewAssignments.users_userid", "users.users_name1", "users.users_name2",
        "projects.projects_id", "projects.projects_dates_deliver_start", "projects.projects_dates_deliver_end", "projectsStatuses.projectsStatuses_pipelineStage"]);
    $people = [];
    $roles = [];
    $vacant = 0;
    foreach ($rows as $row) {
        $role = trim((string) $row['crewAssignments_role']) ?: "No role";
        $roles[$role] = $roles[$role] ?? ["role" => $role, "assignments" => 0, "filled" => 0, "projects" => []];
        $roles[$role]['assignments']++;
        $roles[$role]['projects'][$row['projects_id']] = true;
        $name = $row['users_userid'] ? trim($row['users_name1'] . " " . $row['users_name2']) : trim((string) $row['crewAssignments_personName']);
        if ($name === "") {
            $vacant++;
            continue;
        }
        $roles[$role]['filled']++;
        $key = $row['users_userid'] ? "u" . $row['users_userid'] : "n" . strtolower($name);
        $people[$key] = $people[$key] ?? ["person" => $name, "member" => $row['users_userid'] ? "Yes" : "No", "assignments" => 0, "projects" => [], "won" => [], "roles" => []];
        $people[$key]['assignments']++;
        $days = reportOverlapDays(substr($row['projects_dates_deliver_start'], 0, 10), substr($row['projects_dates_deliver_end'], 0, 10), $ctx->start, $ctx->end);
        $people[$key]['projects'][$row['projects_id']] = $days;
        if ($row['projectsStatuses_pipelineStage'] == "WON") $people[$key]['won'][$row['projects_id']] = true;
        $people[$key]['roles'][$role] = true;
    }
    $personRows = array_values(array_map(function ($p) {
        return ["person" => $p['person'], "member" => $p['member'], "assignments" => $p['assignments'], "projects" => count($p['projects']), "won" => count($p['won']), "days" => array_sum($p['projects']), "roles" => implode(", ", array_keys($p['roles']))];
    }, $people));
    usort($personRows, function ($a, $b) { return [$b['days'], $b['projects']] <=> [$a['days'], $a['projects']]; });
    $roleRows = array_values(array_map(function ($r) {
        return ["role" => $r['role'], "assignments" => $r['assignments'], "filled" => $r['filled'], "vacant" => $r['assignments'] - $r['filled'], "projects" => count($r['projects'])];
    }, $roles));
    usort($roleRows, function ($a, $b) { return $b['assignments'] <=> $a['assignments']; });
    $personColumns = [reportColumn("person", "Person"), reportColumn("member", "Business user"), reportColumn("projects", "Projects", "number", true), reportColumn("won", "Of which won", "number", true), reportColumn("assignments", "Roles filled", "number", true), reportColumn("days", "Project days", "number", true), reportColumn("roles", "Roles")];
    $roleColumns = [reportColumn("role", "Role"), reportColumn("assignments", "Roles", "number", true), reportColumn("filled", "Filled", "number", true), reportColumn("vacant", "Not filled", "number", true), reportColumn("projects", "Projects", "number", true)];
    return [
        "kpis" => [
            ["label" => "People crewing", "value" => count($personRows), "type" => "number"],
            ["label" => "Roles filled", "value" => count($rows) - $vacant, "type" => "number"],
            ["label" => "Roles without anyone", "value" => $vacant, "type" => "number"],
            ["label" => "Busiest", "value" => $personRows[0]['person'] ?? null, "type" => "text", "note" => isset($personRows[0]) ? $personRows[0]['days'] . " project days" : null],
        ],
        "sections" => [
            ["id" => "people", "title" => "By person", "columns" => $personColumns, "rows" => $personRows, "totals" => reportSumColumns($ctx, $personColumns, $personRows), "empty" => "Nobody is crewing a project in this period."],
            ["id" => "roles", "title" => "By role", "columns" => $roleColumns, "rows" => $roleRows, "totals" => reportSumColumns($ctx, $roleColumns, $roleRows), "empty" => "No crew roles on projects in this period."],
        ],
        "notes" => ["Crew on projects whose delivery overlaps the period, other than lost and cancelled ones. Project days are the days of each project's delivery that fall in the period - the most a person could have worked on it."],
    ];
}
