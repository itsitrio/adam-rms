<?php

use Money\Currency;
use Money\Money;

/**
 * Whether won work has been paid for. A project's payment falls due a number of days after it ends (the business's
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

/**
 * The date a project's payment is due (Y-m-d): its own due date if it has one, otherwise the given number of days
 * after its delivery ends. Null if it has neither.
 */
function projectPaymentDueDate($project, $paymentDueDays)
{
    if (!empty($project['projects_paymentDueDate'])) return date("Y-m-d", strtotime($project['projects_paymentDueDate']));
    if (empty($project['projects_dates_deliver_end'])) return null;
    return date("Y-m-d", strtotime($project['projects_dates_deliver_end'] . " +" . intval($paymentDueDays) . " days"));
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
