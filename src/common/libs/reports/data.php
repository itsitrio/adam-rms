<?php
/**
 * The figures the reports are built from. Everything here is scoped to one business (the context's instance) and
 * leaves out soft-deleted records.
 *
 * How the money is counted, which every report shares:
 * - A project's revenue is what it bills (its equipment after discounts, plus its sales, additional hire and staff
 *   lines), taken from its finance cache - the figures the project's Finance tab works out. A project whose finances
 *   have never been worked out (it has never been opened) has no cache; the reports list these so they can be.
 * - Only projects whose status is at the Won stage of the sales pipeline, and whose project type has finance turned
 *   on, are revenue. Revenue belongs to the date the project's delivery ends - when the work has been done.
 * - Costs are what the business pays: the cost recorded against each sales, additional hire and staff line, repairs,
 *   depreciation of assets that have a purchase cost, and overheads (expenses).
 */

use Money\Currency;
use Money\Money;
use Money\Currencies\ISOCurrencies;
use Money\Formatter\DecimalMoneyFormatter;

require_once __DIR__ . '/../bCMS/projectFinance.php';

const REPORT_PAYMENT_TYPES = [1 => "received", 2 => "sales", 3 => "subHire", 4 => "staff"];

/**
 * The business and dates a report is for, and some helpers for working with its money
 */
class ReportContext
{
    public $instance;
    public $instanceId;
    public $currency;
    /** Y-m-d, both inclusive */
    public $start;
    public $end;
    /** Y-m-d - the date balances and valuations are worked out at */
    public $asAt;
    public $params = [];
    public $today;

    public function __construct($instance)
    {
        $this->instance = $instance;
        $this->instanceId = intval($instance['instances_id']);
        $this->currency = new Currency($instance['instances_config_currency']);
        $this->today = date("Y-m-d");
        $this->asAt = $this->today;
    }

    public function money($amount = 0)
    {
        if ($amount instanceof Money) return $amount;
        return new Money((string) intval($amount ?? 0), $this->currency);
    }

    public function zero()
    {
        return new Money(0, $this->currency);
    }

    /** The money as a plain decimal string, as typed into a form: 1234.50 */
    public function decimal(Money $money)
    {
        return (new DecimalMoneyFormatter(new ISOCurrencies()))->format($money);
    }

    /** a as a fraction of b, or null if b is zero */
    public static function ratio($a, $b)
    {
        $a = $a instanceof Money ? floatval($a->getAmount()) : floatval($a);
        $b = $b instanceof Money ? floatval($b->getAmount()) : floatval($b);
        if ($b == 0.0) return null;
        return $a / $b;
    }

    /** Whether a datetime/date falls in the period */
    public function inPeriod($date, $start = null, $end = null)
    {
        if (!$date) return false;
        $day = substr($date, 0, 10);
        return $day >= ($start ?? $this->start) and $day <= ($end ?? $this->end);
    }
}

/** Days from one Y-m-d to another, counting both - 1 Jan to 1 Jan is 1 day. 0 if they're the wrong way round */
function reportDaysInclusive($start, $end)
{
    if ($end < $start) return 0;
    return intval(round((strtotime($end . " 00:00:00 UTC") - strtotime($start . " 00:00:00 UTC")) / 86400)) + 1;
}

/** The days two inclusive Y-m-d ranges share */
function reportOverlapDays($startA, $endA, $startB, $endB)
{
    return reportDaysInclusive(max($startA, $startB), min($endA, $endB));
}

/** The months (Y-m) a period covers, in order */
function reportMonths($start, $end)
{
    $months = [];
    $month = substr($start, 0, 7);
    while ($month <= substr($end, 0, 7)) {
        $months[] = $month;
        $month = date("Y-m", strtotime($month . "-01 +1 month"));
    }
    return $months;
}

/**
 * Every project in the business, with its pipeline stage, client and manager, and its money from the finance cache.
 * Keyed by projects_id.
 *
 * Each project has a 'money' array (Money values) - equipmentGross (before discounts), discounts, equipment
 * (after discounts), sales, subHire, staff, billed (the total), received and outstanding - or null if its finances
 * haven't been worked out yet, and 'isRevenue' if it is won work in a project type with finances.
 */
