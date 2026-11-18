<?php
/**
 * The reports suite: which reports there are, the options each takes (its period, a client, ...), and turning a
 * built report into CSV or a spreadsheet.
 *
 * A report builder takes a ReportContext and returns an array:
 * - kpis: [["label" => ..., "value" => Money|int|float|string|null, "type" => money|number|percent|text, "note" => ...]]
 * - sections: [["id" => ..., "title" => ..., "note" => ..., "columns" => [["key", "label", "type"]], "rows" => [...],
 *               "totals" => row|null, "empty" => message, "chart" => [...]|null, "heading" => report title|null]]
 *   Column types: text, link (["text", "href"]), money, number, percent (a fraction), date (Y-m-d), bar (a fraction)
 *   A row can have _style (heading, subtotal, total, muted) and _indent.
 * - notes: things the reader should know about how the figures are worked out
 * - warnings: things that make the figures less complete than they look
 * - uncalculated: [["id", "name"]] projects whose finances haven't been worked out yet
 */

use Money\Money;

require_once __DIR__ . '/data.php';
require_once __DIR__ . '/financial.php';
require_once __DIR__ . '/sales.php';
require_once __DIR__ . '/assets.php';
require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/liabilities.php';

const REPORT_GROUPS = [
    "finance" => ["name" => "Profit & loss", "icon" => "fas fa-balance-scale"],
    "cash" => ["name" => "Cash & debtors", "icon" => "fas fa-coins"],
    "sales" => ["name" => "Sales & clients", "icon" => "fas fa-handshake"],
    "assets" => ["name" => "Assets", "icon" => "fas fa-boxes"],
    "operations" => ["name" => "Operations", "icon" => "fas fa-tools"],
];

/**
 * Every report. params: the options it takes - period, asAt, client, horizon (months ahead), inactive (months
 * without a booking), detail (list every asset)
 */
