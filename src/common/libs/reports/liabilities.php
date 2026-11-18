<?php
/**
 * What the business owes - loans, equipment bought on finance or credit, and money lent by vendors or members of the
 * business - and the liabilities report.
 *
 * A liability starts at what was borrowed (liabilities_amount) on its start date. A DRAWDOWN borrows more, a CHARGE
 * adds interest or a fee to the balance, and a REPAYMENT pays it off. Part of a repayment can be interest
 * (liabilityTransactions_interest): that part is a cost to the business and doesn't reduce the balance. Interest and
 * fees - charges, and the interest part of repayments - are the business's finance costs in the profit & loss.
 */

const LIABILITY_TYPES = ["LOAN" => "Loan", "ASSET_FINANCE" => "Asset finance", "VENDOR_CREDIT" => "Bought on credit", "MEMBER_LOAN" => "Loan from a member", "OTHER" => "Other"];
const LIABILITY_TRANSACTION_TYPES = ["REPAYMENT" => "Repayment", "CHARGE" => "Interest or fee added", "DRAWDOWN" => "More borrowed"];
const LIABILITY_FREQUENCIES = ["WEEKLY" => "Weekly", "MONTHLY" => "Monthly", "QUARTERLY" => "Quarterly", "YEARLY" => "Yearly"];
const LIABILITY_FREQUENCY_STEPS = ["WEEKLY" => "+1 week", "MONTHLY" => "+1 month", "QUARTERLY" => "+3 months", "YEARLY" => "+1 year"];

/**
 * The business's liabilities, with their transactions (oldest first) and the assets bought with them. Keyed by
 * liabilities_id.
 */
function reportLiabilities(ReportContext $ctx, $id = null)
{
    global $DBLIB;
    $DBLIB->where("liabilities.instances_id", $ctx->instanceId);
    $DBLIB->where("liabilities.liabilities_deleted", 0);
    if ($id !== null) $DBLIB->where("liabilities.liabilities_id", intval($id));
    $DBLIB->join("users", "liabilities.users_userid=users.users_userid", "LEFT");
    $DBLIB->orderBy("liabilities.liabilities_startDate", "ASC");
    $rows = $DBLIB->get("liabilities", null, ["liabilities.*", "users.users_name1", "users.users_name2"]);
    $liabilities = [];
    foreach ($rows as $row) {
        $row['memberName'] = $row['users_userid'] ? trim($row['users_name1'] . " " . $row['users_name2']) : null;
        $row['lenderName'] = $row['memberName'] ?: ($row['liabilities_lender'] ?: "Not recorded");
        $row['typeName'] = LIABILITY_TYPES[$row['liabilities_type']] ?? $row['liabilities_type'];
        $row['amount'] = $ctx->money($row['liabilities_amount']);
        $row['repaymentAmount'] = $row['liabilities_repaymentAmount'] === null ? null : $ctx->money($row['liabilities_repaymentAmount']);
        $row['transactions'] = [];
        $row['assets'] = [];
        $liabilities[$row['liabilities_id']] = $row;
    }
    if (count($liabilities) == 0) return [];
    $DBLIB->where("liabilityTransactions.liabilities_id", array_keys($liabilities), "IN");
    $DBLIB->where("liabilityTransactions.liabilityTransactions_deleted", 0);
    $DBLIB->orderBy("liabilityTransactions.liabilityTransactions_date", "ASC");
    $DBLIB->orderBy("liabilityTransactions.liabilityTransactions_id", "ASC");
    foreach ($DBLIB->get("liabilityTransactions") as $transaction) {
        $transaction['amount'] = $ctx->money($transaction['liabilityTransactions_amount']);
        $transaction['interest'] = $ctx->money($transaction['liabilityTransactions_interest']);
        $liabilities[$transaction['liabilities_id']]['transactions'][] = $transaction;
    }
    $assets = [];
    foreach (reportAssets($ctx) as $asset) {
        if ($asset['liabilities_id'] !== null and isset($liabilities[$asset['liabilities_id']])) $liabilities[$asset['liabilities_id']]['assets'][] = $asset;
    }
    return $liabilities;
}

/**
 * Where a liability stood at the end of a date (Y-m-d)
 *
 * @return array Money values: borrowed (the amount plus anything more borrowed), repaid (what repayments took off the
 *               balance), costs (interest and fees), paid (cash paid in repayments) and balance (what's still owed);
 *               and started (whether it had started by then)
 */
