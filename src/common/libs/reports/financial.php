<?php
/**
 * Profit & loss, cash and debtor reports
 */

use Money\Money;

/**
 * Everything the profit and loss account adds up for a period
 */
function reportPnlFigures(ReportContext $ctx, $start, $end)
{
    $projects = reportRevenueProjects($ctx, $start, $end);
    $z = $ctx->zero();
    $f = ["equipmentGross" => $z, "discounts" => $z, "equipment" => $z, "sales" => $z, "subHire" => $z, "staff" => $z, "revenue" => $z,
        "salesCost" => $z, "subHireCost" => $z, "staffCost" => $z, "directCosts" => $z, "uncostedLines" => 0, "uncostedRevenue" => $z,
        "repairs" => $z, "depreciation" => $z, "disposals" => $z, "overheads" => [], "overheadsTotal" => $z, "projects" => 0];
    $calculated = [];
    foreach ($projects as $project) {
        if ($project['money'] === null) continue;
        $calculated[] = $project['projects_id'];
        $f['projects']++;
        foreach (["equipmentGross", "discounts", "equipment", "sales", "subHire", "staff"] as $key) $f[$key] = $f[$key]->add($project['money'][$key]);
        $f['revenue'] = $f['revenue']->add($project['money']['billed']);
    }
    //Direct costs - what the business paid for the lines it billed on those projects
    foreach (reportPayments($ctx, [2, 3, 4], $calculated) as $line) {
        $key = REPORT_PAYMENT_TYPES[$line['payments_type']] . "Cost";
        if ($line['costTotal'] === null) {
            $f['uncostedLines']++;
            $f['uncostedRevenue'] = $f['uncostedRevenue']->add($line['total']);
            continue;
        }
        $f[$key] = $f[$key]->add($line['costTotal']);
    }
    $f['directCosts'] = $f['salesCost']->add($f['subHireCost'], $f['staffCost']);
    $f['grossMargin'] = $f['revenue']->subtract($f['directCosts']);

    foreach (reportMaintenanceJobs($ctx) as $job) {
        if ($job['cost'] !== null and $ctx->inPeriod($job['added'], $start, $end)) $f['repairs'] = $f['repairs']->add($job['cost']);
    }
    $f['assetsWithoutCost'] = 0;
    foreach (reportAssets($ctx) as $asset) {
        $depreciation = reportDepreciation($ctx, $asset, $start, $end);
        if ($depreciation === null) {
            if (reportAssetHeldOn($asset, $end)) $f['assetsWithoutCost']++;
            continue;
        }
        $f['depreciation'] = $f['depreciation']->add($depreciation['charge']);
        $f['disposals'] = $f['disposals']->add($depreciation['disposal']);
    }
    $f['expenseCount'] = 0;
    foreach (reportExpenses($ctx, $start, $end) as $expense) {
        $category = $expense['expenses_category'];
        $f['overheads'][$category] = ($f['overheads'][$category] ?? $z)->add($expense['amount']);
        $f['overheadsTotal'] = $f['overheadsTotal']->add($expense['amount']);
        $f['expenseCount']++;
    }
    ksort($f['overheads'], SORT_NATURAL | SORT_FLAG_CASE);
    $f['assetCosts'] = $f['repairs']->add($f['depreciation'], $f['disposals']);
    $f['contribution'] = $f['grossMargin']->subtract($f['assetCosts']);
    //Interest and fees on loans and finance - repaying what was borrowed isn't a cost
    $f['financeCosts'] = reportFinanceCosts($ctx, $start, $end);
    $f['netProfit'] = $f['contribution']->subtract($f['overheadsTotal'], $f['financeCosts']);
    $f['uncalculated'] = reportUncalculated($projects);
    return $f;
}

/** The period before this one, of the same length */
function reportPreviousPeriod($start, $end)
{
    $days = reportDaysInclusive($start, $end);
    //Whole months compare with the same number of whole months before them
    if (substr($start, 8, 2) == "01" and $end == date("Y-m-t", strtotime($end))) {
        $months = count(reportMonths($start, $end));
        $previousStart = date("Y-m-01", strtotime($start . " -" . $months . " months"));
        return [$previousStart, date("Y-m-d", strtotime($start . " -1 day"))];
    }
    return [date("Y-m-d", strtotime($start . " -" . $days . " days")), date("Y-m-d", strtotime($start . " -1 day"))];
}

