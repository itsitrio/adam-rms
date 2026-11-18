<?php
/**
 * Reading an amount of money someone typed. The reporting forms' money boxes are text rather than number inputs,
 * because some browsers (Safari, Firefox) send nothing at all from a number input holding "£1,250" - which would
 * look like the box was left empty, and clear a cost rather than set it.
 */

use Money\Currencies\ISOCurrencies;
use Money\Parser\DecimalMoneyParser;

/**
 * An amount as typed - "1250", "£1,250.50", "1 250", "250,5" - as a plain decimal ("1250.50"), "" if nothing was
 * typed, or false if it isn't an amount. A comma is a thousands separator when it's followed by groups of three
 * digits ("1,250"), and a decimal point otherwise ("250,5"). Negative amounts aren't amounts.
 */
function moneyInputNormalise($value)
{
    if ($value === null) return "";
    if (!is_scalar($value)) return false;
    $typed = trim((string) $value);
    if ($typed === "") return "";
    //Currency symbols, letters (GBP, USD) and spaces of any kind - but something typed with no digits in it isn't an amount
    $value = preg_replace('/[^\d.,\-]/u', '', $typed);
    if (!preg_match('/\d/', $value)) return false;
    if (strpos($value, "-") !== false) return false;
    if (strpos($value, ",") !== false and strpos($value, ".") !== false) $value = str_replace(",", "", $value);
    elseif (strpos($value, ",") !== false) $value = preg_match('/^\d{1,3}(,\d{3})+$/', $value) ? str_replace(",", "", $value) : str_replace(",", ".", $value);
    if (!preg_match('/^\d+(\.\d+)?$/', $value) and !preg_match('/^\.\d+$/', $value)) return false;
    return $value[0] === "." ? "0" . $value : $value;
}

/** An amount as typed, in minor units of the currency - null if nothing was typed, false if it isn't an amount */
function moneyInputParse($value, $currency)
{
    $decimal = moneyInputNormalise($value);
    if ($decimal === false) return false;
    if ($decimal === "") return null;
    //More decimal places than the currency has are rounded, as a price typed as 9.999 would be
    $places = (new ISOCurrencies())->subunitFor(new \Money\Currency($currency));
    $decimal = number_format(round(floatval($decimal), $places), $places, ".", "");
    return (new DecimalMoneyParser(new ISOCurrencies()))->parse($decimal, $currency)->getAmount();
}
