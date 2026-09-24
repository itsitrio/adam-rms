<?php
if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) require_once __DIR__ . '/../apiHeadSecure.php'; //Only if it wasn't included from somewhere else
require_once __DIR__ . '/../../common/libs/bCMS/projectFinance.php';
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Money;
use Money\Formatter\IntlMoneyFormatter;

$array = [];
if (isset($_POST['formData'])) {
    foreach ($_POST['formData'] as $item) {
        $array[$item['name']] = $item['value'];
        $_GET[$item['name']] = $item['value'];
    }
}
if (isset($_POST['id'])) $_GET['id'] = $_POST['id'];
if (!$AUTH->instancePermissionCheck("PROJECTS:VIEW") or !isset($_GET['id'])) finish(false);

//The project itself
function projectDetails($projectsId) {
    global $DBLIB, $AUTH;
    $DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
    $DBLIB->where("projects.projects_deleted", 0);
    $DBLIB->where("projects.projects_id", $projectsId);
    $DBLIB->join("projectsTypes", "projects.projectsTypes_id=projectsTypes.projectsTypes_id", "LEFT");
    $DBLIB->join("clients", "projects.clients_id=clients.clients_id", "LEFT");
    $DBLIB->join("users", "projects.projects_manager=users.users_userid", "LEFT");
    $DBLIB->join("locations","locations.locations_id=projects.locations_id","LEFT");
    $DBLIB->join("projectsStatuses", "projects.projectsStatuses_id=projectsStatuses.projectsStatuses_id", "LEFT");
    return $DBLIB->getone("projects", ["projects.*", "projectsTypes.*", "clients.clients_id", "clients_name","clients_website","clients_email","clients_notes","clients_address","clients_phone","users.users_userid", "users.users_name1", "users.users_name2", "users.users_email","locations.locations_name","locations.locations_address","projectsStatuses.projectsStatuses_id", "projectsStatuses.projectsStatuses_name", "projectsStatuses.projectsStatuses_foregroundColour","projectsStatuses.projectsStatuses_backgroundColour","projectsStatuses.projectsStatuses_assetsReleased","projectsStatuses.projectsStatuses_description"]);
}
$PAGEDATA['project'] = projectDetails($_GET['id']);
if (!$PAGEDATA['project']) $PAGEDATA['USE_TWIG_404'] ? die($TWIG->render('404.twig', $PAGEDATA)) : die("404");

//subprojects
$DBLIB->where("projects.instances_id", $AUTH->data['instance']['instances_id']);
$DBLIB->where("projects.projects_deleted", 0);
$DBLIB->where("projects.projects_parent_project_id", $_GET['id']);
$DBLIB->join("projectsStatuses", "projects.projectsStatuses_id=projectsStatuses.projectsStatuses_id", "LEFT");
$PAGEDATA['project']['subProjects'] = $DBLIB->get("projects", null, ["projects.*", "projectsStatuses.projectsStatuses_name", "projectsStatuses.projectsStatuses_foregroundColour","projectsStatuses.projectsStatuses_backgroundColour"]);

//Finances

/**
 * Lays a project's equipment out the way its quote shows it: the units allocated to each of the project's quote
 * sections under that section's heading, and everything else under its asset category as before. Note lines are
 * placed in their section, or at the end if they don't have one.
 *
 * Each entry is a copy of the assignment covering just the units shown there, so an assignment split across two
 * sections appears in both. The last part of an assignment takes whatever's left of its price, so the parts
 * always add up to the assignment's price.
 */
