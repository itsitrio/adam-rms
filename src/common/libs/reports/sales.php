<?php
/**
 * Client, pipeline and conversion reports
 */

function reportClientStatement(ReportContext $ctx)
{
    global $CONFIG;
    $client = $ctx->params['client'];
    if (!$client) {
        return ["sections" => [["id" => "statement", "title" => "Statement of account", "columns" => [reportColumn("date", "Date", "date")], "rows" => [], "empty" => "Choose a client to see their statement."]]];
    }
    $projects = array_filter(reportProjects($ctx), function ($p) use ($client) {
        return $p['clients_id'] == $client['clients_id'] and $p['hasFinance'];
    });
    $billable = array_filter($projects, function ($p) use ($ctx) {
        return $p['isRevenue'] and $p['end'] !== null and $p['end'] <= $ctx->end;
    });
    $opening = $ctx->zero();
    $entries = [];
    foreach ($billable as $project) {
        if ($project['money'] === null) continue;
        if ($project['end'] < $ctx->start) $opening = $opening->add($project['money']['billed']);
        else $entries[] = ["date" => $project['end'], "sort" => $project['end'] . " 0", "description" => reportProjectLink($project), "type" => "Billed", "reference" => "Project " . $project['projects_id'], "billed" => $project['money']['billed'], "paid" => null];
    }
    foreach (reportPayments($ctx, [1], array_keys($projects), null, $ctx->end) as $payment) {
        if ($payment['date'] < $ctx->start) {
            $opening = $opening->subtract($payment['total']);
            continue;
        }
        $entries[] = ["date" => $payment['date'], "sort" => $payment['date'] . " 1", "description" => ["text" => $payment['projects_name'], "href" => $CONFIG['ROOTURL'] . "/project/?id=" . $payment['projects_id']], "type" => "Payment" . ($payment['payments_method'] ? " - " . $payment['payments_method'] : ""), "reference" => $payment['payments_reference'], "billed" => null, "paid" => $payment['total']];
    }
    usort($entries, function ($a, $b) { return $a['sort'] <=> $b['sort']; });
    $balance = $opening;
    $rows = [["date" => $ctx->start, "description" => "Balance brought forward", "type" => "", "reference" => "", "billed" => null, "paid" => null, "balance" => $opening, "_style" => "muted"]];
    $billedTotal = $ctx->zero();
    $paidTotal = $ctx->zero();
    foreach ($entries as $entry) {
        if ($entry['billed']) {
            $balance = $balance->add($entry['billed']);
            $billedTotal = $billedTotal->add($entry['billed']);
        }
        if ($entry['paid']) {
            $balance = $balance->subtract($entry['paid']);
            $paidTotal = $paidTotal->add($entry['paid']);
        }
        $entry['balance'] = $balance;
        unset($entry['sort']);
        $rows[] = $entry;
    }
    $columns = [reportColumn("date", "Date", "date"), reportColumn("description", "Project"), reportColumn("type", "Details"), reportColumn("reference", "Reference"), reportColumn("billed", "Billed", "money"), reportColumn("paid", "Paid", "money"), reportColumn("balance", "Balance", "money")];
    $columns[1]['type'] = "link";
    //What's still to pay on each project, today
    $open = [];
    foreach ($billable as $project) {
        if ($project['money'] === null or $project['money']['outstanding']->isZero()) continue;
        $project['daysPastDue'] = ($project['dueDate'] !== null and $project['dueDate'] < $ctx->today) ? reportDaysInclusive($project['dueDate'], $ctx->today) - 1 : 0;
        $open[] = ["project" => reportProjectLink($project), "end" => $project['end'], "due" => $project['dueDate'], "days" => $project['daysPastDue'], "billed" => $project['money']['billed'], "received" => $project['money']['received'], "outstanding" => $project['money']['outstanding']];
    }
    $openColumns = [reportColumn("project", "Project", "link"), reportColumn("end", "Ended", "date"), reportColumn("due", "Due", "date"), reportColumn("days", "Days overdue", "number"), reportColumn("billed", "Billed", "money", true), reportColumn("received", "Paid", "money", true), reportColumn("outstanding", "Still to pay", "money", true)];
    return [
        "recipient" => ["name" => $client['clients_name'], "address" => $client['clients_address'], "email" => $client['clients_email']],
        "kpis" => [
            ["label" => "Brought forward", "value" => $opening, "type" => "money"],
            ["label" => "Billed", "value" => $billedTotal, "type" => "money"],
            ["label" => "Paid", "value" => $paidTotal, "type" => "money"],
            ["label" => "Balance", "value" => $balance, "type" => "money", "note" => "at " . date("j M Y", strtotime($ctx->end))],
        ],
        "sections" => [
            ["id" => "statement", "title" => "Statement of account", "columns" => $columns, "rows" => $rows, "totals" => ["date" => "Balance", "billed" => $billedTotal, "paid" => $paidTotal, "balance" => $balance]],
            ["id" => "open", "title" => "Still to pay, by project", "columns" => $openColumns, "rows" => $open, "totals" => reportSumColumns($ctx, $openColumns, $open), "empty" => "Nothing is owed."],
        ],
        "notes" => ["Each project is billed on the day its delivery ends. A negative balance is money paid in advance."],
        "uncalculated" => reportUncalculated($billable),
    ];
}