function reportDefinitions()
{
    return [
        "close-pack" => ["title" => "Period close pack", "group" => "finance", "permission" => "FINANCE:REPORTS:VIEW", "params" => ["period"], "defaultPeriod" => "last-month", "builder" => "reportClosePack",
            "description" => "Profit and loss, cash received, billed against collected, aged debtors and the pipeline - everything for a month or quarter end, in one document."],
        "profit-and-loss" => ["title" => "Profit & loss", "group" => "finance", "permission" => "FINANCE:REPORTS:VIEW", "params" => ["period"], "defaultPeriod" => "fy-to-date", "builder" => "reportProfitAndLoss",
            "description" => "Revenue, direct costs, gross margin, repairs, depreciation, overheads and net profit, compared with the previous period and the same period last year."],
        "project-profitability" => ["title" => "Project profitability", "group" => "finance", "permission" => "FINANCE:REPORTS:VIEW", "params" => ["period"], "defaultPeriod" => "fy-to-date", "builder" => "reportProjectProfitability",
            "description" => "The margin on each project once the cost of its sales, additional hire and staff lines is taken off."],
        "revenue-trend" => ["title" => "Monthly revenue", "group" => "finance", "permission" => "FINANCE:REPORTS:VIEW", "params" => ["period"], "defaultPeriod" => "last-12", "builder" => "reportRevenueTrend",
            "description" => "Revenue by month, split into equipment hire, sales, additional hire and staff."],
        "expenses" => ["title" => "Overheads", "group" => "finance", "permission" => "FINANCE:EXPENSES:VIEW", "params" => ["period"], "defaultPeriod" => "fy-to-date", "builder" => "reportExpensesReport",
            "description" => "The business's overheads by category and month, and every expense in the period."],
        "discounts" => ["title" => "Discounts given", "group" => "finance", "permission" => "FINANCE:REPORTS:VIEW", "params" => ["period"], "defaultPeriod" => "fy-to-date", "builder" => "reportDiscounts",
            "description" => "How far below its rates equipment was hired out - discounts and custom prices - by client, project manager and project."],
        "liabilities" => ["title" => "Loans & finance", "group" => "finance", "permission" => "FINANCE:LIABILITIES:VIEW", "params" => ["asAt"], "builder" => "reportLiabilitiesReport",
            "description" => "What the business owes on loans, finance and credit - borrowed, paid off, interest and fees, what's still owed, and the repayments due over the next year."],
        "cash-receipts" => ["title" => "Cash received", "group" => "cash", "permission" => "FINANCE:REPORTS:VIEW", "params" => ["period"], "defaultPeriod" => "last-month", "builder" => "reportCashReceipts",
            "description" => "Payments received by month, payment method and client, and every payment in the period."],
        "aged-debtors" => ["title" => "Aged debtors", "group" => "cash", "permission" => "FINANCE:REPORTS:VIEW", "params" => [], "builder" => "reportAgedDebtors",
            "description" => "What each client owes, by how long it's been overdue, with collections and write-offs shown separately."],
        "billed-vs-collected" => ["title" => "Billed against collected", "group" => "cash", "permission" => "FINANCE:REPORTS:VIEW", "params" => ["period"], "defaultPeriod" => "last-12", "builder" => "reportBilledVsCollected",
            "description" => "What was earned each month next to what was paid, and the running gap between them."],
        "write-offs" => ["title" => "Collections & write-offs", "group" => "cash", "permission" => "FINANCE:REPORTS:VIEW", "params" => [], "builder" => "reportWriteOffs",
            "description" => "Projects in collections or written off, and how much is at risk or lost."],
        "client-statement" => ["title" => "Client statement", "group" => "sales", "permission" => "FINANCE:REPORTS:VIEW", "params" => ["client", "period"], "defaultPeriod" => "fy-to-date", "builder" => "reportClientStatement",
            "description" => "A statement of account for one client - what was billed, what they paid and what they owe - to send them."],
        "client-league" => ["title" => "Client league table", "group" => "sales", "permission" => "FINANCE:REPORTS:VIEW", "params" => ["period"], "defaultPeriod" => "fy-to-date", "builder" => "reportClientLeague",
            "description" => "Revenue by client, their share of the total, average job size, how long they take to pay and what they owe."],
        "pipeline" => ["title" => "Pipeline & forecast", "group" => "sales", "permission" => "FINANCE:REPORTS:VIEW", "params" => ["horizon"], "builder" => "reportPipeline",
            "description" => "Upcoming work by month - won, opportunities and leads - for the months ahead."],
        "win-loss" => ["title" => "Win/loss", "group" => "sales", "permission" => "FINANCE:REPORTS:VIEW", "params" => ["period"], "defaultPeriod" => "fy-to-date", "builder" => "reportWinLoss",
            "description" => "How many quotes became work, by number and by value, for each project type and project manager."],
        "asset-roi" => ["title" => "Asset return", "group" => "assets", "permission" => "FINANCE:REPORTS:VIEW", "params" => ["period"], "defaultPeriod" => "last-12", "builder" => "reportAssetReturn",
            "description" => "Revenue each asset type earned against what it's worth and what it cost - its yield and how far it has paid for itself."],
        "utilisation" => ["title" => "Utilisation", "group" => "assets", "permission" => "REPORTS:VIEW", "params" => ["period"], "defaultPeriod" => "last-12", "builder" => "reportUtilisation",
            "description" => "The share of days each asset type spent out on won projects."],
        "dead-stock" => ["title" => "Dead stock", "group" => "assets", "permission" => "REPORTS:VIEW", "params" => ["inactive"], "builder" => "reportDeadStock",
            "description" => "Assets that haven't been booked for months, and the value tied up in them."],
        "asset-register" => ["title" => "Asset register", "group" => "assets", "permission" => "REPORTS:VIEW", "params" => ["asAt", "detail"], "builder" => "reportAssetRegister",
            "description" => "What the business holds on a date, by category and storage location, with replacement values and book values - for insurers and the accounts."],
        "maintenance" => ["title" => "Maintenance", "group" => "operations", "permission" => "REPORTS:VIEW", "params" => ["period"], "defaultPeriod" => "last-12", "builder" => "reportMaintenance",
            "description" => "Repairs by asset type - how many, what they cost, how long they've been open, and the hire a blocked asset can't earn."],
        "crew" => ["title" => "Crew workload", "group" => "operations", "permission" => "REPORTS:VIEW", "params" => ["period"], "defaultPeriod" => "last-month", "builder" => "reportCrew",
            "description" => "How many projects and days each person has been crewing, and in which roles."],
    ];
}