function projectQuoteLayout($project, $sections, $allocations, $assets, $assetsAssignedSUB, $moneyFormatter) {
    global $DBLIB, $AUTH;
    $currency = new Currency($AUTH->data['instance']['instances_config_currency']);
    $totals = function () use ($currency) {
        return ["quantity" => 0, "price" => new Money(0, $currency), "discountPrice" => new Money(0, $currency), "mass" => 0.0];
    };
    $return = ["sections" => [], "unsectioned" => [], "unsectionedSUB" => [], "lines" => [], "linesTotal" => new Money(0, $currency)];
    foreach ($sections as $section) {
        $return['sections'][$section['projectsQuoteSections_id']] = $section + ["types" => [], "lines" => [], "totals" => $totals()];
    }

    foreach ($assets as $asset) {
        //Split the assignment's units into the sections they've been allocated to, with anything left over going under its category
        $remaining = $asset['assetsAssignments_quantity'];
        $parts = [];
        foreach ($asset['quoteAllocations'] as $allocation) {
            $quantity = min(intval($allocation['projectsQuoteAllocations_quantity']), $remaining);
            if ($quantity < 1) continue;
            $parts[] = ["section" => $allocation['projectsQuoteSections_id'], "quantity" => $quantity];
            $remaining -= $quantity;
        }
        if ($remaining > 0) $parts[] = ["section" => null, "quantity" => $remaining];

        $priceSoFar = new Money(0, $currency);
        $discountPriceSoFar = new Money(0, $currency);
        foreach ($parts as $index => $part) {
            $item = $asset;
            $item['assetsAssignments_quantity'] = $part['quantity'];
            if ($index == count($parts) - 1) {
                $item['price'] = $asset['price']->subtract($priceSoFar);
                $item['discountPrice'] = $asset['discountPrice']->subtract($discountPriceSoFar);
            } else {
                $item['price'] = $asset['unitPrice']->multiply($part['quantity']);
                $item['discountPrice'] = ($asset['assetsAssignments_discount'] > 0 ? $item['price']->multiply(1 - ($asset['assetsAssignments_discount'] / 100)) : $item['price']);
            }
            $priceSoFar = $priceSoFar->add($item['price']);
            $discountPriceSoFar = $discountPriceSoFar->add($item['discountPrice']);
            $item['mass'] = $asset['mass'] / $asset['assetsAssignments_quantity'] * $part['quantity'];
            $item['formattedPrice'] = $moneyFormatter->format($item['price']);
            $item['formattedDiscountPrice'] = $moneyFormatter->format($item['discountPrice']);
            $item['formattedMass'] = number_format($item['mass'], 2, '.', '') . "kg";

            if ($part['section'] !== null) {
                $return['sections'][$part['section']]['types'][$asset['assetTypes_id']]['assets'][] = $item;
            } elseif ($asset['instances_id'] != $project['instances_id']) {
                if (!isset($return['unsectionedSUB'][$asset['instances_id']])) $return['unsectionedSUB'][$asset['instances_id']] = ["instance" => $assetsAssignedSUB[$asset['instances_id']]['instance'], "assets" => []];
                $return['unsectionedSUB'][$asset['instances_id']]['assets'][$asset['assetTypes_id']]['assets'][] = $item;
            } else {
                $return['unsectioned'][$asset['assetTypes_id']]['assets'][] = $item;
            }
        }
    }

    //Totals for each asset type, the same as the ones the project's asset list shows
    $typeTotals = function ($types) use ($totals, $moneyFormatter) {
        foreach ($types as $key => $type) {
            $types[$key]['totals'] = $totals() + ["status" => null];
            foreach ($type['assets'] as $item) {
                if ($types[$key]['totals']['status'] === null) $types[$key]['totals']['status'] = $item['assetsAssignmentsStatus_name'];
                elseif ($types[$key]['totals']['status'] != $item['assetsAssignmentsStatus_name']) $types[$key]['totals']['status'] = false;
                $types[$key]['totals']['quantity'] += $item['assetsAssignments_quantity'];
                $types[$key]['totals']['price'] = $types[$key]['totals']['price']->add($item['price']);
                $types[$key]['totals']['discountPrice'] = $types[$key]['totals']['discountPrice']->add($item['discountPrice']);
                $types[$key]['totals']['mass'] += $item['mass'];
            }
            $types[$key]['totals']['formattedPrice'] = $moneyFormatter->format($types[$key]['totals']['price']);
            $types[$key]['totals']['formattedDiscountPrice'] = $moneyFormatter->format($types[$key]['totals']['discountPrice']);
            $types[$key]['totals']['formattedMass'] = number_format($types[$key]['totals']['mass'], 2, '.', '') . "kg";
        }
        return $types;
    };
    $return['unsectioned'] = $typeTotals($return['unsectioned']);
    foreach ($return['unsectionedSUB'] as $instanceId => $instance) {
        $return['unsectionedSUB'][$instanceId]['assets'] = $typeTotals($instance['assets']);
    }

    //Note lines, which can carry a price
    $DBLIB->where("projects_id", $project['projects_id']);
    $DBLIB->where("projectsQuoteLines_deleted", 0);
    $DBLIB->orderBy("projectsQuoteLines_rank", "ASC");
    $DBLIB->orderBy("projectsQuoteLines_id", "ASC");
    foreach ($DBLIB->get("projectsQuoteLines") as $line) {
        $line['projectsQuoteLines_quantity'] = max(1, intval($line['projectsQuoteLines_quantity']));
        $line['unitPrice'] = new Money($line['projectsQuoteLines_price'], $currency);
        $line['price'] = $line['unitPrice']->multiply($line['projectsQuoteLines_quantity']);
        $line['formattedUnitPrice'] = $moneyFormatter->format($line['unitPrice']);
        $line['formattedPrice'] = $moneyFormatter->format($line['price']);
        $return['linesTotal'] = $return['linesTotal']->add($line['price']);
        if ($line['projectsQuoteSections_id'] !== null and isset($return['sections'][$line['projectsQuoteSections_id']])) {
            $return['sections'][$line['projectsQuoteSections_id']]['lines'][] = $line;
        } else $return['lines'][] = $line;
    }

    foreach ($return['sections'] as $sectionId => $section) {
        $section['types'] = $typeTotals($section['types']);
        foreach ($section['types'] as $type) {
            $section['totals']['quantity'] += $type['totals']['quantity'];
            $section['totals']['price'] = $section['totals']['price']->add($type['totals']['price']);
            $section['totals']['discountPrice'] = $section['totals']['discountPrice']->add($type['totals']['discountPrice']);
            $section['totals']['mass'] += $type['totals']['mass'];
        }
        foreach ($section['lines'] as $line) {
            $section['totals']['price'] = $section['totals']['price']->add($line['price']);
            $section['totals']['discountPrice'] = $section['totals']['discountPrice']->add($line['price']);
        }
        $section['totals']['formattedPrice'] = $moneyFormatter->format($section['totals']['price']);
        $section['totals']['formattedDiscountPrice'] = $moneyFormatter->format($section['totals']['discountPrice']);
        $return['sections'][$sectionId] = $section;
    }
    $return['sections'] = array_values($return['sections']);
    $return['formattedLinesTotal'] = $moneyFormatter->format($return['linesTotal']);
    return $return;
}

