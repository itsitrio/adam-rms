<?php
/**
 * Asset reports - what assets earn, how much they're used, and what the business holds
 */

/** A link cell for an asset */
function reportAssetLink($asset)
{
    global $CONFIG;
    return ["text" => $asset['assets_tag'] . " " . $asset['assetTypes_name'], "href" => $CONFIG['ROOTURL'] . "/asset.php?id=" . $asset['assetTypes_id'] . "&asset=" . $asset['assets_id']];
}

/** Mass in the business's unit, from kg */
function reportMass(ReportContext $ctx, $kg)
{
    return ($ctx->instance['instances_config_massUnit'] ?? "kg") == "lb" ? round($kg * 2.20462262, 1) : round($kg, 1);
}

/** The first and last day (inclusive) the business held an asset within a period, or null if it didn't */
function reportHeldWithin($asset, $start, $end)
{
    $from = max($asset['added'], $start);
    $to = $asset['endDate'] ? min(date("Y-m-d", strtotime($asset['endDate'] . " -1 day")), $end) : $end;
    return $from <= $to ? [$from, $to] : null;
}

function reportAssetReturn(ReportContext $ctx)
{
    $projects = reportProjects($ctx);
    $assets = [];
    foreach (reportAssets($ctx) as $asset) $assets[$asset['assets_id']] = $asset;
    $types = [];
    $type = function ($asset) use (&$types, $ctx) {
        $id = $asset['assetTypes_id'];
        if (!isset($types[$id])) $types[$id] = ["type" => $asset['assetTypes_name'], "category" => $asset['categoryName'], "units" => 0, "value" => $ctx->zero(), "cost" => $ctx->zero(), "costed" => 0,
            "revenue" => $ctx->zero(), "lifetime" => $ctx->zero(), "assets" => 0];
        return $id;
    };
    foreach ($assets as $asset) {
        if (!reportAssetHeldOn($asset, $ctx->end) and reportHeldWithin($asset, $ctx->start, $ctx->end) === null) continue;
        $id = $type($asset);
        $types[$id]['assets']++;
        $types[$id]['units'] += $asset['quantityHeld'];
        $types[$id]['value'] = $types[$id]['value']->add($asset['value']);
        if ($asset['purchaseCost'] !== null) {
            $types[$id]['cost'] = $types[$id]['cost']->add($asset['purchaseCost']);
            $types[$id]['costed']++;
        }
    }
    foreach (reportAssignments($ctx) as $assignment) {
        if (!isset($assets[$assignment['assets_id']]) or $assignment['assets_instances_id'] != $ctx->instanceId) continue;
        $project = $projects[$assignment['projects_id']] ?? null;
        if (!$project or !$project['isRevenue'] or $project['end'] === null or $project['end'] > $ctx->end) continue;
        $id = $type($assets[$assignment['assets_id']]);
        $types[$id]['lifetime'] = $types[$id]['lifetime']->add($assignment['price']);
        if ($project['end'] >= $ctx->start) $types[$id]['revenue'] = $types[$id]['revenue']->add($assignment['price']);
    }
    $years = reportDaysInclusive($ctx->start, $ctx->end) / 365;
    $rows = [];
    foreach ($types as $row) {
        $row['yield'] = ($years > 0 and !$row['value']->isZero()) ? ReportContext::ratio($row['revenue'], $row['value']) / $years : null;
        $row['payback'] = ($row['costed'] > 0 and $row['costed'] == $row['assets'] and !$row['cost']->isZero()) ? ReportContext::ratio($row['lifetime'], $row['cost']) : null;
        $row['perUnit'] = $row['units'] > 0 ? $row['revenue']->divide($row['units']) : null;
        if ($row['costed'] == 0) $row['cost'] = null;
        $rows[] = $row;
    }
    usort($rows, function ($a, $b) { return $b['revenue']->compare($a['revenue']); });
    $columns = [reportColumn("type", "Asset type"), reportColumn("category", "Category"), reportColumn("units", "Units", "number", true), reportColumn("value", "Replacement value", "money", true), reportColumn("cost", "Purchase cost", "money", true),
        reportColumn("revenue", "Revenue in period", "money", true), reportColumn("perUnit", "Revenue per unit", "money"), reportColumn("yield", "Yearly yield on value", "percent"), reportColumn("lifetime", "Revenue to date", "money", true), reportColumn("payback", "Paid for itself", "bar")];
    $totals = reportSumColumns($ctx, $columns, $rows);
    $totals['yield'] = ($years > 0 and !$totals['value']->isZero()) ? ReportContext::ratio($totals['revenue'], $totals['value']) / $years : null;
    $idle = count(array_filter($rows, function ($r) { return $r['revenue']->isZero(); }));
    return [
        "kpis" => [
            ["label" => "Equipment revenue", "value" => $totals['revenue'], "type" => "money"],
            ["label" => "Replacement value", "value" => $totals['value'], "type" => "money"],
            ["label" => "Yearly yield on value", "value" => $totals['yield'], "type" => "percent"],
            ["label" => "Types that earned nothing", "value" => $idle, "type" => "number", "note" => "of " . count($rows)],
        ],
        "sections" => [["id" => "types", "title" => "By asset type", "columns" => $columns, "rows" => $rows, "totals" => $totals]],
        "notes" => [
            "Revenue is each asset's price on won projects whose delivery ended in the period, priced as its project prices it. Yield is that revenue as a share of the type's replacement value, scaled to a year.",
            "Paid for itself is revenue to date against purchase cost - 100% means the type has earned back what it cost. It's only shown where every asset of the type has a purchase cost.",
        ],
    ];
}