function reportClientLeague(ReportContext $ctx)
{
    $projects = reportRevenueProjects($ctx);
    $clients = [];
    $total = $ctx->zero();
    foreach ($projects as $project) {
        if ($project['money'] === null) continue;
        $name = reportClientName($project);
        $clients[$name] = $clients[$name] ?? ["client" => $name, "clients_id" => $project['clients_id'], "projects" => 0, "revenue" => $ctx->zero(), "projectIds" => [], "dayWeight" => 0.0, "paidWeight" => 0.0];
        $clients[$name]['projects']++;
        $clients[$name]['revenue'] = $clients[$name]['revenue']->add($project['money']['billed']);
        $clients[$name]['projectIds'][] = $project['projects_id'];
        $total = $total->add($project['money']['billed']);
    }
    //How long after a project ends its payments come in, weighted by how much each payment was
    $byProject = [];
    foreach ($projects as $p) $byProject[$p['projects_id']] = $p;
    foreach (reportPayments($ctx, [1], array_keys($byProject)) as $payment) {
        $project = $byProject[$payment['projects_id']];
        $name = reportClientName($project);
        if (!isset($clients[$name])) continue;
        $days = (strtotime($payment['date']) - strtotime($project['end'])) / 86400;
        $amount = floatval($payment['total']->getAmount());
        $clients[$name]['dayWeight'] += $days * $amount;
        $clients[$name]['paidWeight'] += $amount;
    }
    //What each client owes today, on any project
    $owed = [];
    foreach (reportDebtorProjects($ctx)['debtors'] as $p) $owed[reportClientName($p)] = ($owed[reportClientName($p)] ?? $ctx->zero())->add($p['money']['outstanding']);
    $rows = array_values($clients);
    usort($rows, function ($a, $b) { return $b['revenue']->compare($a['revenue']); });
    $cumulative = $ctx->zero();
    foreach ($rows as $key => $row) {
        $cumulative = $cumulative->add($row['revenue']);
        $rows[$key] = ["rank" => $key + 1, "client" => $row['client'], "projects" => $row['projects'], "revenue" => $row['revenue'], "share" => ReportContext::ratio($row['revenue'], $total),
            "cumulative" => ReportContext::ratio($cumulative, $total), "average" => $row['revenue']->divide($row['projects']),
            "daysToPay" => $row['paidWeight'] > 0 ? intval(round($row['dayWeight'] / $row['paidWeight'])) : null, "owed" => $owed[$row['client']] ?? $ctx->zero()];
    }
    $columns = [reportColumn("rank", "#", "number"), reportColumn("client", "Client"), reportColumn("projects", "Projects", "number", true), reportColumn("revenue", "Revenue", "money", true), reportColumn("share", "Share", "bar"),
        reportColumn("cumulative", "Cumulative share", "percent"), reportColumn("average", "Average project", "money"), reportColumn("daysToPay", "Days to pay", "number"), reportColumn("owed", "Owed today", "money", true)];
    $totals = reportSumColumns($ctx, $columns, $rows);
    $totals['rank'] = "";
    $totals['client'] = "Total";
    $topFive = $ctx->zero();
    foreach (array_slice($rows, 0, 5) as $row) $topFive = $topFive->add($row['revenue']);
    return [
        "kpis" => [
            ["label" => "Clients", "value" => count($rows), "type" => "number"],
            ["label" => "Revenue", "value" => $total, "type" => "money"],
            ["label" => "Largest client", "value" => $rows[0]['client'] ?? null, "type" => "text", "note" => isset($rows[0]) ? reportPercentText($rows[0]['share']) . " of revenue" : null],
            ["label" => "Top 5 clients", "value" => ReportContext::ratio($topFive, $total), "type" => "percent", "note" => "of revenue"],
        ],
        "sections" => [["id" => "clients", "title" => "Clients by revenue", "columns" => $columns, "rows" => $rows, "totals" => $totals]],
        "notes" => ["Days to pay is how long after a project ends its payments arrive, on average, weighted by the size of each payment - a negative number means they pay in advance."],
        "uncalculated" => reportUncalculated($projects),
    ];
}