function reportLiabilityPosition(ReportContext $ctx, $liability, $date)
{
    $started = $liability['liabilities_startDate'] <= $date;
    $p = ["borrowed" => $started ? $liability['amount'] : $ctx->zero(), "repaid" => $ctx->zero(), "costs" => $ctx->zero(), "paid" => $ctx->zero(), "started" => $started];
    foreach ($liability['transactions'] as $t) {
        if ($t['liabilityTransactions_date'] > $date) continue;
        if ($t['liabilityTransactions_type'] == "DRAWDOWN") $p['borrowed'] = $p['borrowed']->add($t['amount']);
        elseif ($t['liabilityTransactions_type'] == "CHARGE") $p['costs'] = $p['costs']->add($t['amount']);
        elseif ($t['liabilityTransactions_type'] == "REPAYMENT") {
            $p['paid'] = $p['paid']->add($t['amount']);
            $p['costs'] = $p['costs']->add($t['interest']);
            $p['repaid'] = $p['repaid']->add($t['amount']->subtract($t['interest']));
        }
    }
    $charges = $ctx->zero();
    foreach ($liability['transactions'] as $t) if ($t['liabilityTransactions_date'] <= $date and $t['liabilityTransactions_type'] == "CHARGE") $charges = $charges->add($t['amount']);
    $p['balance'] = $p['borrowed']->add($charges)->subtract($p['repaid']);
    return $p;
}

/** Interest and fees on a liability in a period - charges, and the interest part of repayments */
function reportLiabilityCosts(ReportContext $ctx, $liability, $start, $end)
{
    $costs = $ctx->zero();
    foreach ($liability['transactions'] as $t) {
        if ($t['liabilityTransactions_date'] < $start or $t['liabilityTransactions_date'] > $end) continue;
        if ($t['liabilityTransactions_type'] == "CHARGE") $costs = $costs->add($t['amount']);
        elseif ($t['liabilityTransactions_type'] == "REPAYMENT") $costs = $costs->add($t['interest']);
    }
    return $costs;
}

/**
 * The repayments a liability's schedule says are due after a date (Y-m-d) and up to another, stopping once they'd
 * have paid off the balance or the liability's end date has passed. Empty if it has no regular repayment.
 *
 * @return array [["date" => Y-m-d, "amount" => Money]]
 */
function reportLiabilitySchedule(ReportContext $ctx, $liability, $balance, $after, $until)
{
    if ($liability['repaymentAmount'] === null or !$liability['repaymentAmount']->isPositive() or !$liability['liabilities_firstRepaymentDate'] or !$balance->isPositive()) return [];
    $step = LIABILITY_FREQUENCY_STEPS[$liability['liabilities_repaymentFrequency']] ?? "+1 month";
    $first = $liability['liabilities_firstRepaymentDate'];
    $due = [];
    $left = $balance;
    for ($i = 0; $i < 1000; $i++) {
        //Stepping from the first date each time keeps the day of the month (31 Jan, 28 Feb, 31 Mar)
        $date = $i == 0 ? $first : reportStepDate($first, $step, $i);
        if ($date > $until or ($liability['liabilities_endDate'] and $date > $liability['liabilities_endDate'])) break;
        if ($date <= $after) continue;
        $amount = $left->lessThan($liability['repaymentAmount']) ? $left : $liability['repaymentAmount'];
        $due[] = ["date" => $date, "amount" => $amount];
        $left = $left->subtract($amount);
        if (!$left->isPositive()) break;
    }
    return $due;
}

/** A date stepped on a number of times, without drifting off the end of short months */
function reportStepDate($start, $step, $times)
{
    if ($step == "+1 week") return date("Y-m-d", strtotime($start . " +" . ($times * 7) . " days"));
    $months = ["+1 month" => 1, "+3 months" => 3, "+1 year" => 12][$step] * $times;
    $first = date("Y-m-01", strtotime(substr($start, 0, 7) . "-01 +" . $months . " months"));
    $day = min(intval(substr($start, 8, 2)), intval(date("t", strtotime($first))));
    return substr($first, 0, 8) . sprintf("%02d", $day);
}

/** Interest and fees on all the business's liabilities in a period, for the profit & loss */
function reportFinanceCosts(ReportContext $ctx, $start, $end)
{
    $costs = $ctx->zero();
    foreach (reportLiabilities($ctx) as $liability) $costs = $costs->add(reportLiabilityCosts($ctx, $liability, $start, $end));
    return $costs;
}

