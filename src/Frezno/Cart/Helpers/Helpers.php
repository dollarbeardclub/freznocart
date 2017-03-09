<?php
namespace Frezno\Cart\Helpers;

class Helpers
{
    /**
     * Normalize price.
     *
     * @param $price
     *
     * @return float
     */
    public static function normalizePrice($price)
    {
        return (is_string($price)) ? floatval($price) : $price;
    }

    /**
     * Check if the array is a multi dimensional array.
     *
     * This will only check the first element of the array if it is still an
     * array to decide that it is multi dimensional.
     * If you want to check the array strictly with all on its element,
     * flag the second argument as true.
     *
     * @param $array
     * @param bool $recursive
     *
     * @return bool
     */
    public static function isMultiArray($array, $recursive = false)
    {
        if ($recursive) {
            return (count($array) == count($array, COUNT_RECURSIVE)) ? false : true;
        } else {
            foreach ($array as $k => $v) {
                if (is_array($v)) {
                    return true;
                } else {
                    return false;
                }
            }
        }
    }

    /**
     * Check if variable is set and has value. Return a default value.
     *
     * @param $var
     * @param bool|mixed $default
     *
     * @return bool|mixed
     */
    public static function issetAndHasValueOrAssignDefault(&$var, $default = false)
    {
        if ((isset($var)) && ($var != '')) {
            return $var;
        }

        return $default;
    }

    /**
     * Format price.
     *
     * Format the price according to the settings in the config file.
     *
     * @param $value  the price
     * @param bool $format_numbers  using the formatting values or not
     * @param $config  array values
     *
     * @return mixed
     */
    public static function formatValue($value, $format_numbers, $config)
    {
        if ($format_numbers && $config['format_numbers']) {
            return number_format($value, $config['decimals'], $config['dec_point'], $config['thousands_sep']);
        } else {
            return $value;
        }
    }
}