function reportProfitAndLoss(ReportContext $ctx)
{
    $now = reportPnlFigures($ctx, $ctx->start, $ctx->end);
    [$prevStart, $prevEnd] = reportPreviousPeriod($ctx->start, $ctx->end);
    $prev = reportPnlFigures($ctx, $prevStart, $prevEnd);
    $lastYear = reportPnlFigures($ctx, date("Y-m-d", strtotime($ctx->start . " -1 year")), date("Y-m-d", strtotime($ctx->end . " -1 year")));
    $periods = ["current" => $now, "previous" => $prev, "lastYear" => $lastYear];

    $rows = [];
    $line = function ($label, $key, $style = null, $indent = 0, $negate = false) use (&$rows, $periods) {
        $row = ["line" => $label, "_style" => $style, "_indent" => $indent];
        foreach ($periods as $name => $figures) {
            $value = is_callable($key) ? $key($figures) : $figures[$key];
            $row[$name] = $negate ? $value->negative() : $value;
        }
        $row['change'] = ReportContext::ratio($row['current']->subtract($row['previous']), $row['previous']->absolute());
        $rows[] = $row;
    };
    $percent = function ($label, $numerator, $denominator) use (&$rows, $periods) {
        $row = ["line" => $label, "_style" => "muted", "_indent" => 1];
        foreach ($periods as $name => $figures) $row[$name] = ReportContext::ratio($figures[$numerator], $figures[$denominator]);
        $row['change'] = null;
        $row['_percent'] = true;
        $rows[] = $row;
    };
    $heading = function ($label) use (&$rows) {
        $rows[] = ["line" => $label, "_style" => "heading"];
    };

    $heading("Revenue");
    $line("Equipment hire at rates", "equipmentGross", null, 1);
    $line("Less discounts", "discounts", null, 1, true);
    $line("Sales", "sales", null, 1);
    $line("Additional hire", "subHire", null, 1);
    $line("Staff", "staff", null, 1);
    $line("Total revenue", "revenue", "subtotal");
    $heading("Direct costs");
    $line("Cost of sales", "salesCost", null, 1);
    $line("Additional hire from suppliers", "subHireCost", null, 1);
    $line("Crew and staff", "staffCost", null, 1);
    $line("Total direct costs", "directCosts", "subtotal");
    $line("Gross margin", "grossMargin", "total");
    $percent("Gross margin %", "grossMargin", "revenue");
    $heading("Asset costs");
    $line("Repairs and maintenance", "repairs", null, 1);
    $line("Depreciation", "depreciation", null, 1);
    $line("Book value of assets disposed of", "disposals", null, 1);
    $line("Contribution after asset costs", "contribution", "subtotal");
    $heading("Overheads");
    $categories = array_unique(array_merge(array_keys($now['overheads']), array_keys($prev['overheads']), array_keys($lastYear['overheads'])));
    sort($categories, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($categories as $category) {
        $line($category, function ($figures) use ($category, $ctx) {
            return $figures['overheads'][$category] ?? $ctx->zero();
        }, null, 1);
    }
    $line("Total overheads", "overheadsTotal", "subtotal");
    $line("Interest and fees on loans and finance", "financeCosts", "subtotal");
    $line("Net profit", "netProfit", "total");
    $percent("Net margin %", "netProfit", "revenue");

    //Percent rows hold a fraction rather than money - the template and exports show them as percentages
    $columns = [reportColumn("line", ""), reportColumn("current", "This period", "money"), reportColumn("previous", "Previous period", "money"), reportColumn("lastYear", "Same period last year", "money"), reportColumn("change", "Change on previous", "percent")];
    //A year's previous period is the same period last year - show it once
    $sameAsLastYear = ($prevStart == date("Y-m-d", strtotime($ctx->start . " -1 year")) and $prevEnd == date("Y-m-d", strtotime($ctx->end . " -1 year")));
    if ($sameAsLastYear) {
        unset($columns[3]);
        $columns = array_values($columns);
        $columns[2]['label'] = "Previous year";
    }

    $warnings = [];
    if ($now['uncostedLines'] > 0) $warnings[] = $now['uncostedLines'] . " sales, additional hire or staff line" . ($now['uncostedLines'] == 1 ? " has" : "s have") . " no cost recorded (" . $ctx->decimal($now['uncostedRevenue']) . " of revenue), so " . ($now['uncostedLines'] == 1 ? "its" : "their") . " cost counts as nothing. Record costs on each project's Finance tab.";
    if ($now['assetsWithoutCost'] > 0) $warnings[] = $now['assetsWithoutCost'] . " asset" . ($now['assetsWithoutCost'] == 1 ? " has" : "s have") . " no purchase cost and date, so " . ($now['assetsWithoutCost'] == 1 ? "isn't" : "aren't") . " depreciated.";
    if ($now['expenseCount'] == 0) $warnings[] = "No overheads have been recorded for this period.";

    return [
        "kpis" => [
            ["label" => "Revenue", "value" => $now['revenue'], "type" => "money", "note" => $now['projects'] . " won project" . ($now['projects'] == 1 ? "" : "s")],
            ["label" => "Gross margin", "value" => $now['grossMargin'], "type" => "money", "note" => reportPercentText(ReportContext::ratio($now['grossMargin'], $now['revenue'])) . " of revenue"],
            ["label" => "Overheads", "value" => $now['overheadsTotal'], "type" => "money"],
            ["label" => "Net profit", "value" => $now['netProfit'], "type" => "money", "note" => reportPercentText(ReportContext::ratio($now['netProfit'], $now['revenue'])) . " of revenue"],
        ],
        "sections" => [["id" => "pnl", "title" => "Profit & loss account", "columns" => $columns, "rows" => $rows,
            "note" => "Previous period: " . date("j M Y", strtotime($prevStart)) . " – " . date("j M Y", strtotime($prevEnd)) . "." . ($sameAsLastYear ? "" : " Same period last year: " . date("j M Y", strtotime($ctx->start . " -1 year")) . " – " . date("j M Y", strtotime($ctx->end . " -1 year")) . ".")]],
        "warnings" => $warnings,
        "notes" => [
            "Revenue is what won projects in project types with finances bill, counted in the period their delivery ends. Equipment hire has no direct cost - the cost of owning it is its depreciation and repairs.",
            "Interest and fees are the charges added to loans and finance, and the interest part of repayments. Repaying what was borrowed isn't a cost, as buying the assets it paid for wasn't one either.",
            "Depreciation is straight line over each asset's life (" . intval($ctx->instance['instances_config_depreciationMonths'] ?? 60) . " months unless the asset has its own), from its purchase date. Repairs count in the period their job was opened.",
        ],
        "uncalculated" => $now['uncalculated'],
    ];
}

/** A fraction as a percentage, for notes */
function reportPercentText($fraction)
{
    return $fraction === null ? "—" : number_format($fraction * 100, 1, ".", "") . "%";
}

function reportProjectProfitability(ReportContext $ctx)
{
    $projects = reportRevenueProjects($ctx);
    $calculated = array_filter($projects, function ($p) { return $p['money'] !== null; });
    $costs = [];
    foreach (reportPayments($ctx, [2, 3, 4], array_keys($calculated)) as $line) {
        $id = $line['projects_id'];
        $costs[$id] = $costs[$id] ?? ["cost" => $ctx->zero(), "uncosted" => 0];
        if ($line['costTotal'] === null) $costs[$id]['uncosted']++;
        else $costs[$id]['cost'] = $costs[$id]['cost']->add($line['costTotal']);
    }
    $rows = [];
    $losses = 0;
    foreach ($calculated as $project) {
        $cost = $costs[$project['projects_id']]['cost'] ?? $ctx->zero();
        $margin = $project['money']['billed']->subtract($cost);
        if ($margin->isNegative()) $losses++;
        $rows[] = ["project" => reportProjectLink($project), "client" => reportClientName($project), "manager" => $project['managerName'], "end" => $project['end'],
            "equipment" => $project['money']['equipment'], "other" => $project['money']['sales']->add($project['money']['subHire'], $project['money']['staff']),
            "revenue" => $project['money']['billed'], "cost" => $cost, "margin" => $margin, "marginPercent" => ReportContext::ratio($margin, $project['money']['billed']),
            "uncosted" => $costs[$project['projects_id']]['uncosted'] ?? 0, "_style" => $margin->isNegative() ? "negative" : null];
    }
    usort($rows, function ($a, $b) { return $b['revenue']->compare($a['revenue']); });
    $columns = [reportColumn("project", "Project", "link"), reportColumn("client", "Client"), reportColumn("manager", "Project manager"), reportColumn("end", "Ended", "date"),
        reportColumn("equipment", "Equipment", "money", true), reportColumn("other", "Sales, hire & staff", "money", true), reportColumn("revenue", "Revenue", "money", true),
        reportColumn("cost", "Direct cost", "money", true), reportColumn("margin", "Margin", "money", true), reportColumn("marginPercent", "Margin %", "percent"), reportColumn("uncosted", "Lines without a cost", "number", true)];
    $totals = reportSumColumns($ctx, $columns, $rows);
    $totals['marginPercent'] = ReportContext::ratio($totals['margin'], $totals['revenue']);
    return [
        "kpis" => [
            ["label" => "Revenue", "value" => $totals['revenue'], "type" => "money", "note" => count($rows) . " project" . (count($rows) == 1 ? "" : "s")],
            ["label" => "Direct costs", "value" => $totals['cost'], "type" => "money"],
            ["label" => "Margin", "value" => $totals['margin'], "type" => "money", "note" => reportPercentText($totals['marginPercent'])],
            ["label" => "Projects at a loss", "value" => $losses, "type" => "number"],
        ],
        "sections" => [["id" => "projects", "title" => "Projects", "columns" => $columns, "rows" => $rows, "totals" => $totals]],
        "warnings" => $totals['uncosted'] > 0 ? [$totals['uncosted'] . " line" . ($totals['uncosted'] == 1 ? " has" : "s have") . " no cost recorded, so " . ($totals['uncosted'] == 1 ? "its" : "their") . " cost counts as nothing and those projects' margins are overstated."] : [],
        "notes" => ["Margin is revenue less the cost of each project's sales, additional hire and staff lines. Equipment you own has no direct cost - see the profit & loss for depreciation and repairs."],
        "uncalculated" => reportUncalculated($projects),
    ];
}

function reportRevenueTrend(ReportContext $ctx)
{
    $months = [];
    foreach (reportMonths($ctx->start, $ctx->end) as $month) $months[$month] = ["month" => $month, "equipment" => $ctx->zero(), "sales" => $ctx->zero(), "subHire" => $ctx->zero(), "staff" => $ctx->zero(), "total" => $ctx->zero(), "projects" => 0];
    $projects = reportRevenueProjects($ctx);
    foreach ($projects as $project) {
        if ($project['money'] === null) continue;
        $month = substr($project['end'], 0, 7);
        foreach (["equipment", "sales", "subHire", "staff"] as $key) $months[$month][$key] = $months[$month][$key]->add($project['money'][$key]);
        $months[$month]['total'] = $months[$month]['total']->add($project['money']['billed']);
        $months[$month]['projects']++;
    }
    $rows = array_values(array_map(function ($row) {
        $row['monthLabel'] = date("M Y", strtotime($row['month'] . "-01"));
        $row['average'] = $row['projects'] > 0 ? $row['total']->divide($row['projects']) : null;
        return $row;
    }, $months));
    $columns = [reportColumn("monthLabel", "Month"), reportColumn("projects", "Projects", "number", true), reportColumn("equipment", "Equipment", "money", true), reportColumn("sales", "Sales", "money", true),
        reportColumn("subHire", "Additional hire", "money", true), reportColumn("staff", "Staff", "money", true), reportColumn("total", "Total", "money", true), reportColumn("average", "Average project", "money")];
    $totals = reportSumColumns($ctx, $columns, $rows);
    $totals['average'] = $totals['projects'] > 0 ? $totals['total']->divide($totals['projects']) : null;
    $best = null;
    foreach ($rows as $row) if ($best === null or $row['total']->greaterThan($best['total'])) $best = $row;
    return [
        "kpis" => [
            ["label" => "Revenue", "value" => $totals['total'], "type" => "money"],
            ["label" => "Average month", "value" => count($rows) > 0 ? $totals['total']->divide(count($rows)) : null, "type" => "money"],
            ["label" => "Best month", "value" => $best ? $best['monthLabel'] : null, "type" => "text", "note" => $best ? $ctx->decimal($best['total']) : null],
            ["label" => "Average project", "value" => $totals['average'], "type" => "money"],
        ],
        "sections" => [["id" => "months", "title" => "Revenue by month", "columns" => $columns, "rows" => $rows, "totals" => $totals,
            "chart" => ["type" => "bars", "stacked" => true, "labels" => array_map(function ($r) { return date("M y", strtotime($r['month'] . "-01")); }, $rows),
                "series" => [["name" => "Equipment", "values" => array_column($rows, "equipment")], ["name" => "Sales", "values" => array_column($rows, "sales")],
                    ["name" => "Additional hire", "values" => array_column($rows, "subHire")], ["name" => "Staff", "values" => array_column($rows, "staff")]]]]],
        "notes" => ["Each won project's revenue counts in the month its delivery ends."],
        "uncalculated" => reportUncalculated($projects),
    ];
}

function reportExpensesReport(ReportContext $ctx)
{
    global $CONFIG;
    $expenses = reportExpenses($ctx, $ctx->start, $ctx->end);
    $total = $ctx->zero();
    $byCategory = [];
    $months = [];
    foreach (reportMonths($ctx->start, $ctx->end) as $month) $months[$month] = ["month" => date("M Y", strtotime($month . "-01")), "count" => 0, "amount" => $ctx->zero()];
    $detail = [];
    foreach ($expenses as $expense) {
        $total = $total->add($expense['amount']);
        $category = $expense['expenses_category'];
        $byCategory[$category] = $byCategory[$category] ?? ["category" => $category, "count" => 0, "amount" => $ctx->zero()];
        $byCategory[$category]['count']++;
        $byCategory[$category]['amount'] = $byCategory[$category]['amount']->add($expense['amount']);
        $month = substr($expense['expenses_date'], 0, 7);
        $months[$month]['count']++;
        $months[$month]['amount'] = $months[$month]['amount']->add($expense['amount']);
        $detail[] = ["date" => $expense['expenses_date'], "category" => $category, "supplier" => $expense['expenses_supplier'], "description" => $expense['expenses_description'], "reference" => $expense['expenses_reference'], "amount" => $expense['amount']];
    }
    $byCategory = array_values($byCategory);
    usort($byCategory, function ($a, $b) { return $b['amount']->compare($a['amount']); });
    foreach ($byCategory as $key => $row) $byCategory[$key]['share'] = ReportContext::ratio($row['amount'], $total);
    $catColumns = [reportColumn("category", "Category"), reportColumn("count", "Expenses", "number", true), reportColumn("amount", "Amount", "money", true), reportColumn("share", "Share", "bar")];
    $monthColumns = [reportColumn("month", "Month"), reportColumn("count", "Expenses", "number", true), reportColumn("amount", "Amount", "money", true)];
    $detailColumns = [reportColumn("date", "Date", "date"), reportColumn("category", "Category"), reportColumn("supplier", "Supplier"), reportColumn("description", "Description"), reportColumn("reference", "Reference"), reportColumn("amount", "Amount", "money", true)];
    return [
        "kpis" => [
            ["label" => "Overheads", "value" => $total, "type" => "money", "note" => count($expenses) . " expense" . (count($expenses) == 1 ? "" : "s")],
            ["label" => "Average month", "value" => count($months) > 0 ? $total->divide(count($months)) : null, "type" => "money"],
            ["label" => "Largest category", "value" => $byCategory[0]['category'] ?? null, "type" => "text", "note" => isset($byCategory[0]) ? $ctx->decimal($byCategory[0]['amount']) : null],
        ],
        "sections" => [
            ["id" => "categories", "title" => "By category", "columns" => $catColumns, "rows" => $byCategory, "totals" => reportSumColumns($ctx, $catColumns, $byCategory)],
            ["id" => "months", "title" => "By month", "columns" => $monthColumns, "rows" => array_values($months), "totals" => reportSumColumns($ctx, $monthColumns, array_values($months))],
            ["id" => "detail", "title" => "Every expense", "columns" => $detailColumns, "rows" => $detail, "totals" => reportSumColumns($ctx, $detailColumns, $detail)],
        ],
        "notes" => ["Overheads are recorded on the Overheads page (" . $CONFIG['ROOTURL'] . "/reports/expenses.php)."],
    ];
}

function reportDiscounts(ReportContext $ctx)
{
    $projects = reportRevenueProjects($ctx);
    $perProject = [];
    foreach (reportAssignments($ctx) as $assignment) {
        if (!isset($projects[$assignment['projects_id']]) or $assignment['excluded']) continue;
        $id = $assignment['projects_id'];
        $perProject[$id] = $perProject[$id] ?? ["list" => $ctx->zero(), "charged" => $ctx->zero(), "discounted" => 0];
        $perProject[$id]['list'] = $perProject[$id]['list']->add($assignment['list']);
        $perProject[$id]['charged'] = $perProject[$id]['charged']->add($assignment['price']);
        if ($assignment['price']->lessThan($assignment['list'])) $perProject[$id]['discounted']++;
    }
    $rows = [];
    $groups = ["client" => [], "manager" => []];
    $totalList = $ctx->zero();
    $totalDiscount = $ctx->zero();
    foreach ($perProject as $id => $figures) {
        $project = $projects[$id];
        $discount = $figures['list']->subtract($figures['charged']);
        $totalList = $totalList->add($figures['list']);
        $totalDiscount = $totalDiscount->add($discount);
        foreach (["client" => reportClientName($project), "manager" => ($project['managerName'] ?: "No project manager")] as $group => $name) {
            $groups[$group][$name] = $groups[$group][$name] ?? ["name" => $name, "projects" => 0, "list" => $ctx->zero(), "charged" => $ctx->zero(), "discount" => $ctx->zero()];
            $groups[$group][$name]['projects']++;
            $groups[$group][$name]['list'] = $groups[$group][$name]['list']->add($figures['list']);
            $groups[$group][$name]['charged'] = $groups[$group][$name]['charged']->add($figures['charged']);
            $groups[$group][$name]['discount'] = $groups[$group][$name]['discount']->add($discount);
        }
        if ($discount->isZero()) continue;
        $rows[] = ["project" => reportProjectLink($project), "client" => reportClientName($project), "manager" => $project['managerName'], "end" => $project['end'],
            "lines" => $figures['discounted'], "list" => $figures['list'], "charged" => $figures['charged'], "discount" => $discount, "rate" => ReportContext::ratio($discount, $figures['list'])];
    }
    usort($rows, function ($a, $b) { return $b['discount']->compare($a['discount']); });
    $groupRows = function ($group) use ($groups) {
        $rows = array_values($groups[$group]);
        foreach ($rows as $key => $row) $rows[$key]['rate'] = ReportContext::ratio($row['discount'], $row['list']);
        usort($rows, function ($a, $b) { return $b['discount']->compare($a['discount']); });
        return $rows;
    };
    $groupColumns = function ($label) {
        return [reportColumn("name", $label), reportColumn("projects", "Projects", "number", true), reportColumn("list", "At rates", "money", true), reportColumn("charged", "Charged", "money", true), reportColumn("discount", "Discount", "money", true), reportColumn("rate", "Discount rate", "bar")];
    };
    $sections = [];
    foreach (["client" => "Client", "manager" => "Project manager"] as $group => $label) {
        $r = $groupRows($group);
        $t = reportSumColumns($ctx, $groupColumns($label), $r);
        $t['rate'] = ReportContext::ratio($t['discount'], $t['list']);
        $sections[] = ["id" => $group, "title" => "By " . strtolower($label), "columns" => $groupColumns($label), "rows" => $r, "totals" => $t];
    }
    $projectColumns = [reportColumn("project", "Project", "link"), reportColumn("client", "Client"), reportColumn("manager", "Project manager"), reportColumn("end", "Ended", "date"), reportColumn("lines", "Discounted lines", "number", true),
        reportColumn("list", "At rates", "money", true), reportColumn("charged", "Charged", "money", true), reportColumn("discount", "Discount", "money", true), reportColumn("rate", "Discount rate", "percent")];
    $projectTotals = reportSumColumns($ctx, $projectColumns, $rows);
    $projectTotals['rate'] = ReportContext::ratio($projectTotals['discount'], $projectTotals['list']);
    $sections[] = ["id" => "projects", "title" => "Projects with a discount or custom price", "columns" => $projectColumns, "rows" => $rows, "totals" => $projectTotals];
    return [
        "kpis" => [
            ["label" => "Equipment at rates", "value" => $totalList, "type" => "money"],
            ["label" => "Given away", "value" => $totalDiscount, "type" => "money", "note" => reportPercentText(ReportContext::ratio($totalDiscount, $totalList)) . " of rates"],
            ["label" => "Projects discounted", "value" => count($rows), "type" => "number", "note" => "of " . count($perProject) . " with equipment"],
        ],
        "sections" => $sections,
        "notes" => ["Each piece of equipment's rates for the project's duration, against what it was charged at after any custom price and discount. A custom price above the rates counts as a negative discount."],
        "uncalculated" => [],
    ];
}

function reportCashReceipts(ReportContext $ctx)
{
    global $CONFIG;
    $payments = reportPayments($ctx, [1], null, $ctx->start, $ctx->end);
    $total = $ctx->zero();
    $months = [];
    foreach (reportMonths($ctx->start, $ctx->end) as $month) $months[$month] = ["month" => date("M Y", strtotime($month . "-01")), "count" => 0, "amount" => $ctx->zero()];
    $methods = [];
    $clients = [];
    $detail = [];
    foreach ($payments as $payment) {
        $total = $total->add($payment['total']);
        $month = substr($payment['date'], 0, 7);
        $months[$month]['count']++;
        $months[$month]['amount'] = $months[$month]['amount']->add($payment['total']);
        $method = $payment['payments_method'] ?: "Not recorded";
        $methods[$method] = $methods[$method] ?? ["method" => $method, "count" => 0, "amount" => $ctx->zero()];
        $methods[$method]['count']++;
        $methods[$method]['amount'] = $methods[$method]['amount']->add($payment['total']);
        $client = $payment['clients_name'] ?: "No client";
        $clients[$client] = $clients[$client] ?? ["client" => $client, "count" => 0, "amount" => $ctx->zero()];
        $clients[$client]['count']++;
        $clients[$client]['amount'] = $clients[$client]['amount']->add($payment['total']);
        $detail[] = ["date" => $payment['date'], "reference" => $payment['payments_reference'], "project" => ["text" => $payment['projects_name'], "href" => $CONFIG['ROOTURL'] . "/project/?id=" . $payment['projects_id']],
            "client" => $client, "method" => $method, "amount" => $payment['total']];
    }
    $share = function ($rows) use ($total) {
        $rows = array_values($rows);
        usort($rows, function ($a, $b) { return $b['amount']->compare($a['amount']); });
        foreach ($rows as $key => $row) $rows[$key]['share'] = ReportContext::ratio($row['amount'], $total);
        return $rows;
    };
    $methods = $share($methods);
    $clients = $share($clients);
    $monthColumns = [reportColumn("month", "Month"), reportColumn("count", "Payments", "number", true), reportColumn("amount", "Received", "money", true)];
    $methodColumns = [reportColumn("method", "Payment method"), reportColumn("count", "Payments", "number", true), reportColumn("amount", "Received", "money", true), reportColumn("share", "Share", "bar")];
    $clientColumns = [reportColumn("client", "Client"), reportColumn("count", "Payments", "number", true), reportColumn("amount", "Received", "money", true), reportColumn("share", "Share", "bar")];
    $detailColumns = [reportColumn("date", "Date", "date"), reportColumn("reference", "Reference"), reportColumn("project", "Project", "link"), reportColumn("client", "Client"), reportColumn("method", "Method"), reportColumn("amount", "Amount", "money", true)];
    $monthRows = array_values($months);
    return [
        "kpis" => [
            ["label" => "Received", "value" => $total, "type" => "money", "note" => count($payments) . " payment" . (count($payments) == 1 ? "" : "s")],
            ["label" => "Average payment", "value" => count($payments) > 0 ? $total->divide(count($payments)) : null, "type" => "money"],
            ["label" => "Paying clients", "value" => count($clients), "type" => "number"],
        ],
        "sections" => [
            ["id" => "months", "title" => "By month", "columns" => $monthColumns, "rows" => $monthRows, "totals" => reportSumColumns($ctx, $monthColumns, $monthRows),
                "chart" => count($monthRows) > 1 ? ["type" => "bars", "labels" => array_map(function ($r) { return date("M y", strtotime($r['month'])); }, $monthRows), "series" => [["name" => "Received", "values" => array_column($monthRows, "amount")]]] : null],
            ["id" => "methods", "title" => "By payment method", "columns" => $methodColumns, "rows" => $methods, "totals" => reportSumColumns($ctx, $methodColumns, $methods)],
            ["id" => "clients", "title" => "By client", "columns" => $clientColumns, "rows" => $clients, "totals" => reportSumColumns($ctx, $clientColumns, $clients)],
            ["id" => "payments", "title" => "Every payment", "columns" => $detailColumns, "rows" => $detail, "totals" => reportSumColumns($ctx, $detailColumns, $detail)],
        ],
        "notes" => ["Payments received on the business's projects, by the date they were received - the same payments as the payments ledger."],
    ];
}

/** Won projects that have ended, or whose payment is due, with something still to pay - the business's debtors */
function reportDebtorProjects(ReportContext $ctx)
{
    $today = $ctx->today;
    $debtors = [];
    $credits = [];
    $uncalculated = [];
    foreach (reportProjects($ctx) as $project) {
        if (!$project['isRevenue']) continue;
        $ended = ($project['end'] !== null && $project['end'] < $today);
        $due = ($project['dueDate'] !== null && $project['dueDate'] < $today);
        if (!$ended and !$due) continue;
        if ($project['money'] === null) {
            $uncalculated[] = $project;
            continue;
        }
        $project['daysPastDue'] = ($project['dueDate'] !== null and $project['dueDate'] < $today) ? reportDaysInclusive($project['dueDate'], $today) - 1 : 0;
        if ($project['money']['outstanding']->isNegative()) $credits[] = $project;
        elseif ($project['money']['outstanding']->isPositive()) $debtors[] = $project;
    }
    return ["debtors" => $debtors, "credits" => $credits, "uncalculated" => reportUncalculated($uncalculated)];
}

const REPORT_AGE_BANDS = ["notDue" => "Not yet due", "d30" => "1–30 days", "d60" => "31–60 days", "d90" => "61–90 days", "d90plus" => "Over 90 days", "collections" => "In collections", "writtenOff" => "Written off"];

/** Which age band a debtor's balance falls in */
function reportAgeBand($project)
{
    if ($project['projects_collectionStatus'] == "COLLECTIONS") return "collections";
    if ($project['projects_collectionStatus'] == "WRITTEN_OFF") return "writtenOff";
    if ($project['daysPastDue'] <= 0) return "notDue";
    if ($project['daysPastDue'] <= 30) return "d30";
    if ($project['daysPastDue'] <= 60) return "d60";
    if ($project['daysPastDue'] <= 90) return "d90";
    return "d90plus";
}

function reportAgedDebtors(ReportContext $ctx)
{
    $data = reportDebtorProjects($ctx);
    $clients = [];
    $detail = [];
    $bandTotals = array_map(function () use ($ctx) { return $ctx->zero(); }, REPORT_AGE_BANDS);
    foreach ($data['debtors'] as $project) {
        $band = reportAgeBand($project);
        $name = reportClientName($project);
        if (!isset($clients[$name])) {
            $clients[$name] = ["client" => $name, "projects" => 0, "total" => $ctx->zero()];
            foreach (REPORT_AGE_BANDS as $key => $label) $clients[$name][$key] = $ctx->zero();
        }
        $clients[$name]['projects']++;
        $clients[$name][$band] = $clients[$name][$band]->add($project['money']['outstanding']);
        $clients[$name]['total'] = $clients[$name]['total']->add($project['money']['outstanding']);
        $bandTotals[$band] = $bandTotals[$band]->add($project['money']['outstanding']);
        $detail[] = ["project" => reportProjectLink($project), "client" => $name, "end" => $project['end'], "due" => $project['dueDate'], "days" => $project['daysPastDue'],
            "band" => REPORT_AGE_BANDS[$band], "billed" => $project['money']['billed'], "received" => $project['money']['received'], "outstanding" => $project['money']['outstanding']];
    }
    $clients = array_values($clients);
    usort($clients, function ($a, $b) { return $b['total']->compare($a['total']); });
    usort($detail, function ($a, $b) { return $b['days'] <=> $a['days']; });
    $clientColumns = [reportColumn("client", "Client"), reportColumn("projects", "Projects", "number", true)];
    foreach (REPORT_AGE_BANDS as $key => $label) $clientColumns[] = reportColumn($key, $label, "money", true);
    $clientColumns[] = reportColumn("total", "Total owed", "money", true);
    $detailColumns = [reportColumn("project", "Project", "link"), reportColumn("client", "Client"), reportColumn("end", "Ended", "date"), reportColumn("due", "Due", "date"), reportColumn("days", "Days overdue", "number"),
        reportColumn("band", "Age"), reportColumn("billed", "Billed", "money", true), reportColumn("received", "Received", "money", true), reportColumn("outstanding", "Outstanding", "money", true)];
    $creditRows = array_map(function ($p) {
        return ["project" => reportProjectLink($p), "client" => reportClientName($p), "end" => $p['end'], "credit" => $p['money']['outstanding']->negative()];
    }, $data['credits']);
    $creditColumns = [reportColumn("project", "Project", "link"), reportColumn("client", "Client"), reportColumn("end", "Ended", "date"), reportColumn("credit", "Overpaid by", "money", true)];
    $owed = $ctx->zero();
    foreach ($clients as $client) $owed = $owed->add($client['total']);
    $overdue = $bandTotals['d30']->add($bandTotals['d60'], $bandTotals['d90'], $bandTotals['d90plus']);
    $sections = [
        ["id" => "clients", "title" => "By client", "columns" => $clientColumns, "rows" => $clients, "totals" => reportSumColumns($ctx, $clientColumns, $clients), "empty" => "Nothing is owed on any won project that has ended."],
        ["id" => "projects", "title" => "By project", "columns" => $detailColumns, "rows" => $detail, "totals" => reportSumColumns($ctx, $detailColumns, $detail), "empty" => "Nothing is owed on any won project that has ended."],
    ];
    if (count($creditRows) > 0) $sections[] = ["id" => "credits", "title" => "Credit balances", "note" => "Projects that have been paid more than they bill.", "columns" => $creditColumns, "rows" => $creditRows, "totals" => reportSumColumns($ctx, $creditColumns, $creditRows)];
    return [
        "kpis" => [
            ["label" => "Owed", "value" => $owed, "type" => "money", "note" => count($detail) . " project" . (count($detail) == 1 ? "" : "s")],
            ["label" => "Overdue", "value" => $overdue, "type" => "money", "note" => reportPercentText(ReportContext::ratio($overdue, $owed)) . " of what's owed"],
            ["label" => "Over 90 days", "value" => $bandTotals['d90plus'], "type" => "money"],
            ["label" => "In collections or written off", "value" => $bandTotals['collections']->add($bandTotals['writtenOff']), "type" => "money"],
        ],
        "sections" => $sections,
        "notes" => ["Won projects that have ended, or whose payment is already due, with something still to pay. Payment is due " . intval($ctx->instance['instances_config_paymentDueDays']) . " days after a project ends unless it has its own due date. Balances are as they stand today."],
        "uncalculated" => $data['uncalculated'],
    ];
}

function reportBilledVsCollected(ReportContext $ctx)
{
    $months = [];
    foreach (reportMonths($ctx->start, $ctx->end) as $month) $months[$month] = ["month" => date("M Y", strtotime($month . "-01")), "billed" => $ctx->zero(), "collected" => $ctx->zero()];
    $projects = reportRevenueProjects($ctx);
    foreach ($projects as $project) {
        if ($project['money'] === null) continue;
        $month = substr($project['end'], 0, 7);
        $months[$month]['billed'] = $months[$month]['billed']->add($project['money']['billed']);
    }
    foreach (reportPayments($ctx, [1], null, $ctx->start, $ctx->end) as $payment) {
        $month = substr($payment['date'], 0, 7);
        $months[$month]['collected'] = $months[$month]['collected']->add($payment['total']);
    }
    $gap = $ctx->zero();
    $rows = [];
    foreach ($months as $row) {
        $row['difference'] = $row['billed']->subtract($row['collected']);
        $gap = $gap->add($row['difference']);
        $row['gap'] = $gap;
        $row['rate'] = ReportContext::ratio($row['collected'], $row['billed']);
        $rows[] = $row;
    }
    $columns = [reportColumn("month", "Month"), reportColumn("billed", "Billed", "money", true), reportColumn("collected", "Collected", "money", true), reportColumn("difference", "Difference", "money", true), reportColumn("gap", "Running gap", "money"), reportColumn("rate", "Collected / billed", "percent")];
    $totals = reportSumColumns($ctx, $columns, $rows);
    $totals['rate'] = ReportContext::ratio($totals['collected'], $totals['billed']);
    $totals['gap'] = $gap;
    return [
        "kpis" => [
            ["label" => "Billed", "value" => $totals['billed'], "type" => "money"],
            ["label" => "Collected", "value" => $totals['collected'], "type" => "money"],
            ["label" => "Gap", "value" => $gap, "type" => "money", "note" => "billed but not yet collected"],
            ["label" => "Collected / billed", "value" => $totals['rate'], "type" => "percent"],
        ],
        "sections" => [["id" => "months", "title" => "By month", "columns" => $columns, "rows" => $rows, "totals" => $totals,
            "chart" => ["type" => "bars", "labels" => array_map(function ($r) { return date("M y", strtotime($r['month'])); }, $rows), "series" => [["name" => "Billed", "values" => array_column($rows, "billed")], ["name" => "Collected", "values" => array_column($rows, "collected")]]]]],
        "notes" => ["Billed is the revenue of won projects whose delivery ended in the month (accrual). Collected is the payments received in the month, on any project (cash). A payment can be for work billed in an earlier month, or a deposit for later work."],
        "uncalculated" => reportUncalculated($projects),
    ];
}

function reportWriteOffs(ReportContext $ctx)
{
    $rows = [];
    $totals = ["COLLECTIONS" => $ctx->zero(), "WRITTEN_OFF" => $ctx->zero()];
    $uncalculated = [];
    foreach (reportProjects($ctx) as $project) {
        if (!$project['hasFinance'] or !in_array($project['projects_collectionStatus'], ["COLLECTIONS", "WRITTEN_OFF"], true)) continue;
        if ($project['money'] === null) {
            $uncalculated[] = $project;
            continue;
        }
        $totals[$project['projects_collectionStatus']] = $totals[$project['projects_collectionStatus']]->add($project['money']['outstanding']);
        $rows[] = ["status" => PROJECT_COLLECTION_STATUSES[$project['projects_collectionStatus']], "project" => reportProjectLink($project), "client" => reportClientName($project), "end" => $project['end'], "due" => $project['dueDate'],
            "billed" => $project['money']['billed'], "received" => $project['money']['received'], "outstanding" => $project['money']['outstanding'], "recovered" => ReportContext::ratio($project['money']['received'], $project['money']['billed'])];
    }
    usort($rows, function ($a, $b) { return [$a['status'], $b['outstanding']->getAmount()] <=> [$b['status'], $a['outstanding']->getAmount()]; });
    $columns = [reportColumn("status", "Status"), reportColumn("project", "Project", "link"), reportColumn("client", "Client"), reportColumn("end", "Ended", "date"), reportColumn("due", "Due", "date"),
        reportColumn("billed", "Billed", "money", true), reportColumn("received", "Received", "money", true), reportColumn("outstanding", "Outstanding", "money", true), reportColumn("recovered", "Recovered", "percent")];
    $sumRow = reportSumColumns($ctx, $columns, $rows);
    $sumRow['recovered'] = ReportContext::ratio($sumRow['received'], $sumRow['billed']);
    return [
        "kpis" => [
            ["label" => "At risk (in collections)", "value" => $totals['COLLECTIONS'], "type" => "money"],
            ["label" => "Written off", "value" => $totals['WRITTEN_OFF'], "type" => "money"],
            ["label" => "Projects", "value" => count($rows), "type" => "number"],
        ],
        "sections" => [["id" => "projects", "title" => "Projects in collections or written off", "columns" => $columns, "rows" => $rows, "totals" => $sumRow, "empty" => "No project is in collections or written off."]],
        "notes" => ["A project is put into collections or written off from its Finance tab. Outstanding is what's still unpaid on it today."],
        "uncalculated" => reportUncalculated($uncalculated),
    ];
}

function reportClosePack(ReportContext $ctx)
{
    $definitions = reportDefinitions();
    $pnl = reportProfitAndLoss($ctx);
    $cash = reportCashReceipts($ctx);
    $billed = reportBilledVsCollected($ctx);
    $debtors = reportAgedDebtors($ctx);
    $pipelineCtx = clone $ctx;
    $pipelineCtx->params['horizon'] = 3;
    $pipeline = reportPipeline($pipelineCtx);
    //Loans and finance at the end of the period, for those who can see them
    global $AUTH;
    $liabilities = ["sections" => [], "uncalculated" => []];
    if (!isset($AUTH) or $AUTH->instancePermissionCheck("FINANCE:LIABILITIES:VIEW")) {
        $liabilitiesCtx = clone $ctx;
        $liabilitiesCtx->asAt = min($ctx->end, $ctx->today);
        $liabilities = reportLiabilitiesReport($liabilitiesCtx);
    }
    $sections = [];
    foreach ([["profit-and-loss", $pnl, ["pnl"]], ["cash-receipts", $cash, ["months", "methods"]], ["billed-vs-collected", $billed, ["months"]], ["aged-debtors", $debtors, ["clients"]], ["pipeline", $pipeline, ["months"]], ["liabilities", $liabilities, ["liabilities"]]] as [$key, $report, $ids]) {
        $first = true;
        foreach ($report['sections'] as $section) {
            if (!in_array($section['id'], $ids, true)) continue;
            $section['heading'] = $definitions[$key]['title'] . ($key == "aged-debtors" ? " (today)" : ($key == "pipeline" ? " (next 3 months)" : ($key == "liabilities" ? " (at the end of the period)" : "")));
            $section['headingFirst'] = $first;
            $section['id'] = $key . "-" . $section['id'];
            $first = false;
            $sections[] = $section;
        }
    }
    $uncalculated = [];
    foreach ([$pnl, $billed, $debtors, $pipeline] as $report) foreach ($report['uncalculated'] as $p) $uncalculated[$p['id']] = $p;
    return [
        "kpis" => [$pnl['kpis'][0], $pnl['kpis'][3], $cash['kpis'][0], $debtors['kpis'][0], $debtors['kpis'][1]],
        "sections" => $sections,
        "warnings" => $pnl['warnings'],
        "notes" => array_merge($pnl['notes'], $billed['notes']),
        "uncalculated" => array_values($uncalculated),
    ];
}