//Payments and also
function projectFinancials($project) {
    global $DBLIB,$AUTH,$bCMS;
    $projectFinanceHelper = new projectFinance();
    $return = [];

    //create a formatter for money
    $numberFormatter = new \NumberFormatter('en_GB', \NumberFormatter::CURRENCY);
    $moneyFormatter = new IntlMoneyFormatter($numberFormatter, new ISOCurrencies());

    $DBLIB->where("payments.payments_deleted", 0);
    $DBLIB->orderBy("payments.payments_date", "ASC");
    $DBLIB->where("payments.projects_id", $project['projects_id']);
    $payments = $DBLIB->get("payments");
    $return['payments'] = ["received" => ["ledger" => [], "total" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']))], "sales" => ["ledger" => [], "total" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']))], "subHire" => ["ledger" => [], "total" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']))], "staff" => ["ledger" => [], "total" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']))]];
    foreach ($payments as $payment) {
        $payment['files'] = $bCMS->s3List(14, $payment['payments_id']);
        $key = false;
        switch ($payment['payments_type']) {
            case 1:
                $key = "received";
                break;
            case 2:
                $key = 'sales';
                break;
            case 3:
                $key = 'subHire';
                break;
            case 4:
                $key = 'staff';
                break;
        }
        if ($key) {
            $payment['payments_amount'] = new Money($payment['payments_amount'], new Currency($AUTH->data['instance']['instances_config_currency']));
            $payment['payments_amountTotal'] = $payment['payments_amount']->multiply($payment['payments_quantity']);
            $return['payments'][$key]['total'] = $payment['payments_amountTotal']->add($return['payments'][$key]['total']);
            $return['payments'][$key]['ledger'][] = $payment;
        } else throw new Exception("Unknown payment type found");
    }

    //Assets
    $DBLIB->where("projects_id", $project['projects_id']);
    $DBLIB->where("assetsAssignments.assetsAssignments_deleted", 0);
    $DBLIB->join("assets", "assetsAssignments.assets_id=assets.assets_id", "LEFT");
    $DBLIB->join("assetTypes", "assets.assetTypes_id=assetTypes.assetTypes_id", "LEFT");
    $DBLIB->join("manufacturers", "manufacturers.manufacturers_id=assetTypes.manufacturers_id", "LEFT");
    $DBLIB->join("assetCategories", "assetTypes.assetCategories_id=assetCategories.assetCategories_id", "LEFT");
    $DBLIB->join("assetCategoriesGroups", "assetCategoriesGroups.assetCategoriesGroups_id=assetCategories.assetCategoriesGroups_id", "LEFT");
    $DBLIB->join("assetsAssignmentsStatus", "assetsAssignments.assetsAssignmentsStatus_id=assetsAssignmentsStatus.assetsAssignmentsStatus_id", "LEFT");
    $DBLIB->orderBy("assetCategories.assetCategories_rank", "ASC");
    $DBLIB->orderBy("assetCategories.assetCategories_id", "ASC");
    $DBLIB->orderBy("assetTypes.assetTypes_id", "ASC");
    $DBLIB->orderBy("assets.assets_tag", "ASC");
    $DBLIB->where("assets.assets_deleted", 0);
    $assets = $DBLIB->get("assetsAssignments", null, ["assetCategories.assetCategories_rank","assetsAssignmentsStatus.assetsAssignmentsStatus_id", "assetsAssignmentsStatus.assetsAssignmentsStatus_order", "assetsAssignmentsStatus.assetsAssignmentsStatus_name","assetsAssignments.*", "manufacturers.manufacturers_name", "assetTypes.*", "assets.*", "assetCategories.assetCategories_name", "assetCategories.assetCategories_fontAwesome", "assetCategoriesGroups.assetCategoriesGroups_name", "assets.instances_id"]);

    $return['assetsAssigned'] = [];
    $return['assetsAssignedSUB'] = [];
    $return['assetsAssignedQuantity'] = 0; //Units assigned, which isn't the number of assignments once unserialized assets are involved
    $return['mass'] = 0.0; //TODO evaluate whether using floats for mass is a good idea....
    $return['value'] = new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']));
    $return['prices'] = ["subTotal" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])), "discounts" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])), "total" => new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']))];

    $return['priceMaths'] = $projectFinanceHelper->durationMaths($project['projects_id']);

    //Quote sections - custom headings for the quote, and how many units of each assignment are shown under them
    $DBLIB->where("projects_id", $project['projects_id']);
    $DBLIB->where("projectsQuoteSections_deleted", 0);
    $DBLIB->orderBy("projectsQuoteSections_rank", "ASC");
    $DBLIB->orderBy("projectsQuoteSections_id", "ASC");
    $quoteSections = $DBLIB->get("projectsQuoteSections", null, ["projectsQuoteSections_id", "projectsQuoteSections_name", "projectsQuoteSections_rank"]);
    $quoteAllocations = [];
    if (count($quoteSections) > 0) {
        $DBLIB->where("projectsQuoteAllocations.projectsQuoteSections_id", array_column($quoteSections, "projectsQuoteSections_id"), "IN");
        $DBLIB->join("projectsQuoteSections", "projectsQuoteAllocations.projectsQuoteSections_id=projectsQuoteSections.projectsQuoteSections_id", "LEFT");
        $DBLIB->orderBy("projectsQuoteSections.projectsQuoteSections_rank", "ASC");
        $DBLIB->orderBy("projectsQuoteSections.projectsQuoteSections_id", "ASC");
        foreach ($DBLIB->get("projectsQuoteAllocations", null, ["projectsQuoteAllocations.*", "projectsQuoteSections.projectsQuoteSections_name"]) as $allocation) {
            $quoteAllocations[$allocation['assetsAssignments_id']][] = $allocation;
        }
    }
    $quoteAssets = [];

    foreach ($assets as $asset) {
        //An assignment can take several units of an unserialized asset, and each of them weighs, costs and is worth the same
        $asset['assetsAssignments_quantity'] = (intval($asset['assetsAssignments_quantity']) > 0 ? intval($asset['assetsAssignments_quantity']) : 1);
        $return['assetsAssignedQuantity'] += $asset['assetsAssignments_quantity'];
        $asset['mass'] = ($asset['assets_mass'] == null ? $asset['assetTypes_mass'] : $asset['assets_mass']) * $asset['assetsAssignments_quantity'];
        $return['mass'] += $asset['mass'];
        $asset['value'] = (new Money(($asset['assets_value'] != null ? $asset['assets_value'] : $asset['assetTypes_value']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($asset['assetsAssignments_quantity']);
        $return['value'] = $return['value']->add($asset['value']);

        if ($asset['assetsAssignments_customPrice'] == null) {
            //The actual pricing calculator
            $asset['price'] = new Money(null, new Currency($AUTH->data['instance']['instances_config_currency']));
            $asset['price'] = $asset['price']->add((new Money(($asset['assets_dayRate'] !== null ? $asset['assets_dayRate'] : $asset['assetTypes_dayRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($return['priceMaths']['days']));
            $asset['price'] = $asset['price']->add((new Money(($asset['assets_weekRate'] !== null ? $asset['assets_weekRate'] : $asset['assetTypes_weekRate']), new Currency($AUTH->data['instance']['instances_config_currency'])))->multiply($return['priceMaths']['weeks']));
        } else $asset['price'] = new Money($asset['assetsAssignments_customPrice'],new Currency($AUTH->data['instance']['instances_config_currency']));
        $asset['unitPrice'] = $asset['price'];
        $asset['price'] = $asset['price']->multiply($asset['assetsAssignments_quantity']);

        $return['prices']['subTotal'] = $asset['price']->add($return['prices']['subTotal']);

        if ($asset['assetsAssignments_discount'] > 0) $asset['discountPrice'] = $asset['price']->multiply(1 - ($asset['assetsAssignments_discount'] / 100));
        else $asset['discountPrice'] = $asset['price'];

        $return['prices']['discounts'] = $return['prices']['discounts']->add($asset['price']->subtract($asset['discountPrice']));
        $return['prices']['total'] = $return['prices']['total']->add($asset['discountPrice']);

        //Formatted values for each asset
        $asset['formattedValue'] = $moneyFormatter->format($asset['value']);
        $asset['formattedPrice'] = $moneyFormatter->format($asset['price']);
        $asset['formattedDiscountPrice'] = $moneyFormatter->format($asset['discountPrice']);
        $asset['formattedMass'] = number_format($asset['mass'], 2, '.', '') . "kg";

        $asset['flagsblocks'] = assetFlagsAndBlocks($asset['assets_id']);

        $asset['quoteAllocations'] = (isset($quoteAllocations[$asset['assetsAssignments_id']]) ? $quoteAllocations[$asset['assetsAssignments_id']] : []);
        $asset['quoteAllocationsBySection'] = (object) array_column($asset['quoteAllocations'], "projectsQuoteAllocations_quantity", "projectsQuoteSections_id");

        $asset['assetTypes_definableFields_ARRAY'] = array_filter(explode(",", $asset['assetTypes_definableFields']));

        $asset['latestScan'] = assetLatestScan($asset['assets_id']);

        if ($asset['instances_id'] != $project['instances_id']) {
            if (!isset($return['assetsAssignedSUB'][$asset['instances_id']]['assets'])) $return['assetsAssignedSUB'][$asset['instances_id']]['assets'] = [];
            if (!isset($return['assetsAssignedSUB'][$asset['instances_id']]['assets'][$asset['assetTypes_id']])) $return['assetsAssignedSUB'][$asset['instances_id']]['assets'][$asset['assetTypes_id']]['assets'] = [];
            $return['assetsAssignedSUB'][$asset['instances_id']]['assets'][$asset['assetTypes_id']]['assets'][] = $asset;
        } else {
            if (!isset($return['assetsAssigned'][$asset['assetTypes_id']])) $return['assetsAssigned'][$asset['assetTypes_id']]['assets'] = [];
            $return['assetsAssigned'][$asset['assetTypes_id']]['assets'][] = $asset;
        }
        $quoteAssets[] = $asset;
    }
    foreach ($return['assetsAssigned'] as $key => $type) {
        if (!isset($return['assetsAssigned'][$key]['totals'])) $return['assetsAssigned'][$key]['totals'] = ["status" => null,"quantity"=>0,"discountPrice"=>new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])),"price"=>new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])),"mass"=>0.0];
        foreach ($type['assets'] as $asset) {
            if ($return['assetsAssigned'][$key]['totals']['status'] == null) $return['assetsAssigned'][$key]['totals']['status'] = $asset['assetsAssignmentsStatus_name'];
            elseif ($return['assetsAssigned'][$key]['totals']['status'] != $asset['assetsAssignmentsStatus_name']) $return['assetsAssigned'][$key]['totals']['status'] = false; //They aren't all the same
            $return['assetsAssigned'][$key]['totals']['discountPrice'] = $return['assetsAssigned'][$key]['totals']['discountPrice']->add($asset['discountPrice']);
            $return['assetsAssigned'][$key]['totals']['price'] = $return['assetsAssigned'][$key]['totals']['price']->add($asset['price']);
            $return['assetsAssigned'][$key]['totals']['quantity'] += $asset['assetsAssignments_quantity'];
            $return['assetsAssigned'][$key]['totals']['mass'] += $asset['mass'];
        }
        //formatted Totals
        $return['assetsAssigned'][$key]['totals']['formattedDiscountPrice'] = $moneyFormatter->format($return['assetsAssigned'][$key]['totals']['discountPrice']);
        $return['assetsAssigned'][$key]['totals']['formattedPrice'] = $moneyFormatter->format($return['assetsAssigned'][$key]['totals']['price']);
        $return['assetsAssigned'][$key]['totals']['formattedMass'] = number_format($return['assetsAssigned'][$key]['totals']['mass'], 2, '.', '') . "kg";
    }
    foreach ($return['assetsAssignedSUB'] as $instanceid => $instance) {
        if (!isset($return['assetsAssignedSUB'][$instanceid]['instance'])) {
            $DBLIB->where("instances_id",$instanceid);
            $return['assetsAssignedSUB'][$instanceid]['instance'] = $DBLIB->getone("instances",["instances_id","instances_name"]);
        }
        foreach ($return['assetsAssignedSUB'][$instanceid]['assets'] as $key => $type) {
            if (!isset($return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals'])) $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals'] = ["status" => null,"quantity"=>0,"discountPrice"=>new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])),"price"=>new Money(null, new Currency($AUTH->data['instance']['instances_config_currency'])),"mass"=>0.0];
            foreach ($type['assets'] as $asset) {
                if ($return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['status'] == null) $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['status'] = $asset['assetsAssignmentsStatus_name'];
                elseif ($return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['status'] != $asset['assetsAssignmentsStatus_name']) $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['status'] = false; //They aren't all the same
                $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['discountPrice'] = $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['discountPrice']->add($asset['discountPrice']);
                $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['price'] = $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['price']->add($asset['price']);
                $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['quantity'] += $asset['assetsAssignments_quantity'];
                $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['mass'] += $asset['mass'];
            }
            //Formatted totals
            $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['formattedDiscountPrice'] = $moneyFormatter->format($return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['discountPrice']);
            $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['formattedPrice'] = $moneyFormatter->format($return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['price']);
            $return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['formattedMass'] = number_format($return['assetsAssignedSUB'][$instanceid]['assets'][$key]['totals']['mass'], 2, '.', '') . "kg";
        }
    }

    $return['quote'] = projectQuoteLayout($project, $quoteSections, $quoteAllocations, $quoteAssets, $return['assetsAssignedSUB'], $moneyFormatter);
    //Priced note lines are charged alongside the equipment
    $return['prices']['subTotal'] = $return['prices']['subTotal']->add($return['quote']['linesTotal']);
    $return['prices']['total'] = $return['prices']['total']->add($return['quote']['linesTotal']);

    $return['payments']['subTotal'] = $return['prices']['total']->add($return['payments']['sales']['total'],$return['payments']['subHire']['total'],$return['payments']['staff']['total']);
    $return['payments']['total'] = $return['payments']['subTotal']->subtract($return['payments']['received']['total']);

    //add formatted values to everything
    $return['formattedValue'] = $moneyFormatter->format($return['value']);
    $return['formattedPrices'] = ["subTotal" => $moneyFormatter->format($return['prices']['subTotal']), "discounts" => $moneyFormatter->format($return['prices']['discounts']), "total" => $moneyFormatter->format($return['prices']['total'])];
    $return['formattedMass'] = number_format($return['mass'], 2, '.', '') . "kg";
    //format payments
    foreach ($return['payments'] as $key => $value) {
        if ($key == "subTotal") {
            $return['payments']['formattedSubTotal'] = $moneyFormatter->format($return['payments']["subTotal"]);
        } elseif ($key == "total"){
            $return['payments']['formattedTotal'] = $moneyFormatter->format($return['payments']["total"]);
        } else {
            $return['payments'][$key]['formattedTotal'] = $moneyFormatter->format($return['payments'][$key]['total']);
        }
    }
    
    return $return;
}
/**
 * A project's sub-projects with their details and finances, in date order - for showing or printing them alongside it
 */