/**
 * The periods a report can be run for, worked out from the business's financial year
 *
 * @return array key => ["label", "start", "end"] (Y-m-d, inclusive)
 */
function reportPeriodPresets($financialYearStart, $today = null)
{
    $today = $today ?? date("Y-m-d");
    $t = strtotime($today);
    $y = intval(date("Y", $t));
    $m = intval(date("n", $t));
    $fyStartMonth = max(1, min(12, intval($financialYearStart)));
    $fyYear = $m >= $fyStartMonth ? $y : $y - 1;
    $fyStart = sprintf("%04d-%02d-01", $fyYear, $fyStartMonth);
    $fyEnd = date("Y-m-d", strtotime($fyStart . " +1 year -1 day"));
    $quarterStart = sprintf("%04d-%02d-01", $y, (intdiv($m - 1, 3) * 3) + 1);
    $fyLabel = function ($start) use ($fyStartMonth) {
        $startYear = intval(substr($start, 0, 4));
        return $fyStartMonth == 1 ? (string) $startYear : $startYear . "/" . substr((string) ($startYear + 1), 2);
    };
    $presets = [
        "this-month" => ["label" => "This month", "start" => date("Y-m-01", $t), "end" => date("Y-m-t", $t)],
        "last-month" => ["label" => "Last month", "start" => date("Y-m-01", strtotime(date("Y-m-01", $t) . " -1 month")), "end" => date("Y-m-t", strtotime(date("Y-m-01", $t) . " -1 month"))],
        "this-quarter" => ["label" => "This quarter", "start" => $quarterStart, "end" => date("Y-m-d", strtotime($quarterStart . " +3 months -1 day"))],
        "last-quarter" => ["label" => "Last quarter", "start" => date("Y-m-d", strtotime($quarterStart . " -3 months")), "end" => date("Y-m-d", strtotime($quarterStart . " -1 day"))],
        "fy-to-date" => ["label" => "Financial year to date (" . $fyLabel($fyStart) . ")", "start" => $fyStart, "end" => $today],
        "this-fy" => ["label" => "This financial year (" . $fyLabel($fyStart) . ")", "start" => $fyStart, "end" => $fyEnd],
        "last-fy" => ["label" => "Last financial year (" . $fyLabel(date("Y-m-d", strtotime($fyStart . " -1 year"))) . ")", "start" => date("Y-m-d", strtotime($fyStart . " -1 year")), "end" => date("Y-m-d", strtotime($fyStart . " -1 day"))],
        "last-12" => ["label" => "Last 12 months", "start" => date("Y-m-01", strtotime(date("Y-m-01", $t) . " -11 months")), "end" => date("Y-m-t", $t)],
    ];
    return $presets;
}