function reportPipeline(ReportContext $ctx)
{
    $horizon = $ctx->params['horizon'] ?? 6;
    $first = date("Y-m-01", strtotime($ctx->today));
    $last = date("Y-m-t", strtotime($first . " +" . ($horizon - 1) . " months"));
    $months = [];
    foreach (reportMonths($first, $last) as $month) $months[$month] = ["month" => date("M Y", strtotime($month . "-01")), "WON" => $ctx->zero(), "OPPORTUNITY" => $ctx->zero(), "LEAD" => $ctx->zero(), "count" => 0];
    $included = [];
    $chase = [];
    foreach (reportProjects($ctx) as $project) {
        if (!$project['hasFinance'] or !in_array($project['stage'], ["WON", "OPPORTUNITY", "LEAD"], true)) continue;
        if ($project['end'] === null or $project['end'] < $ctx->today) continue; //Already over
        $start = $project['start'] ?? $project['end'];
        $month = substr(max($start, $first), 0, 7); //Work under way counts this month
        if (!isset($months[$month])) continue;
        $included[] = $project;
        if ($project['money'] === null) continue;
        $months[$month][$project['stage']] = $months[$month][$project['stage']]->add($project['money']['billed']);
        $months[$month]['count']++;
        if ($project['stage'] != "WON") $chase[] = ["project" => reportProjectLink($project), "stage" => PROJECT_PIPELINE_STAGES[$project['stage']], "status" => $project['projectsStatuses_name'], "client" => reportClientName($project), "manager" => $project['managerName'], "start" => $start, "value" => $project['money']['billed']];
    }
    $rows = [];
    foreach ($months as $row) {
        $row['total'] = $row['WON']->add($row['OPPORTUNITY'], $row['LEAD']);
        $rows[] = $row;
    }
    usort($chase, function ($a, $b) { return $b['value']->compare($a['value']); });
    $columns = [reportColumn("month", "Month"), reportColumn("count", "Projects", "number", true), reportColumn("WON", "Won", "money", true), reportColumn("OPPORTUNITY", "Opportunities", "money", true), reportColumn("LEAD", "Leads", "money", true), reportColumn("total", "Total", "money", true)];
    $totals = reportSumColumns($ctx, $columns, $rows);
    $chaseColumns = [reportColumn("project", "Project", "link"), reportColumn("stage", "Stage"), reportColumn("status", "Status"), reportColumn("client", "Client"), reportColumn("manager", "Project manager"), reportColumn("start", "Starts", "date"), reportColumn("value", "Value", "money", true)];
    return [
        "kpis" => [
            ["label" => "Won", "value" => $totals['WON'], "type" => "money", "note" => "confirmed work"],
            ["label" => "Opportunities", "value" => $totals['OPPORTUNITY'], "type" => "money"],
            ["label" => "Leads", "value" => $totals['LEAD'], "type" => "money"],
            ["label" => "Total pipeline", "value" => $totals['total'], "type" => "money"],
        ],
        "sections" => [
            ["id" => "months", "title" => "By month", "columns" => $columns, "rows" => $rows, "totals" => $totals,
                "chart" => ["type" => "bars", "stacked" => true, "labels" => array_map(function ($r) { return date("M y", strtotime($r['month'])); }, $rows),
                    "series" => [["name" => "Won", "values" => array_column($rows, "WON")], ["name" => "Opportunities", "values" => array_column($rows, "OPPORTUNITY")], ["name" => "Leads", "values" => array_column($rows, "LEAD")]]]],
            ["id" => "chase", "title" => "Opportunities and leads to follow up", "columns" => $chaseColumns, "rows" => $chase, "totals" => reportSumColumns($ctx, $chaseColumns, $chase), "empty" => "No open opportunities or leads in these months."],
        ],
        "notes" => ["Projects that haven't finished yet, by the month they start - work already under way counts this month. Each is valued at what it would bill as it stands."],
        "uncalculated" => reportUncalculated($included),
    ];
}