function subProjectsWithFinancials($subProjects) {
    $children = [];
    foreach ($subProjects as $subProject) {
        $child = ["project" => projectDetails($subProject['projects_id'])];
        if (!$child['project']) continue;
        $child['FINANCIALS'] = projectFinancials($child['project']);
        foreach (["subHire", "sales", "staff"] as $ledger) {
            usort($child['FINANCIALS']['payments'][$ledger]['ledger'], function ($a, $b) {
                return $a['payments_supplier'] <=> $b['payments_supplier'];
            });
        }
        $children[] = $child;
    }
    usort($children, function ($a, $b) {
        return [$a['project']['projects_dates_deliver_start'] ?? '', $a['project']['projects_id']] <=> [$b['project']['projects_dates_deliver_start'] ?? '', $b['project']['projects_id']];
    });
    return $children;
}

/**
 * Totals for a list of projects (each ["project" => ..., "FINANCIALS" => ...]), one row each plus a row adding them all up
 */
function projectsSummary($projects) {
    $summary = ["rows" => [], "totals" => null];
    foreach ($projects as $row) {
        $summaryRow = [
            "project" => $row['project'],
            "assets" => $row['FINANCIALS']['assetsAssignedQuantity'],
            "equipment" => $row['FINANCIALS']['prices']['total'],
            "other" => $row['FINANCIALS']['payments']['subTotal']->subtract($row['FINANCIALS']['prices']['total']), //Sales, staff and sub-hires
            "total" => $row['FINANCIALS']['payments']['subTotal'],
            "received" => $row['FINANCIALS']['payments']['received']['total'],
            "outstanding" => $row['FINANCIALS']['payments']['total'],
        ];
        $summary['rows'][] = $summaryRow;
        if ($summary['totals'] === null) $summary['totals'] = $summaryRow;
        else {
            $summary['totals']['assets'] += $summaryRow['assets'];
            foreach (["equipment", "other", "total", "received", "outstanding"] as $key) $summary['totals'][$key] = $summary['totals'][$key]->add($summaryRow[$key]);
        }
    }
    return $summary;
}