function reportUtilisation(ReportContext $ctx)
{
    $projects = reportProjects($ctx);
    $assets = [];
    foreach (reportAssets($ctx) as $asset) {
        $held = reportHeldWithin($asset, $ctx->start, $ctx->end);
        if ($held === null) continue;
        $asset['held'] = $held;
        $asset['available'] = reportDaysInclusive($held[0], $held[1]) * $asset['quantityHeld'];
        $asset['booked'] = 0;
        $assets[$asset['assets_id']] = $asset;
    }
    foreach (reportAssignments($ctx) as $assignment) {
        if (!isset($assets[$assignment['assets_id']])) continue;
        $project = $projects[$assignment['projects_id']] ?? null;
        if (!$project or $project['stage'] != "WON" or $project['start'] === null or $project['end'] === null) continue;
        $asset = $assets[$assignment['assets_id']];
        $days = reportOverlapDays($project['start'], $project['end'], $asset['held'][0], $asset['held'][1]);
        $assets[$assignment['assets_id']]['booked'] += $days * $assignment['quantityCounted'];
    }
    $types = [];
    $assetRows = [];
    foreach ($assets as $asset) {
        $booked = min($asset['booked'], $asset['available']);
        $id = $asset['assetTypes_id'];
        $types[$id] = $types[$id] ?? ["type" => $asset['assetTypes_name'], "category" => $asset['categoryName'], "units" => 0, "available" => 0, "booked" => 0];
        $types[$id]['units'] += $asset['quantityHeld'];
        $types[$id]['available'] += $asset['available'];
        $types[$id]['booked'] += $booked;
        $assetRows[] = ["asset" => reportAssetLink($asset), "category" => $asset['categoryName'], "units" => $asset['quantityHeld'], "available" => $asset['available'], "booked" => $booked, "utilisation" => $asset['available'] > 0 ? $booked / $asset['available'] : null];
    }
    $rows = array_values(array_map(function ($row) {
        $row['utilisation'] = $row['available'] > 0 ? $row['booked'] / $row['available'] : null;
        return $row;
    }, $types));
    usort($rows, function ($a, $b) { return $b['utilisation'] <=> $a['utilisation']; });
    usort($assetRows, function ($a, $b) { return $a['utilisation'] <=> $b['utilisation']; });
    $columns = [reportColumn("type", "Asset type"), reportColumn("category", "Category"), reportColumn("units", "Units", "number", true), reportColumn("available", "Unit days held", "number", true), reportColumn("booked", "Unit days on projects", "number", true), reportColumn("utilisation", "Utilisation", "bar")];
    $totals = reportSumColumns($ctx, $columns, $rows);
    $totals['utilisation'] = $totals['available'] > 0 ? $totals['booked'] / $totals['available'] : null;
    $assetColumns = [reportColumn("asset", "Asset", "link"), reportColumn("category", "Category"), reportColumn("units", "Units", "number"), reportColumn("available", "Unit days held", "number", true), reportColumn("booked", "Unit days on projects", "number", true), reportColumn("utilisation", "Utilisation", "bar")];
    $unused = count(array_filter($assetRows, function ($r) { return $r['booked'] == 0; }));
    return [
        "kpis" => [
            ["label" => "Utilisation", "value" => $totals['utilisation'], "type" => "percent", "note" => "of unit days held"],
            ["label" => "Busiest type", "value" => $rows[0]['type'] ?? null, "type" => "text", "note" => isset($rows[0]) ? reportPercentText($rows[0]['utilisation']) : null],
            ["label" => "Assets never out", "value" => $unused, "type" => "number", "note" => "of " . count($assetRows)],
        ],
        "sections" => [
            ["id" => "types", "title" => "By asset type", "columns" => $columns, "rows" => $rows, "totals" => $totals],
            ["id" => "assets", "title" => "By asset, least used first", "columns" => $assetColumns, "rows" => $assetRows],
        ],
        "notes" => ["The days each asset spent on won projects, from the start of delivery to its end, out of the days the business held it in the period. An unserialized asset counts each of its units."],
    ];
}

