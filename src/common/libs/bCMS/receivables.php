<?php

use Money\Currency;
use Money\Money;

/**
 * Whether won work has been paid for. A project's payment falls due a number of days after the latest of its dates (the business's
 * payment terms, instances_config_paymentDueDays) unless it has its own due date (projects_paymentDueDate), and a
 * project can be marked as in collections or written off (projects_collectionStatus).
 */

/** The values projects_collectionStatus can be set to - NULL means the project is being paid as normal */
const PROJECT_COLLECTION_STATUSES = ["COLLECTIONS" => "In collections", "WRITTEN_OFF" => "Written off"];

/** Where a project's payment is up to, as worked out by projectCollectionState() */
const PROJECT_COLLECTION_STATES = [
    "PAID" => "Paid",
    "NOT_DUE" => "Not yet due",
    "PAST_DUE" => "Past due",
    "COLLECTIONS" => "In collections",
    "WRITTEN_OFF" => "Written off",
    "NOT_CALCULATED" => "Not calculated yet",
];

/** The project date columns a payment's due date can be worked from - the latest one a project has wins */
const PROJECT_DUE_DATE_COLUMNS = ["projects_dates_deliver_end", "projects_dates_use_end", "projects_dates_deliver_start", "projects_dates_use_start"];

/**
 * The latest of a project's dates (Y-m-d H:i:s), or null if it has none. Projects without assets only have their own
 * use dates, so the delivery dates alone aren't enough.
 */
function projectLatestDate($project)
{
    $latest = null;
    foreach (PROJECT_DUE_DATE_COLUMNS as $column) {
        if (empty($project[$column])) continue;
        if ($latest === null or strtotime($project[$column]) > strtotime($latest)) $latest = $project[$column];
    }
    return $latest;
}

/** SQL for the latest of a project's dates, for filtering - NULL if it has none */
function projectLatestDateSql($table = "projects")
{
    return "NULLIF(GREATEST(" . implode(", ", array_map(function ($column) use ($table) { return "COALESCE(" . $table . "." . $column . ", '1000-01-01')"; }, PROJECT_DUE_DATE_COLUMNS)) . "), '1000-01-01')";
}

/**
 * The date a project's payment is due (Y-m-d): its own due date if it has one, then the date locked in when its
 * invoice was issued, otherwise the given number of days after the latest date the project has. Null if it has none.
 */
function projectPaymentDueDate($project, $paymentDueDays)
{
    if (!empty($project['projects_paymentDueDate'])) return date("Y-m-d", strtotime($project['projects_paymentDueDate']));
    if (!empty($project['projects_invoiceDueDate'])) return date("Y-m-d", strtotime($project['projects_invoiceDueDate']));
    return projectComputedDueDate($project, $paymentDueDays);
}

/** The due date worked out from the project's dates and the payment terms, whatever has been set or locked in */
function projectComputedDueDate($project, $paymentDueDays)
{
    $latest = projectLatestDate($project);
    if ($latest === null) return null;
    return date("Y-m-d", strtotime($latest . " +" . intval($paymentDueDays) . " days"));
}

/**
 * Lock in the dates a project's invoice was issued and is due, so changes to the project's dates or the business's
 * payment terms don't move what a client was told. Does nothing if it's already locked - it has to be unlocked first.
 *
 * @param array $project The project's row
 * @param string|null $dueDate Y-m-d to lock in, or null for the date worked out from the project's dates
 * @return array|string ["issueDate", "dueDate"] as locked, or a message saying why it couldn't be
 */
function projectLockInvoiceDueDate($project, $dueDate = null)
{
    global $DBLIB, $AUTH, $bCMS;
    if ($project['projects_invoiceDueDate'] !== null) return ["issueDate" => $project['projects_invoiceIssueDate'], "dueDate" => $project['projects_invoiceDueDate']];
    $terms = intval($AUTH->data['instance']['instances_config_paymentDueDays']);
    if ($dueDate === null) {
        $dueDate = projectPaymentDueDate(array_merge($project, ["projects_invoiceDueDate" => null]), $terms);
        if ($dueDate === null) return "The project has no dates to work a due date from - choose one";
    }
    $lock = ["projects_invoiceIssueDate" => date("Y-m-d"), "projects_invoiceDueDate" => $dueDate, "projects_invoiceTermsDays" => $terms];
    $DBLIB->where("projects.projects_id", $project['projects_id']);
    if (!$DBLIB->update("projects", $lock)) return "The due date couldn't be locked in";
    $bCMS->auditLog("LOCK-INVOICE-DUE-DATE", "projects", json_encode($lock), $AUTH->data['users_userid'], null, $project['projects_id']);
    return ["issueDate" => $lock['projects_invoiceIssueDate'], "dueDate" => $dueDate];
}

/**
 * What a project has been billed, been paid and still owes, from its finance cache - the same figures the project
 * list and clients page show. Null if the project's finances haven't been calculated yet (it hasn't been opened).
 *
 * @return array|null ["total" => Money, "received" => Money, "outstanding" => Money]
 */
function projectBalance($projectsId, Currency $currency)
{
    global $DBLIB;
    $DBLIB->where("projects_id", $projectsId);
    $DBLIB->orderBy("projectsFinanceCache_timestamp", "DESC");
    $cache = $DBLIB->getOne("projectsFinanceCache", ["projectsFinanceCache_grandTotal", "projectsFinanceCache_paymentsReceived"]);
    if (!$cache) return null;
    $outstanding = new Money($cache['projectsFinanceCache_grandTotal'] ?? 0, $currency); //The cache's grand total already has the payments taken off
    $received = new Money($cache['projectsFinanceCache_paymentsReceived'] ?? 0, $currency);
    return ["total" => $outstanding->add($received), "received" => $received, "outstanding" => $outstanding];
}

/**
 * Where a project's payment is up to - one of the keys of PROJECT_COLLECTION_STATES. Anything owed on a project
 * that's in collections or written off is counted as such whatever its due date.
 *
 * @param Money|null $outstanding What's still owed, or null if it isn't known
 * @param string|null $dueDate Y-m-d, from projectPaymentDueDate()
 */
function projectCollectionState($project, $outstanding, $dueDate)
{
    if ($outstanding === null) return "NOT_CALCULATED";
    if (!$outstanding->isPositive()) return "PAID";
    if (($project['projects_collectionStatus'] ?? null) == "WRITTEN_OFF") return "WRITTEN_OFF";
    if (($project['projects_collectionStatus'] ?? null) == "COLLECTIONS") return "COLLECTIONS";
    if ($dueDate !== null and $dueDate < date("Y-m-d")) return "PAST_DUE";
    return "NOT_DUE";
}

/**
 * The share of a project's bill that's been paid, between 0 and 1 - for splitting what one asset brought in on it
 * into collected and uncollected in proportion. A project with nothing to pay counts as fully paid.
 */
function projectPaidShare($balance)
{
    if ($balance === null) return 0.0;
    if (!$balance['total']->isPositive()) return 1.0;
    return min(1.0, max(0.0, (float) $balance['received']->getAmount() / (float) $balance['total']->getAmount()));
}