$PAGEDATA['FINANCIALS'] = projectFinancials($PAGEDATA['project']);
$DBLIB->where("projects_id",$PAGEDATA['project']['projects_id']);
$DBLIB->orderBy("projectsFinanceCache_timestamp", "DESC");
$projectFinanceCache = $DBLIB->getone("projectsFinanceCache");
$projectFinancesCacheMismatch = false;
if (!$projectFinanceCache) {
    //Insert a new project finance cache for this project as it doesn't seem to have one hmmm
    $projectFinanceCacheInsert = [
        "projects_id" => $PAGEDATA['project']['projects_id'],
        "projectsFinanceCache_timestamp" => date("Y-m-d H:i:s"),
        "projectsFinanceCache_equipmentSubTotal" =>$PAGEDATA['FINANCIALS']['prices']['subTotal']->getAmount(),
        "projectsFinanceCache_equiptmentDiscounts" =>$PAGEDATA['FINANCIALS']['prices']['discounts']->getAmount(),
        "projectsFinanceCache_equiptmentTotal" =>$PAGEDATA['FINANCIALS']['prices']['total']->getAmount(),
        "projectsFinanceCache_salesTotal" =>$PAGEDATA['FINANCIALS']['payments']['sales']['total']->getAmount(),
        "projectsFinanceCache_staffTotal" =>$PAGEDATA['FINANCIALS']['payments']['staff']['total']->getAmount(),
        "projectsFinanceCache_externalHiresTotal" => $PAGEDATA['FINANCIALS']['payments']['subHire']['total']->getAmount(),
        "projectsFinanceCache_paymentsReceived" =>$PAGEDATA['FINANCIALS']['payments']['received']['total']->getAmount(),
        "projectsFinanceCache_grandTotal" =>$PAGEDATA['FINANCIALS']['payments']['total']->getAmount(),
        "projectsFinanceCache_mass"=>$PAGEDATA['FINANCIALS']['mass'],
        "projectsFinanceCache_value"=>$PAGEDATA['FINANCIALS']['value']->getAmount(),
    ];
    $DBLIB->insert("projectsFinanceCache", $projectFinanceCacheInsert); //Add a cache for the finance of the project
//Just check the cache while we're here - shouldn't ever be thrown!
} elseif ($projectFinanceCache["projectsFinanceCache_equipmentSubTotal"] != $PAGEDATA['FINANCIALS']['prices']['subTotal']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_equiptmentDiscounts"] != $PAGEDATA['FINANCIALS']['prices']['discounts']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_equiptmentTotal"] != $PAGEDATA['FINANCIALS']['prices']['total']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_salesTotal"] != $PAGEDATA['FINANCIALS']['payments']['sales']['total']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_staffTotal"] != $PAGEDATA['FINANCIALS']['payments']['staff']['total']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_externalHiresTotal"] !=  $PAGEDATA['FINANCIALS']['payments']['subHire']['total']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_paymentsReceived"] != $PAGEDATA['FINANCIALS']['payments']['received']['total']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_grandTotal"] != $PAGEDATA['FINANCIALS']['payments']['total']->getAmount()) $projectFinancesCacheMismatch = true;
elseif ($projectFinanceCache["projectsFinanceCache_value"] != $PAGEDATA['FINANCIALS']['value']->getAmount()) $projectFinancesCacheMismatch = true;
elseif (round($projectFinanceCache["projectsFinanceCache_mass"]*100000) != round($PAGEDATA['FINANCIALS']['mass']*100000)) $projectFinancesCacheMismatch = true;

