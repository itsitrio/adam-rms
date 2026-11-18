<?php
//Shared by the liability endpoints: reading money and dates from a request, and finding one of the business's liabilities

use Money\Currencies\ISOCurrencies;
use Money\Parser\DecimalMoneyParser;

require_once __DIR__ . '/liabilities.php';

/** A Y-m-d date from the request, null if it was left empty, or the request fails */
function liabilityDate($key, $name, $required = false)
{
    $value = isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : "";
    if ($value === "") {
        if ($required) finish(false, ["code" => "PARAM-ERROR", "message" => "Enter the " . $name]);
        return null;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) or !checkdate(intval(substr($value, 5, 2)), intval(substr($value, 8, 2)), intval(substr($value, 0, 4)))) finish(false, ["code" => "PARAM-ERROR", "message" => "The " . $name . " isn't a date"]);
    return $value;
}

/** An amount of money from the request in minor units, null if it was left empty, or the request fails */
function liabilityMoney($key, $name, $required = false)
{
    global $AUTH;
    $value = isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : "";
    if ($value === "") {
        if ($required) finish(false, ["code" => "PARAM-ERROR", "message" => "Enter the " . $name]);
        return null;
    }
    if (!preg_match('/^\d+(\.\d+)?$/', $value)) finish(false, ["code" => "PARAM-ERROR", "message" => "The " . $name . " must be a number"]);
    return (new DecimalMoneyParser(new ISOCurrencies()))->parse($value, $AUTH->data['instance']['instances_config_currency'])->getAmount();
}

/** Text from the request, trimmed and cut to a length - null if it's empty */
function liabilityText($key, $limit)
{
    if (!isset($_POST[$key]) or !is_string($_POST[$key])) return null;
    $value = trim($_POST[$key]);
    return $value === "" ? null : mb_substr($value, 0, $limit);
}

/** One of the business's liabilities, or the request fails */
function liabilityFind($id)
{
    global $DBLIB, $AUTH;
    $DBLIB->where("liabilities_id", intval($id));
    $DBLIB->where("instances_id", $AUTH->data['instance']['instances_id']);
    $DBLIB->where("liabilities_deleted", 0);
    $liability = $DBLIB->getOne("liabilities");
    if (!$liability) finish(false, ["code" => "PARAM-ERROR", "message" => "Could not find the liability"]);
    return $liability;
}