function reportDeadStock(ReportContext $ctx)
{
    $months = $ctx->params['inactive'] ?? 6;
    $cutoff = date("Y-m-d", strtotime($ctx->today . " -" . $months . " months"));
    $projects = reportProjects($ctx);
    $lastBooked = [];
    $upcoming = [];
    foreach (reportAssignments($ctx) as $assignment) {
        $project = $projects[$assignment['projects_id']] ?? null;
        if (!$project or !in_array($project['stage'], ["WON", "OPPORTUNITY", "LEAD"], true) or $project['end'] === null) continue;
        $id = $assignment['assets_id'];
        if ($project['stage'] == "WON" and $project['end'] <= $ctx->today) $lastBooked[$id] = max($lastBooked[$id] ?? "", $project['end']);
        if ($project['end'] > $ctx->today) $upcoming[$id] = true;
    }
    $rows = [];
    $categories = [];
    $value = $ctx->zero();
    foreach (reportAssets($ctx) as $asset) {
        if (!reportAssetHeldOn($asset, $ctx->today) or $asset['added'] > $cutoff or isset($upcoming[$asset['assets_id']])) continue;
        $last = $lastBooked[$asset['assets_id']] ?? null;
        if ($last !== null and $last >= $cutoff) continue;
        $depreciation = reportDepreciation($ctx, $asset, $ctx->today, $ctx->today);
        $rows[] = ["asset" => reportAssetLink($asset), "category" => $asset['categoryName'], "location" => $asset['locations_name'], "units" => $asset['quantityHeld'], "last" => $last ?? "Never", "value" => $asset['value'], "nbv" => $depreciation ? $depreciation['nbv'] : null];
        $categories[$asset['categoryName']] = $categories[$asset['categoryName']] ?? ["category" => $asset['categoryName'], "assets" => 0, "value" => $ctx->zero()];
        $categories[$asset['categoryName']]['assets']++;
        $categories[$asset['categoryName']]['value'] = $categories[$asset['categoryName']]['value']->add($asset['value']);
        $value = $value->add($asset['value']);
    }
    usort($rows, function ($a, $b) { return $b['value']->compare($a['value']); });
    $categories = array_values($categories);
    usort($categories, function ($a, $b) { return $b['value']->compare($a['value']); });
    $columns = [reportColumn("asset", "Asset", "link"), reportColumn("category", "Category"), reportColumn("location", "Stored at"), reportColumn("units", "Units", "number", true), reportColumn("last", "Last out", "date"), reportColumn("value", "Replacement value", "money", true), reportColumn("nbv", "Book value", "money", true)];
    $categoryColumns = [reportColumn("category", "Category"), reportColumn("assets", "Assets", "number", true), reportColumn("value", "Replacement value", "money", true)];
    return [
        "kpis" => [
            ["label" => "Assets not booked", "value" => count($rows), "type" => "number", "note" => "since " . date("j M Y", strtotime($cutoff))],
            ["label" => "Value tied up", "value" => $value, "type" => "money"],
        ],
        "sections" => [
            ["id" => "categories", "title" => "By category", "columns" => $categoryColumns, "rows" => $categories, "totals" => reportSumColumns($ctx, $categoryColumns, $categories), "empty" => "Every asset has been out recently."],
            ["id" => "assets", "title" => "Assets", "columns" => $columns, "rows" => $rows, "totals" => reportSumColumns($ctx, $columns, $rows), "empty" => "Every asset has been out recently."],
        ],
        "notes" => ["Assets the business has held for at least " . $months . " months that haven't been on a won project in that time and aren't on any upcoming project, lead or opportunity - candidates to sell or stop buying."],
    ];
}