function reportProjects(ReportContext $ctx)
{
    global $DBLIB;
    static $cache = [];
    if (isset($cache[$ctx->instanceId])) return $cache[$ctx->instanceId];

    $DBLIB->where("projects.instances_id", $ctx->instanceId);
    $DBLIB->where("projects.projects_deleted", 0);
    $DBLIB->join("projectsStatuses", "projects.projectsStatuses_id=projectsStatuses.projectsStatuses_id", "LEFT");
    $DBLIB->join("projectsTypes", "projects.projectsTypes_id=projectsTypes.projectsTypes_id", "LEFT");
    $DBLIB->join("clients", "projects.clients_id=clients.clients_id", "LEFT");
    $DBLIB->join("users", "projects.projects_manager=users.users_userid", "LEFT");
    $DBLIB->orderBy("projects.projects_dates_deliver_end", "ASC");
    $rows = $DBLIB->get("projects", null, [
        "projects.projects_id", "projects.projects_name", "projects.projects_created", "projects.projects_archived", "projects.projects_parent_project_id",
        "projects.projects_dates_deliver_start", "projects.projects_dates_deliver_end", "projects.projects_paymentDueDate", "projects.projects_collectionStatus",
        "projects.clients_id", "clients.clients_name", "projects.projects_manager", "users.users_name1", "users.users_name2",
        "projects.projectsTypes_id", "projectsTypes.projectsTypes_name", "projectsTypes.projectsTypes_config_finance",
        "projectsStatuses.projectsStatuses_name", "projectsStatuses.projectsStatuses_pipelineStage",
    ]);

    //The most recent finance cache of each project, which is the one the rest of the app reads
    $caches = [];
    $cacheRows = $DBLIB->rawQuery("SELECT c.* FROM projectsFinanceCache c
        JOIN (SELECT projectsFinanceCache.projects_id, MAX(projectsFinanceCache.projectsFinanceCache_id) AS latest FROM projectsFinanceCache
              JOIN projects ON projects.projects_id = projectsFinanceCache.projects_id WHERE projects.instances_id = ? GROUP BY projectsFinanceCache.projects_id) l
        ON l.latest = c.projectsFinanceCache_id", [$ctx->instanceId]);
    foreach ($cacheRows ?: [] as $row) $caches[$row['projects_id']] = $row;

    $projects = [];
    foreach ($rows as $project) {
        $project['stage'] = $project['projectsStatuses_pipelineStage'] ?: "LEAD";
        $project['managerName'] = trim(($project['users_name1'] ?? "") . " " . ($project['users_name2'] ?? ""));
        $project['hasFinance'] = $project['projectsTypes_config_finance'] == 1;
        $project['isRevenue'] = ($project['stage'] == "WON" && $project['hasFinance']);
        $project['end'] = $project['projects_dates_deliver_end'] ? substr($project['projects_dates_deliver_end'], 0, 10) : null;
        $project['start'] = $project['projects_dates_deliver_start'] ? substr($project['projects_dates_deliver_start'], 0, 10) : null;
        $project['money'] = null;
        if (isset($caches[$project['projects_id']])) {
            $c = $caches[$project['projects_id']];
            $m = [
                "equipmentGross" => $ctx->money($c['projectsFinanceCache_equipmentSubTotal']),
                "discounts" => $ctx->money($c['projectsFinanceCache_equiptmentDiscounts']),
                "sales" => $ctx->money($c['projectsFinanceCache_salesTotal']),
                "subHire" => $ctx->money($c['projectsFinanceCache_externalHiresTotal']),
                "staff" => $ctx->money($c['projectsFinanceCache_staffTotal']),
                "received" => $ctx->money($c['projectsFinanceCache_paymentsReceived']),
                "outstanding" => $ctx->money($c['projectsFinanceCache_grandTotal']), //Already has the payments taken off
            ];
            $m['equipment'] = $m['equipmentGross']->subtract($m['discounts']);
            $m['billed'] = $m['outstanding']->add($m['received']);
            $project['money'] = $m;
        }
        $project['dueDate'] = projectPaymentDueDate($project, $ctx->instance['instances_config_paymentDueDays']);
        $projects[$project['projects_id']] = $project;
    }
    return $cache[$ctx->instanceId] = $projects;
}

/** Won projects (in a project type with finances) whose delivery ends in the period */
function reportRevenueProjects(ReportContext $ctx, $start = null, $end = null)
{
    return array_filter(reportProjects($ctx), function ($p) use ($ctx, $start, $end) {
        return $p['isRevenue'] and $ctx->inPeriod($p['end'], $start, $end);
    });
}

/** Projects in a list whose finances haven't been worked out, for the report to offer to work them out */
function reportUncalculated($projects)
{
    return array_values(array_map(function ($p) {
        return ["id" => $p['projects_id'], "name" => $p['projects_name']];
    }, array_filter($projects, function ($p) {
        return $p['money'] === null;
    })));
}

/** A link cell for a project */
function reportProjectLink($project)
{
    global $CONFIG;
    return ["text" => $project['projects_name'], "href" => $CONFIG['ROOTURL'] . "/project/?id=" . $project['projects_id']];
}

/** A client's name, for grouping - projects without one are grouped together */
function reportClientName($project)
{
    return $project['clients_name'] ?: "No client";
}

/**
 * Lines of the payments table for the business's projects - each with its total (amount x quantity) and, for sales,
 * additional hire and staff lines, its cost (cost x quantity, or null if no cost has been recorded).
 *
 * @param array $types payments_type values
 * @param array|null $projectIds only these projects, or null for all
 * @param string|null $start only payments dated in this period (Y-m-d, inclusive) - for payments received
 */
function reportPayments(ReportContext $ctx, array $types, $projectIds = null, $start = null, $end = null)
{
    global $DBLIB;
    if ($projectIds !== null and count($projectIds) == 0) return [];
    $DBLIB->join("projects", "payments.projects_id=projects.projects_id", "LEFT");
    $DBLIB->join("clients", "projects.clients_id=clients.clients_id", "LEFT");
    $DBLIB->where("projects.instances_id", $ctx->instanceId);
    $DBLIB->where("payments.payments_deleted", 0);
    $DBLIB->where("payments.payments_type", array_map('intval', $types), "IN");
    if ($projectIds !== null) $DBLIB->where("payments.projects_id", array_map('intval', array_values($projectIds)), "IN");
    if ($start !== null) $DBLIB->where("payments.payments_date", $start . " 00:00:00", ">=");
    if ($end !== null) $DBLIB->where("payments.payments_date", $end . " 23:59:59", "<=");
    $DBLIB->orderBy("payments.payments_date", "ASC");
    $rows = $DBLIB->get("payments", null, ["payments.*", "projects.projects_name", "projects.projects_deleted", "projects.clients_id", "clients.clients_name"]);
    foreach ($rows as $key => $row) {
        $quantity = intval($row['payments_quantity']) > 0 ? intval($row['payments_quantity']) : 1;
        $rows[$key]['quantityCounted'] = $quantity;
        $rows[$key]['total'] = $ctx->money($row['payments_amount'])->multiply($quantity);
        $rows[$key]['costTotal'] = $row['payments_cost'] === null ? null : $ctx->money($row['payments_cost'])->multiply($quantity);
        $rows[$key]['date'] = substr($row['payments_date'], 0, 10);
    }
    return $rows;
}

/**
 * Prices an asset assignment the way projects/data.php does (including its loose check on the custom price, which
 * is 0 when there isn't one). Returns the price it's charged at and its list price - its rates for the project's
 * duration, before any custom price or discount.
 */
function reportAssignmentPrice(ReportContext $ctx, $assignment)
{
    $quantity = (intval($assignment['assetsAssignments_quantity']) > 0 ? intval($assignment['assetsAssignments_quantity']) : 1);
    if ($assignment['projects_dates_finances_days'] !== null and $assignment['projects_dates_finances_weeks'] !== null) $duration = ["days" => $assignment['projects_dates_finances_days'], "weeks" => $assignment['projects_dates_finances_weeks']];
    elseif ($assignment['projects_dates_deliver_start'] and $assignment['projects_dates_deliver_end']) $duration = (new projectFinance())->durationMathsByDates($assignment['projects_dates_deliver_start'], $assignment['projects_dates_deliver_end']);
    else $duration = ["days" => 0, "weeks" => 0];
    $list = $ctx->money($assignment['assets_dayRate'] !== null ? $assignment['assets_dayRate'] : $assignment['assetTypes_dayRate'])->multiply($duration['days']);
    $list = $list->add($ctx->money($assignment['assets_weekRate'] !== null ? $assignment['assets_weekRate'] : $assignment['assetTypes_weekRate'])->multiply($duration['weeks']));
    $list = $list->multiply($quantity);

    if ($assignment['assetsAssignments_excludedFromTotals'] == 1) return ["price" => $ctx->zero(), "list" => $ctx->zero(), "excluded" => true];
    if ($assignment['assetsAssignments_customPrice'] != null) $price = $ctx->money($assignment['assetsAssignments_customPrice'])->multiply($quantity);
    else $price = $list;
    if ($assignment['assetsAssignments_discount'] > 0) $price = $price->multiply(reportFactor(1 - ($assignment['assetsAssignments_discount'] / 100)));
    return ["price" => $price, "list" => $list, "excluded" => false];
}

/**
 * Every asset assignment on the business's projects, priced, with its asset, asset type and project.
 */
function reportAssignments(ReportContext $ctx)
{
    global $DBLIB;
    static $cache = [];
    if (isset($cache[$ctx->instanceId])) return $cache[$ctx->instanceId];
    $DBLIB->join("assets", "assetsAssignments.assets_id=assets.assets_id", "LEFT");
    $DBLIB->join("assetTypes", "assets.assetTypes_id=assetTypes.assetTypes_id", "LEFT");
    $DBLIB->join("projects", "assetsAssignments.projects_id=projects.projects_id", "LEFT");
    $DBLIB->where("projects.instances_id", $ctx->instanceId);
    $DBLIB->where("projects.projects_deleted", 0);
    $DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
    $rows = $DBLIB->get("assetsAssignments", null, [
        "assetsAssignments.assetsAssignments_id", "assetsAssignments.assets_id", "assetsAssignments.projects_id", "assetsAssignments.assetsAssignments_quantity",
        "assetsAssignments.assetsAssignments_customPrice", "assetsAssignments.assetsAssignments_discount", "assetsAssignments.assetsAssignments_excludedFromTotals",
        "assets.assets_tag", "assets.assets_dayRate", "assets.assets_weekRate", "assets.assetTypes_id", "assets.instances_id AS assets_instances_id",
        "assetTypes.assetTypes_name", "assetTypes.assetTypes_dayRate", "assetTypes.assetTypes_weekRate",
        "projects.projects_dates_deliver_start", "projects.projects_dates_deliver_end", "projects.projects_dates_finances_days", "projects.projects_dates_finances_weeks",
    ]);
    foreach ($rows as $key => $row) {
        $rows[$key] = array_merge($row, reportAssignmentPrice($ctx, $row));
        $rows[$key]['quantityCounted'] = intval($row['assetsAssignments_quantity']) > 0 ? intval($row['assetsAssignments_quantity']) : 1;
    }
    return $cache[$ctx->instanceId] = $rows;
}

/**
 * The business's assets, with their type, category and storage location, their value and (if they have a purchase
 * cost) their depreciation. Archived assets are included - each has 'endDate' (Y-m-d) if it has one.
 */
function reportAssets(ReportContext $ctx)
{
    global $DBLIB;
    static $cache = [];
    if (isset($cache[$ctx->instanceId])) return $cache[$ctx->instanceId];
    $DBLIB->join("assetTypes", "assets.assetTypes_id=assetTypes.assetTypes_id", "LEFT");
    $DBLIB->join("assetCategories", "assetTypes.assetCategories_id=assetCategories.assetCategories_id", "LEFT");
    $DBLIB->join("manufacturers", "assetTypes.manufacturers_id=manufacturers.manufacturers_id", "LEFT");
    $DBLIB->join("locations", "assets.assets_storageLocation=locations.locations_id", "LEFT");
    $DBLIB->where("assets.instances_id", $ctx->instanceId);
    $DBLIB->where("assets.assets_deleted", 0);
    $DBLIB->orderBy("assetCategories.assetCategories_name", "ASC");
    $DBLIB->orderBy("assetTypes.assetTypes_name", "ASC");
    $DBLIB->orderBy("assets.assets_tag", "ASC");
    $rows = $DBLIB->get("assets", null, [
        "assets.assets_id", "assets.assets_tag", "assets.assetTypes_id", "assets.assets_inserted", "assets.assets_endDate", "assets.assets_archived",
        "assets.assets_value", "assets.assets_mass", "assets.assets_dayRate", "assets.assets_weekRate", "assets.assets_unserialized", "assets.assets_quantity",
        "assets.assets_purchaseCost", "assets.assets_purchaseDate", "assets.assets_depreciationMonths", "assets.assets_storageLocation",
        "assetTypes.assetTypes_name", "assetTypes.assetTypes_value", "assetTypes.assetTypes_mass", "assetTypes.assetTypes_dayRate", "assetTypes.assetTypes_weekRate",
        "assetCategories.assetCategories_name", "manufacturers.manufacturers_name", "locations.locations_name",
    ]);
    $defaultMonths = max(1, intval($ctx->instance['instances_config_depreciationMonths'] ?? 60));
    foreach ($rows as $key => $asset) {
        $quantity = $asset['assets_unserialized'] == 1 ? max(1, intval($asset['assets_quantity'])) : 1;
        $asset['quantityHeld'] = $quantity;
        $asset['value'] = $ctx->money($asset['assets_value'] !== null ? $asset['assets_value'] : $asset['assetTypes_value'])->multiply($quantity);
        $asset['mass'] = floatval($asset['assets_mass'] !== null ? $asset['assets_mass'] : $asset['assetTypes_mass']) * $quantity;
        $asset['added'] = substr($asset['assets_inserted'], 0, 10);
        $asset['endDate'] = $asset['assets_endDate'] ? substr($asset['assets_endDate'], 0, 10) : null;
        $asset['purchaseCost'] = $asset['assets_purchaseCost'] === null ? null : $ctx->money($asset['assets_purchaseCost']);
        $asset['purchaseDate'] = $asset['assets_purchaseDate'] ?: null;
        $asset['lifeMonths'] = $asset['assets_depreciationMonths'] ? intval($asset['assets_depreciationMonths']) : $defaultMonths;
        $asset['categoryName'] = $asset['assetCategories_name'] ?: "Uncategorised";
        $rows[$key] = $asset;
    }
    return $cache[$ctx->instanceId] = $rows;
}

/** Whether the business held an asset on a date (Y-m-d) - it had been added and hadn't been archived */
function reportAssetHeldOn($asset, $date)
{
    $from = $asset['purchaseDate'] && $asset['purchaseDate'] < $asset['added'] ? $asset['purchaseDate'] : $asset['added'];
    if ($from > $date) return false;
    return $asset['endDate'] === null or $asset['endDate'] > $date;
}

/**
 * Straight line depreciation of an asset with a purchase cost: its cost spread evenly over its life from the day it
 * was bought, stopping when it's archived. Null if the asset has no purchase cost or date.
 *
 * @return array|null ["charge" => depreciation in the period, "nbv" => net book value at the end of the period,
 *                     "disposal" => book value written off because it was archived in the period]
 */
function reportDepreciation(ReportContext $ctx, $asset, $start, $end)
{
    if ($asset['purchaseCost'] === null or !$asset['purchaseDate']) return null;
    $cost = $asset['purchaseCost'];
    $bought = $asset['purchaseDate'];
    $lifeEnd = date("Y-m-d", strtotime($bought . " +" . $asset['lifeMonths'] . " months -1 day"));
    $lifeDays = max(1, reportDaysInclusive($bought, $lifeEnd));
    //Depreciation stops the day before it's archived - what's left is written off then
    $stop = $asset['endDate'] ? min($lifeEnd, date("Y-m-d", strtotime($asset['endDate'] . " -1 day"))) : $lifeEnd;
    $depreciatedBy = function ($date) use ($bought, $stop, $lifeDays, $cost) {
        $days = reportDaysInclusive($bought, min($date, $stop));
        if ($days <= 0) return $cost->multiply(0);
        return $cost->multiply(reportFactor($days / $lifeDays));
    };
    $before = $depreciatedBy(date("Y-m-d", strtotime($start . " -1 day")));
    $atEnd = $depreciatedBy($end);
    $disposal = $cost->multiply(0);
    $nbv = $cost->subtract($atEnd);
    if ($asset['endDate'] and $asset['endDate'] <= $end) {
        if ($asset['endDate'] >= $start) $disposal = $nbv;
        $nbv = $cost->multiply(0);
    }
    if ($bought > $end) $nbv = $cost->multiply(0); //Not bought yet
    return ["charge" => $atEnd->subtract($before), "nbv" => $nbv, "disposal" => $disposal];
}

/** The business's overheads, dated in the period */
function reportExpenses(ReportContext $ctx, $start, $end)
{
    global $DBLIB;
    $DBLIB->where("expenses.instances_id", $ctx->instanceId);
    $DBLIB->where("expenses.expenses_deleted", 0);
    $DBLIB->where("expenses.expenses_date", $start, ">=");
    $DBLIB->where("expenses.expenses_date", $end, "<=");
    $DBLIB->orderBy("expenses.expenses_date", "ASC");
    $rows = $DBLIB->get("expenses", null, ["expenses.*"]);
    foreach ($rows as $key => $row) $rows[$key]['amount'] = $ctx->money($row['expenses_amount']);
    return $rows;
}

/** The business's maintenance jobs, with the assets on each and its status */
function reportMaintenanceJobs(ReportContext $ctx)
{
    global $DBLIB;
    $DBLIB->where("maintenanceJobs.instances_id", $ctx->instanceId);
    $DBLIB->where("maintenanceJobs.maintenanceJobs_deleted", 0);
    $DBLIB->join("maintenanceJobsStatuses", "maintenanceJobs.maintenanceJobsStatuses_id=maintenanceJobsStatuses.maintenanceJobsStatuses_id", "LEFT");
    $DBLIB->orderBy("maintenanceJobs.maintenanceJobs_timestamp_added", "ASC");
    $rows = $DBLIB->get("maintenanceJobs", null, ["maintenanceJobs.maintenanceJobs_id", "maintenanceJobs.maintenanceJobs_title", "maintenanceJobs.maintenanceJobs_assets", "maintenanceJobs.maintenanceJobs_timestamp_added", "maintenanceJobs.maintenanceJobs_blockAssets", "maintenanceJobs.maintenanceJobs_flagAssets", "maintenanceJobs.maintenanceJobs_cost", "maintenanceJobs.maintenanceJobsStatuses_id", "maintenanceJobsStatuses.maintenanceJobsStatuses_name", "maintenanceJobsStatuses.maintenanceJobsStatuses_showJobInMainList"]);
    foreach ($rows as $key => $job) {
        $rows[$key]['assetIds'] = array_values(array_filter(array_map('intval', explode(",", (string) $job['maintenanceJobs_assets']))));
        //Jobs without a status, or in one shown in the main list, are still open
        $rows[$key]['isOpen'] = ($job['maintenanceJobsStatuses_id'] === null or $job['maintenanceJobsStatuses_showJobInMainList'] == 1);
        $rows[$key]['added'] = substr($job['maintenanceJobs_timestamp_added'], 0, 10);
        $rows[$key]['cost'] = $job['maintenanceJobs_cost'] === null ? null : $ctx->money($job['maintenanceJobs_cost']);
    }
    return $rows;
}

/** A multiplier for Money::multiply() that's never written in scientific notation */
function reportFactor($factor)
{
    return sprintf('%.12F', $factor);
}