/** A link cell for a liability */
function reportLiabilityLink($liability)
{
    global $CONFIG;
    return ["text" => $liability['liabilities_name'], "href" => $CONFIG['ROOTURL'] . "/reports/liability.php?id=" . $liability['liabilities_id']];
}

function reportLiabilitiesReport(ReportContext $ctx)
{
    $date = $ctx->asAt;
    $yearAgo = date("Y-m-d", strtotime($date . " -1 year +1 day"));
    $yearAhead = date("Y-m-d", strtotime($date . " +1 year"));
    $rows = [];
    $types = [];
    $months = [];
    foreach (reportMonths(date("Y-m-d", strtotime($date . " +1 day")), $yearAhead) as $month) $months[$month] = ["month" => date("M Y", strtotime($month . "-01")), "count" => 0, "amount" => $ctx->zero()];
    $owed = $ctx->zero();
    $costsYear = $ctx->zero();
    $dueYear = $ctx->zero();
    foreach (reportLiabilities($ctx) as $liability) {
        $p = reportLiabilityPosition($ctx, $liability, $date);
        if (!$p['started']) continue;
        $assetsValue = null;
        foreach ($liability['assets'] as $asset) {
            $depreciation = reportDepreciation($ctx, $asset, $date, $date);
            if ($depreciation === null) continue;
            $assetsValue = ($assetsValue ?? $ctx->zero())->add($depreciation['nbv']);
        }
        $schedule = reportLiabilitySchedule($ctx, $liability, $p['balance'], $date, $yearAhead);
        foreach ($schedule as $payment) {
            $month = substr($payment['date'], 0, 7);
            if (!isset($months[$month])) continue;
            $months[$month]['count']++;
            $months[$month]['amount'] = $months[$month]['amount']->add($payment['amount']);
            $dueYear = $dueYear->add($payment['amount']);
        }
        $costs = reportLiabilityCosts($ctx, $liability, $yearAgo, $date);
        $costsYear = $costsYear->add($costs);
        $owed = $owed->add($p['balance']);
        $types[$liability['typeName']] = $types[$liability['typeName']] ?? ["type" => $liability['typeName'], "count" => 0, "balance" => $ctx->zero()];
        $types[$liability['typeName']]['count']++;
        $types[$liability['typeName']]['balance'] = $types[$liability['typeName']]['balance']->add($p['balance']);
        $rows[] = ["liability" => reportLiabilityLink($liability), "type" => $liability['typeName'], "lender" => $liability['lenderName'], "start" => $liability['liabilities_startDate'],
            "rate" => $liability['liabilities_interestRate'] === null ? null : floatval($liability['liabilities_interestRate']) / 100,
            "borrowed" => $p['borrowed'], "repaid" => $p['repaid'], "costs" => $p['costs'], "balance" => $p['balance'],
            "next" => $schedule[0]['date'] ?? null, "assets" => count($liability['assets']), "assetsValue" => $assetsValue, "_style" => $p['balance']->isZero() ? "muted" : null];
    }
    usort($rows, function ($a, $b) { return $b['balance']->compare($a['balance']); });
    $types = array_values($types);
    foreach ($types as $key => $type) $types[$key]['share'] = ReportContext::ratio($type['balance'], $owed);
    $monthRows = array_values($months);
    $columns = [reportColumn("liability", "Liability", "link"), reportColumn("type", "Type"), reportColumn("lender", "Owed to"), reportColumn("start", "Started", "date"), reportColumn("rate", "Interest rate", "percent"),
        reportColumn("borrowed", "Borrowed", "money", true), reportColumn("repaid", "Paid off", "money", true), reportColumn("costs", "Interest & fees", "money", true), reportColumn("balance", "Still owed", "money", true),
        reportColumn("next", "Next repayment", "date"), reportColumn("assets", "Assets bought", "number", true), reportColumn("assetsValue", "Their book value", "money", true)];
    $typeColumns = [reportColumn("type", "Type"), reportColumn("count", "Liabilities", "number", true), reportColumn("balance", "Still owed", "money", true), reportColumn("share", "Share", "bar")];
    $monthColumns = [reportColumn("month", "Month"), reportColumn("count", "Repayments", "number", true), reportColumn("amount", "Due", "money", true)];
    return [
        "kpis" => [
            ["label" => "Still owed", "value" => $owed, "type" => "money", "note" => count($rows) . " liabilit" . (count($rows) == 1 ? "y" : "ies")],
            ["label" => "Due in the next 12 months", "value" => $dueYear, "type" => "money", "note" => "on the repayment schedules"],
            ["label" => "Interest & fees, last 12 months", "value" => $costsYear, "type" => "money"],
        ],
        "sections" => [
            ["id" => "liabilities", "title" => "What the business owes", "columns" => $columns, "rows" => $rows, "totals" => reportSumColumns($ctx, $columns, $rows), "empty" => "No loans or finance have been recorded."],
            ["id" => "types", "title" => "By type", "columns" => $typeColumns, "rows" => $types, "totals" => reportSumColumns($ctx, $typeColumns, $types), "empty" => "No loans or finance have been recorded."],
            ["id" => "schedule", "title" => "Repayments due in the next 12 months", "columns" => $monthColumns, "rows" => $monthRows, "totals" => reportSumColumns($ctx, $monthColumns, $monthRows),
                "chart" => ["type" => "bars", "labels" => array_map(function ($r) { return date("M y", strtotime($r['month'])); }, $monthRows), "series" => [["name" => "Due", "values" => array_column($monthRows, "amount")]]]],
        ],
        "notes" => [
            "Still owed is what was borrowed, plus interest and fees added to the balance, less the part of each repayment that wasn't interest.",
            "Repayments due come from each liability's repayment amount and how often it's paid - only those with a regular repayment are included.",
        ],
    ];
}