function reportAssetRegister(ReportContext $ctx)
{
    $categories = [];
    $locations = [];
    $detail = [];
    $total = ["assets" => 0, "units" => 0, "value" => $ctx->zero(), "cost" => $ctx->zero(), "nbv" => $ctx->zero(), "uncosted" => 0];
    $unit = $ctx->instance['instances_config_massUnit'] ?? "kg";
    foreach (reportAssets($ctx) as $asset) {
        if (!reportAssetHeldOn($asset, $ctx->asAt)) continue;
        $depreciation = reportDepreciation($ctx, $asset, $ctx->asAt, $ctx->asAt);
        $nbv = $depreciation ? $depreciation['nbv'] : null;
        foreach (["categories" => $asset['categoryName'], "locations" => ($asset['locations_name'] ?: "No storage location")] as $group => $name) {
            $target = &$$group;
            $target[$name] = $target[$name] ?? ["name" => $name, "assets" => 0, "units" => 0, "value" => $ctx->zero(), "cost" => $ctx->zero(), "nbv" => $ctx->zero(), "mass" => 0.0];
            $target[$name]['assets']++;
            $target[$name]['units'] += $asset['quantityHeld'];
            $target[$name]['value'] = $target[$name]['value']->add($asset['value']);
            if ($asset['purchaseCost'] !== null) $target[$name]['cost'] = $target[$name]['cost']->add($asset['purchaseCost']);
            if ($nbv !== null) $target[$name]['nbv'] = $target[$name]['nbv']->add($nbv);
            $target[$name]['mass'] += reportMass($ctx, $asset['mass']);
            unset($target);
        }
        $total['assets']++;
        $total['units'] += $asset['quantityHeld'];
        $total['value'] = $total['value']->add($asset['value']);
        if ($asset['purchaseCost'] !== null) $total['cost'] = $total['cost']->add($asset['purchaseCost']);
        else $total['uncosted']++;
        if ($nbv !== null) $total['nbv'] = $total['nbv']->add($nbv);
        if ($ctx->params['detail'] ?? false) {
            $detail[] = ["asset" => reportAssetLink($asset), "manufacturer" => $asset['manufacturers_name'], "category" => $asset['categoryName'], "location" => $asset['locations_name'], "units" => $asset['quantityHeld'],
                "purchased" => $asset['purchaseDate'], "cost" => $asset['purchaseCost'], "nbv" => $nbv, "value" => $asset['value'], "mass" => reportMass($ctx, $asset['mass'])];
        }
    }
    $groupColumns = function ($label) use ($unit) {
        return [reportColumn("name", $label), reportColumn("assets", "Assets", "number", true), reportColumn("units", "Units", "number", true), reportColumn("value", "Replacement value", "money", true), reportColumn("cost", "Purchase cost", "money", true), reportColumn("nbv", "Book value", "money", true), reportColumn("mass", "Mass (" . $unit . ")", "number", true)];
    };
    $sort = function ($rows) {
        $rows = array_values($rows);
        usort($rows, function ($a, $b) { return $b['value']->compare($a['value']); });
        return $rows;
    };
    $categories = $sort($categories);
    $locations = $sort($locations);
    $sections = [
        ["id" => "categories", "title" => "By category", "columns" => $groupColumns("Category"), "rows" => $categories, "totals" => reportSumColumns($ctx, $groupColumns("Category"), $categories)],
        ["id" => "locations", "title" => "By storage location", "columns" => $groupColumns("Storage location"), "rows" => $locations, "totals" => reportSumColumns($ctx, $groupColumns("Storage location"), $locations)],
    ];
    if ($ctx->params['detail'] ?? false) {
        $detailColumns = [reportColumn("asset", "Asset", "link"), reportColumn("manufacturer", "Manufacturer"), reportColumn("category", "Category"), reportColumn("location", "Stored at"), reportColumn("units", "Units", "number", true),
            reportColumn("purchased", "Bought", "date"), reportColumn("cost", "Purchase cost", "money", true), reportColumn("nbv", "Book value", "money", true), reportColumn("value", "Replacement value", "money", true), reportColumn("mass", "Mass (" . $unit . ")", "number", true)];
        $sections[] = ["id" => "assets", "title" => "Every asset", "columns" => $detailColumns, "rows" => $detail, "totals" => reportSumColumns($ctx, $detailColumns, $detail)];
    }
    return [
        "kpis" => [
            ["label" => "Assets", "value" => $total['assets'], "type" => "number", "note" => $total['units'] . " unit" . ($total['units'] == 1 ? "" : "s")],
            ["label" => "Replacement value", "value" => $total['value'], "type" => "money"],
            ["label" => "Purchase cost", "value" => $total['cost'], "type" => "money"],
            ["label" => "Book value", "value" => $total['nbv'], "type" => "money"],
        ],
        "sections" => $sections,
        "warnings" => $total['uncosted'] > 0 ? [$total['uncosted'] . " asset" . ($total['uncosted'] == 1 ? " has" : "s have") . " no purchase cost, so " . ($total['uncosted'] == 1 ? "has" : "have") . " no book value. Add it on each asset's page."] : [],
        "notes" => ["Replacement value is each asset's value, or its type's. Book value is purchase cost less straight line depreciation to the date."],
    ];
}