if ($projectFinancesCacheMismatch) {
    //So there's a serious project finance mismatch - you can force a rebuild of the cache by passing a get parameter - otherwise throw an error so support are notified
    $projectFinanceCacheInsert = [
        "projects_id" => $PAGEDATA['project']['projects_id'],
        "projectsFinanceCache_timestamp" => date("Y-m-d H:i:s"),
        "projectsFinanceCache_equipmentSubTotal" =>$PAGEDATA['FINANCIALS']['prices']['subTotal']->getAmount(),
        "projectsFinanceCache_equiptmentDiscounts" =>$PAGEDATA['FINANCIALS']['prices']['discounts']->getAmount(),
        "projectsFinanceCache_equiptmentTotal" =>$PAGEDATA['FINANCIALS']['prices']['total']->getAmount(),
        "projectsFinanceCache_salesTotal" =>$PAGEDATA['FINANCIALS']['payments']['sales']['total']->getAmount(),
        "projectsFinanceCache_staffTotal" =>$PAGEDATA['FINANCIALS']['payments']['staff']['total']->getAmount(),
        "projectsFinanceCache_externalHiresTotal" => $PAGEDATA['FINANCIALS']['payments']['subHire']['total']->getAmount(),
        "projectsFinanceCache_paymentsReceived" =>$PAGEDATA['FINANCIALS']['payments']['received']['total']->getAmount(),
        "projectsFinanceCache_grandTotal" =>$PAGEDATA['FINANCIALS']['payments']['total']->getAmount(),
        "projectsFinanceCache_mass"=>$PAGEDATA['FINANCIALS']['mass'],
        "projectsFinanceCache_value"=>$PAGEDATA['FINANCIALS']['value']->getAmount(),
    ];
    $newCache = $DBLIB->insert("projectsFinanceCache", $projectFinanceCacheInsert); //Add a cache for the finance of the project
    if(!$newCache) throw new \Exception('Cache reload error');
    else trigger_error("Project finance cache mismatch " . json_encode($projectFinanceCacheInsert) . " vs " . json_encode($projectFinanceCache), E_USER_WARNING);
}