function reportWinLoss(ReportContext $ctx)
{
    $groups = ["overall" => [], "type" => [], "manager" => []];
    $included = [];
    foreach (reportProjects($ctx) as $project) {
        if (!$project['hasFinance'] or !$ctx->inPeriod($project['projects_created'])) continue;
        $included[] = $project;
        foreach (["overall" => "All projects", "type" => ($project['projectsTypes_name'] ?: "No type"), "manager" => ($project['managerName'] ?: "No project manager")] as $group => $name) {
            if (!isset($groups[$group][$name])) {
                $groups[$group][$name] = ["name" => $name, "total" => 0];
                foreach (["WON", "LOST", "CANCELLED", "OPEN"] as $outcome) {
                    $groups[$group][$name][$outcome] = 0;
                    $groups[$group][$name][$outcome . "_value"] = $ctx->zero();
                }
            }
            $outcome = in_array($project['stage'], ["WON", "LOST", "CANCELLED"], true) ? $project['stage'] : "OPEN";
            $groups[$group][$name][$outcome]++;
            $groups[$group][$name]['total']++;
            if ($project['money'] !== null) $groups[$group][$name][$outcome . "_value"] = $groups[$group][$name][$outcome . "_value"]->add($project['money']['billed']);
        }
    }
    $finish = function ($rows) {
        foreach ($rows as $key => $row) {
            $rows[$key]['rate'] = ($row['WON'] + $row['LOST']) > 0 ? $row['WON'] / ($row['WON'] + $row['LOST']) : null;
            $decidedValue = $row['WON_value']->add($row['LOST_value']);
            $rows[$key]['valueRate'] = $decidedValue->isZero() ? null : ReportContext::ratio($row['WON_value'], $decidedValue);
        }
        usort($rows, function ($a, $b) { return $b['total'] <=> $a['total']; });
        return array_values($rows);
    };
    $columns = function ($label) {
        return [reportColumn("name", $label), reportColumn("total", "Projects", "number", true), reportColumn("WON", "Won", "number", true), reportColumn("LOST", "Lost", "number", true), reportColumn("CANCELLED", "Cancelled", "number", true), reportColumn("OPEN", "Still open", "number", true),
            reportColumn("rate", "Win rate", "bar"), reportColumn("WON_value", "Won value", "money", true), reportColumn("LOST_value", "Lost value", "money", true), reportColumn("OPEN_value", "Open value", "money", true), reportColumn("valueRate", "Win rate by value", "percent")];
    };
    $overall = $finish($groups['overall'])[0] ?? null;
    $sections = [];
    foreach (["type" => "Project type", "manager" => "Project manager"] as $group => $label) {
        $rows = $finish($groups[$group]);
        $sections[] = ["id" => $group, "title" => "By " . strtolower($label), "columns" => $columns($label), "rows" => $rows, "totals" => $overall ? array_merge($overall, ["name" => "Total"]) : null];
    }
    return [
        "kpis" => [
            ["label" => "Projects", "value" => $overall['total'] ?? 0, "type" => "number", "note" => "created in the period"],
            ["label" => "Win rate", "value" => $overall['rate'] ?? null, "type" => "percent", "note" => "of those won or lost"],
            ["label" => "Win rate by value", "value" => $overall['valueRate'] ?? null, "type" => "percent"],
            ["label" => "Still open", "value" => $overall['OPEN'] ?? 0, "type" => "number", "note" => isset($overall) ? $ctx->decimal($overall['OPEN_value']) : null],
        ],
        "sections" => $sections,
        "notes" => ["Projects created in the period, by where they are in the sales pipeline now. The win rate leaves out cancelled projects and those still being decided."],
        "uncalculated" => reportUncalculated($included),
    ];
}