/** The business's members, for choosing one who lent the business money */
function reportBusinessMembers(ReportContext $ctx)
{
    global $DBLIB;
    $DBLIB->join("instancePositions", "userInstances.instancePositions_id=instancePositions.instancePositions_id", "LEFT");
    $DBLIB->join("users", "userInstances.users_userid=users.users_userid", "LEFT");
    $DBLIB->where("instancePositions.instances_id", $ctx->instanceId);
    $DBLIB->where("userInstances.userInstances_deleted", 0);
    $DBLIB->where("users.users_deleted", 0);
    $DBLIB->orderBy("users.users_name1", "ASC");
    $DBLIB->groupBy("users.users_userid, users.users_name1, users.users_name2");
    return $DBLIB->get("userInstances", null, ["users.users_userid", "users.users_name1", "users.users_name2"]);
}

/**
 * A liability ready for its page: where it stands today, each transaction with the balance after it, and the
 * repayments its schedule says are still to come
 */
function reportLiabilityDetail(ReportContext $ctx, $liability)
{
    $rows = [["date" => $liability['liabilities_startDate'], "typeName" => "Borrowed", "transaction" => null, "change" => $liability['amount'], "interest" => null]];
    foreach ($liability['transactions'] as $t) {
        if ($t['liabilityTransactions_type'] == "REPAYMENT") $change = $t['amount']->subtract($t['interest'])->negative();
        else $change = $t['amount'];
        $rows[] = ["date" => $t['liabilityTransactions_date'], "typeName" => LIABILITY_TRANSACTION_TYPES[$t['liabilityTransactions_type']] ?? $t['liabilityTransactions_type'], "transaction" => $t, "change" => $change,
            "interest" => $t['liabilityTransactions_type'] == "REPAYMENT" ? $t['interest'] : null];
    }
    //In date order with the balance after each - the amount borrowed first on its day
    usort($rows, function ($a, $b) { return [$a['date'], $a['transaction'] !== null] <=> [$b['date'], $b['transaction'] !== null]; });
    $running = $ctx->zero();
    foreach ($rows as $key => $row) {
        $running = $running->add($row['change']);
        $rows[$key]['balance'] = $running;
    }
    $liability['ledger'] = $rows;
    $liability['position'] = reportLiabilityPosition($ctx, $liability, "9999-12-31");
    $liability['schedule'] = reportLiabilitySchedule($ctx, $liability, $liability['position']['balance'], $ctx->today, "9999-12-31");
    $liability['assetsValue'] = null;
    foreach ($liability['assets'] as $key => $asset) {
        $depreciation = reportDepreciation($ctx, $asset, $ctx->today, $ctx->today);
        $liability['assets'][$key]['nbv'] = $depreciation ? $depreciation['nbv'] : null;
        if ($depreciation) $liability['assetsValue'] = ($liability['assetsValue'] ?? $ctx->zero())->add($depreciation['nbv']);
    }
    return $liability;
}