usort($PAGEDATA['FINANCIALS']['payments']['subHire']['ledger'], function ($a, $b) {
    // Sort sub-hires in order of supplier so you can do supplier headings
    return $a['payments_supplier'] <=> $b['payments_supplier'];
});
usort($PAGEDATA['FINANCIALS']['payments']['sales']['ledger'], function ($a, $b) {
    // Sort sub-hires in order of supplier so you can do supplier headings
    return $a['payments_supplier'] <=> $b['payments_supplier'];
});
usort($PAGEDATA['FINANCIALS']['payments']['staff']['ledger'], function ($a, $b) {
    // Sort sub-hires in order of supplier so you can do supplier headings
    return $a['payments_supplier'] <=> $b['payments_supplier'];
});

//Notes
$DBLIB->where("projectsNotes_deleted", 0);
$DBLIB->where("projects_id", $PAGEDATA['project']['projects_id']);
$DBLIB->orderBy("projectsNotes_id", "ASC");
$PAGEDATA['project']['notes'] = $DBLIB->get("projectsNotes");

//Crew
$DBLIB->where("projects_id", $PAGEDATA['project']['projects_id']);
$DBLIB->where("crewAssignments.crewAssignments_deleted", 0);
$DBLIB->join("users", "crewAssignments.users_userid=users.users_userid", "LEFT");
$DBLIB->orderBy("crewAssignments.crewAssignments_rank", "ASC");
$DBLIB->orderBy("crewAssignments.crewAssignments_id", "ASC");
$PAGEDATA['project']['crewAssignments'] = $DBLIB->get("crewAssignments", null, ["crewAssignments.*", "users.users_name1", "users.users_name2", "users.users_email"]);