/** Whether a string is a real Y-m-d date */
function reportIsDate($value)
{
    if (!is_string($value) or !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
    [$y, $m, $d] = array_map('intval', explode("-", $value));
    return checkdate($m, $d, $y) and $y >= 1900 and $y <= 2200;
}

/**
 * Reads a report's options from the request into the context. Anything missing or not valid falls back to its
 * default rather than failing, so a report always opens.
 *
 * @return array The options as the report's form shows them
 */
function reportReadParams(ReportContext $ctx, $definition, $input)
{
    global $DBLIB;
    $form = [];
    if (in_array("period", $definition['params'])) {
        $presets = reportPeriodPresets($ctx->instance['instances_config_financialYearStart'] ?? 1, $ctx->today);
        $period = (isset($input['period']) and is_string($input['period'])) ? $input['period'] : ($definition['defaultPeriod'] ?? "last-12");
        if ($period == "custom" and reportIsDate($input['start'] ?? null) and reportIsDate($input['end'] ?? null) and $input['start'] <= $input['end']) {
            $ctx->start = $input['start'];
            $ctx->end = $input['end'];
        } else {
            if (!isset($presets[$period])) $period = $definition['defaultPeriod'] ?? "last-12";
            $ctx->start = $presets[$period]['start'];
            $ctx->end = $presets[$period]['end'];
        }
        $form['period'] = $period;
        $form['presets'] = $presets;
        $form['start'] = $ctx->start;
        $form['end'] = $ctx->end;
    }
    if (in_array("asAt", $definition['params'])) {
        $ctx->asAt = reportIsDate($input['asAt'] ?? null) ? $input['asAt'] : $ctx->today;
        $form['asAt'] = $ctx->asAt;
    }
    if (in_array("horizon", $definition['params'])) {
        $horizon = intval($input['horizon'] ?? 6);
        $ctx->params['horizon'] = ($horizon >= 1 and $horizon <= 24) ? $horizon : 6;
        $form['horizon'] = $ctx->params['horizon'];
    }
    if (in_array("inactive", $definition['params'])) {
        $inactive = intval($input['inactive'] ?? 6);
        $ctx->params['inactive'] = ($inactive >= 1 and $inactive <= 60) ? $inactive : 6;
        $form['inactive'] = $ctx->params['inactive'];
    }
    if (in_array("detail", $definition['params'])) {
        $ctx->params['detail'] = (isset($input['detail']) and $input['detail'] === "1");
        $form['detail'] = $ctx->params['detail'];
    }
    if (in_array("client", $definition['params'])) {
        $DBLIB->where("clients.instances_id", $ctx->instanceId);
        $DBLIB->where("clients.clients_deleted", 0);
        $DBLIB->orderBy("clients.clients_name", "ASC");
        $form['clients'] = $DBLIB->get("clients", null, ["clients.clients_id", "clients.clients_name", "clients.clients_address", "clients.clients_email", "clients.clients_archived"]);
        $ctx->params['client'] = null;
        foreach ($form['clients'] as $client) {
            if (isset($input['client']) and is_scalar($input['client']) and strval($client['clients_id']) === strval($input['client'])) $ctx->params['client'] = $client;
        }
        $form['client'] = $ctx->params['client'] ? $ctx->params['client']['clients_id'] : null;
    }
    return $form;
}

/** The period a report is for, in words: "1 Apr 2026 – 31 Mar 2027" */
function reportPeriodLabel(ReportContext $ctx, $definition, $dateFormat)
{
    $parts = [];
    if (in_array("period", $definition['params'])) $parts[] = date($dateFormat, strtotime($ctx->start)) . " – " . date($dateFormat, strtotime($ctx->end));
    if (in_array("asAt", $definition['params'])) $parts[] = "As at " . date($dateFormat, strtotime($ctx->asAt));
    if (in_array("horizon", $definition['params'])) $parts[] = "Next " . $ctx->params['horizon'] . " months";
    if (in_array("inactive", $definition['params'])) $parts[] = "Not booked for " . $ctx->params['inactive'] . " months";
    if (in_array("client", $definition['params']) and $ctx->params['client']) array_unshift($parts, $ctx->params['client']['clients_name']);
    if (count($parts) == 0) $parts[] = "As at " . date($dateFormat, strtotime($ctx->today));
    return implode(" · ", $parts);
}

/** Adds up a section's money and number columns that have "sum" set, for its totals row */
function reportSumColumns(ReportContext $ctx, $columns, $rows, $label = "Total")
{
    $totals = [];
    foreach ($columns as $index => $column) {
        if (empty($column['sum'])) continue;
        $total = $column['type'] == "money" ? $ctx->zero() : 0;
        foreach ($rows as $row) {
            if (!isset($row[$column['key']]) or $row[$column['key']] === null) continue;
            $total = $column['type'] == "money" ? $total->add($row[$column['key']]) : $total + $row[$column['key']];
        }
        $totals[$column['key']] = $total;
    }
    $totals[$columns[0]['key']] = $label;
    return $totals;
}

/** A column definition */
function reportColumn($key, $label, $type = "text", $sum = false)
{
    return ["key" => $key, "label" => $label, "type" => $type, "sum" => $sum];
}

/** A cell's value as plain text, for CSV */
function reportCellText(ReportContext $ctx, $value, $type)
{
    if ($value === null) return "";
    if ($value instanceof Money) return $ctx->decimal($value);
    if (is_array($value)) return (string) ($value['text'] ?? "");
    if ($type == "percent" or $type == "bar") return is_numeric($value) ? number_format($value * 100, 1, ".", "") . "%" : "";
    return (string) $value;
}

/** How a cell is shown - a row marked _percent shows fractions in its money columns as percentages */
function reportCellType($row, $column)
{
    if (!empty($row['_percent']) and $column['type'] == "money") return "percent";
    return $column['type'];
}

/** The sections of a report that are tables, for exporting */
function reportTableSections($report)
{
    return array_values(array_filter($report['sections'], function ($section) {
        return isset($section['columns']);
    }));
}

/** Sends a report as a CSV file - its headline figures, then each table */
function reportSendCsv(ReportContext $ctx, $report, $meta, $filename)
{
    header("Content-Type: text/csv; charset=utf-8");
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header("Cache-Control: no-store");
    $out = fopen("php://output", "w");
    fwrite($out, "\xEF\xBB\xBF"); //So Excel reads it as UTF-8
    $write = function ($row) use ($out) {
        //Cells starting with these would run as formulas in a spreadsheet
        fputcsv($out, array_map(function ($cell) {
            $cell = (string) $cell;
            return (strlen($cell) > 0 and in_array($cell[0], ["=", "+", "-", "@", "\t", "\r"]) and !is_numeric($cell)) ? "'" . $cell : $cell;
        }, $row));
    };
    $write([$meta['title']]);
    $write([$meta['business']]);
    $write([$meta['period']]);
    $write([]);
    if (count($report['kpis'] ?? []) > 0) {
        foreach ($report['kpis'] as $kpi) $write([$kpi['label'], reportCellText($ctx, $kpi['value'], $kpi['type'] ?? "text")]);
        $write([]);
    }
    foreach (reportTableSections($report) as $section) {
        $write([($section['heading'] ? $section['heading'] . " - " : "") . $section['title']]);
        $write(array_map(function ($c) { return $c['label']; }, $section['columns']));
        foreach (array_merge($section['rows'], $section['totals'] ? [$section['totals']] : []) as $row) {
            $write(array_map(function ($c) use ($ctx, $row) { return reportCellText($ctx, $row[$c['key']] ?? null, reportCellType($row, $c)); }, $section['columns']));
        }
        $write([]);
    }
    foreach (array_merge($report['warnings'] ?? [], $report['notes'] ?? []) as $note) $write([$note]);
    fclose($out);
}

/** Sends a report as an Excel workbook - a summary sheet, then a sheet for each table */
function reportSendXlsx(ReportContext $ctx, $report, $meta, $filename)
{
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $moneyFormat = '#,##0.00;[Red]-#,##0.00';
    $setCell = function ($sheet, $col, $row, $value, $type) use ($ctx, $moneyFormat) {
        $cell = $sheet->getCell([$col, $row]);
        if ($value === null) return;
        if ($value instanceof Money) {
            $cell->setValueExplicit(floatval($ctx->decimal($value)), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            $cell->getStyle()->getNumberFormat()->setFormatCode($moneyFormat);
        } elseif (($type == "percent" or $type == "bar") and is_numeric($value)) {
            $cell->setValueExplicit(floatval($value), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
            $cell->getStyle()->getNumberFormat()->setFormatCode('0.0%');
        } elseif ($type == "number" and is_numeric($value)) {
            $cell->setValueExplicit($value + 0, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
        } else {
            //Always text, so nothing typed into the app becomes a formula
            $cell->setValueExplicit(is_array($value) ? (string) ($value['text'] ?? "") : (string) $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
    };
    $summary = $spreadsheet->getActiveSheet();
    $summary->setTitle("Summary");
    $row = 1;
    foreach ([$meta['title'], $meta['business'], $meta['period'], "Generated " . $meta['generated']] as $line) {
        $setCell($summary, 1, $row++, $line, "text");
    }
    $summary->getStyle("A1")->getFont()->setBold(true)->setSize(14);
    $row++;
    foreach ($report['kpis'] ?? [] as $kpi) {
        $setCell($summary, 1, $row, $kpi['label'], "text");
        $setCell($summary, 2, $row, $kpi['value'], $kpi['type'] ?? "text");
        $row++;
    }
    $row++;
    foreach (array_merge($report['warnings'] ?? [], $report['notes'] ?? []) as $note) $setCell($summary, 1, $row++, $note, "text");
    $summary->getColumnDimension("A")->setWidth(40);
    $summary->getColumnDimension("B")->setWidth(20);

    $titles = ["Summary" => true];
    foreach (reportTableSections($report) as $section) {
        $title = trim(substr(preg_replace('/[\[\]\:\*\?\/\\\\]/', '', ($section['heading'] ? $section['heading'] . " " : "") . $section['title']), 0, 28));
        $base = $title !== "" ? $title : "Sheet";
        $title = $base;
        for ($i = 2; isset($titles[strtolower($title)]); $i++) $title = substr($base, 0, 26) . " " . $i;
        $titles[strtolower($title)] = true;
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);
        foreach ($section['columns'] as $index => $column) {
            $setCell($sheet, $index + 1, 1, $column['label'], "text");
            $sheet->getColumnDimensionByColumn($index + 1)->setAutoSize(true);
        }
        $sheet->getStyle([1, 1, count($section['columns']), 1])->getFont()->setBold(true);
        $r = 2;
        foreach ($section['rows'] as $line) {
            foreach ($section['columns'] as $index => $column) $setCell($sheet, $index + 1, $r, $line[$column['key']] ?? null, reportCellType($line, $column));
            if (in_array($line['_style'] ?? null, ["heading", "subtotal", "total"])) $sheet->getStyle([1, $r, count($section['columns']), $r])->getFont()->setBold(true);
            $r++;
        }
        if ($section['totals']) {
            foreach ($section['columns'] as $index => $column) $setCell($sheet, $index + 1, $r, $section['totals'][$column['key']] ?? null, $column['type']);
            $sheet->getStyle([1, $r, count($section['columns']), $r])->getFont()->setBold(true);
        }
        $sheet->freezePane("A2");
    }
    $spreadsheet->setActiveSheetIndex(0);
    header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header("Cache-Control: no-store");
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save("php://output");
}

/** Fills in the parts of a section a builder can leave out */
function reportSection($section)
{
    return array_merge(["id" => null, "title" => "", "note" => null, "totals" => null, "empty" => "Nothing to show for this period.", "chart" => null, "heading" => null, "rows" => []], $section);
}

/** Runs a report's builder and tidies up what it returns */
function reportBuild(ReportContext $ctx, $definition)
{
    $report = call_user_func($definition['builder'], $ctx);
    $report = array_merge(["kpis" => [], "sections" => [], "notes" => [], "warnings" => [], "uncalculated" => []], $report);
    $report['sections'] = array_map('reportSection', $report['sections']);
    if (count($report['uncalculated']) > 0) {
        array_unshift($report['warnings'], count($report['uncalculated']) . " project" . (count($report['uncalculated']) == 1 ? " hasn't" : "s haven't") . " had " . (count($report['uncalculated']) == 1 ? "its" : "their") . " finances worked out yet, so " . (count($report['uncalculated']) == 1 ? "isn't" : "aren't") . " counted. Work them out to include them.");
    }
    return $report;
}