//Files
$PAGEDATA['files'] = $bCMS->s3List(7, $PAGEDATA['project']['projects_id']);

$PAGEDATA['invoices'] = $bCMS->s3List(20, $PAGEDATA['project']['projects_id'],'s3files_meta_uploaded', 'DESC');
$PAGEDATA['quotes'] = $bCMS->s3List(21, $PAGEDATA['project']['projects_id'],'s3files_meta_uploaded', 'DESC');
$PAGEDATA['deliveryNotes'] = $bCMS->s3List(22, $PAGEDATA['project']['projects_id'],'s3files_meta_uploaded', 'DESC');

$DBLIB->orderBy("assetsAssignmentsStatus_order","ASC");
$DBLIB->where("assetsAssignmentsStatus_deleted", 0);
$DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
$PAGEDATA['assetsAssignmentsStatus'] = $DBLIB->get("assetsAssignmentsStatus");


$DATA = [
    "project" => $PAGEDATA['project'],
    "files" => $PAGEDATA['files'],
    "assetsAssignmentsStatus" => $PAGEDATA['assetsAssignmentsStatus'],
    'FINANCIALS' => $PAGEDATA['FINANCIALS']
]; //Data that's safe to return to the app


if (basename(__FILE__) == basename($_SERVER["SCRIPT_FILENAME"])) finish(true, null, $DATA);

/** @OA\Post(
 *     path="/projects/data.php", 
 *     summary="Data", 
 *     description="Get the data of a project  
Requires Instance Permission PROJECTS:VIEW
", 
 *     operationId="data", 
 *     tags={"projects"}, 
 *     @OA\Response(
 *         response="200", 
 *         description="Success",
 *         @OA\MediaType(
 *             mediaType="application/json", 
 *             @OA\Schema( 
 *                 type="object", 
 *                 @OA\Property(
 *                     property="result", 
 *                     type="boolean", 
 *                     description="Whether the request was successful",
 *                 ),
 *             ),
 *         ),
 *     ), 
 *     @OA\Response(
 *         response="404", 
 *         description="Permission Error",
 *     ), 
 *     @OA\Parameter(
 *         name="formData",
 *         in="query",
 *         description="Form Data",
 *         required="false", 
 *         @OA\Schema(
 *             type="object", 
 *             ),
 *     ), 
 *     @OA\Parameter(
 *         name="id",
 *         in="query",
 *         description="Project ID",
 *         required="false", 
 *         @OA\Schema(
 *             type="number"), 
 *         ), 
 * )
 */