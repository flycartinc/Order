<?php

namespace Flycartinc\Order\Model;

use Illuminate\Database\Eloquent\Model as Eloquent;

/**
 * Class products
 * @package StorePress\Models
 */
class TaxProfile extends Eloquent
{
    /**
     * To Get Tax Rates in based on Store's base Address
     *
     * @param integer $value Amount of Tax
     * @param string $tax_profile Profile that assigned for the store's tax
     * @param array $taxes Available taxes that are collected from store's tax profile
     * @param bool $includes_tax is Include or Not
     * @return array Store's Tax Total
     */
    public function getBaseTaxRates($value, $tax_profile, $taxes, $includes_tax = false)
    {
        $return = array();
        if (!$tax_profile) return array();

        if (isset($taxes)) {
            foreach ($taxes['rates'] as $tax) {
                foreach ($tax->getAmounts() as $amount) {
                    $tax_rate[$amount->getID()]['id'] = $amount->getID();
                    $tax_rate[$amount->getID()]['rate'] = $amount->getAmount();
                }
            }
        }
        $taxrates_items[] = $this->getTaxRateItems($tax_profile);

        // If tax rates of the items are empty
        if (empty($taxrates_items[0])) return array();

        if ($includes_tax) {
            $total = 0;
            foreach ($taxrates_items as $rate) {

                if ($rate->getAmount() > 0) {
                    $divider = 1 + ($rate->getAmount() / 100);
                    $amount = $value - ($value / $divider);
//                    dd($rate->getAmount());
                    $total += $amount;
                    $return[$rate->getID()]['id'] = $rate->getID();
                    $return[$rate->getID()]['type'] = $tax_profile;
                    $return[$rate->getID()]['rate'] = $rate->getAmount();
                    $return[$rate->getID()]['amount'] = $amount;
                }
            }
            $taxtotal = $total;
        } else {
            $total = 0;
            $tax_rates = $this->getTaxRates($value, $tax_rate);
            foreach ($tax_rates as $tax_rate) {
                $return[$tax_rate['taxrate_id']]['rate'] = $tax_rate['rate'];
                $return[$tax_rate['taxrate_id']]['amount'] = $tax_rate['amount'];
                $total += $tax_rate['amount'];
            }
            $taxtotal = $total;
        }

        $result = array();
        $result['taxes'] = $return;
        $result['taxtotal'] = $taxtotal;

        return $result;
    }

    /**
     * To Get the taxes of the items
     *
     * @param integer $value Amount of an item
     * @param array $tax_rates available tax rates
     * @return array Reordered tax rate and tax of the item
     */
    public function getTaxRates($value, $tax_rates)
    {
        $tax_rate_data = array();

        if (is_null($tax_rates)) return array();

        foreach ($tax_rates as $tax_rate) {
            if (isset($tax_rate_data[$tax_rate['id']])) {
                $amount = $tax_rate_data[$tax_rate['id']]['amount'];
            } else {
                $amount = 0;
            }

            $divider = 1 + ($tax_rate['rate'] / 100);

            $amount += $value - ($value / $divider);

            $tax_rate_data[$tax_rate['id']] = array(
                'id' => $tax_rate['id'],
                'rate' => $tax_rate['rate'],
                'amount' => $amount
            );
        }
        return $tax_rate_data;
    }

    /**
     * To Get tax rates that are suitable with the given tax profiles
     *
     * @param string $tax_profile Given tax profile
     * @param $tax_rates Available tax rates
     * @return array of tax rates with suitable of given tax profile
     */
    public function getTaxRatesFromProfile($tax_profile, $tax_rates)
    {
        foreach ($tax_rates as $rate) {
            if ($tax_profile == $rate->getName()) {
                foreach ($rate->getAmounts() as $amount) {
                    $tax_profile_rate[$amount->getID()]['taxrate_id'] = $amount->getID();
                    $tax_profile_rate[$amount->getID()]['rate'] = $amount->getAmount();
                    $tax_profile_rate[$amount->getID()]['amount'] += $amount->getAmount();
                }
            }
        }
        return $tax_profile_rate;
    }

    /**
     * To Get Available Tax rates that are available with the given tax profile
     *
     * @param $tax_profile
     * @return array
     */
    public function getTaxRateItems($tax_profile)
    {
        $taxes = (new Tax())->processTax();

        //If no more taxes are available for the given tax profile
        if (empty($taxes)) return array();

        $rates = array();
        foreach ($taxes['rates'] as $tax) {
            if ($tax->getName() == $tax_profile) {
                $rates = $tax->getAmount();
            }
        }
        return $rates;
    }

    /**
     * To Get Tax total of an item
     *
     * @param integer $value Amount of Tax
     * @param string $tax_profile Profile that assigned for the items's tax
     * @param array $taxes Available taxes that are collected from items's tax profile
     * @param bool $includes_tax is Include or Not
     * @return array items's Tax Total
     */
    public function getTaxWithRates($value, $tax_profile, $taxes, $includes_tax = false)
    {
        $return = array();
        if (!isset($tax_profile)) return $return;

        //To Get the Rate of given tax profile
        $rates = $this->getRates($tax_profile);
        $taxtotal = 0;

        if ($includes_tax) {
            $total = 0;
            //If No more rates are available for the given tax profile
            if ($rates == null) return array();

            //Get Rates of the Tax Profile
            foreach ($rates as $rate) {
                if ($rate['rate'] > 0) {
                    $divider = 1 + ($rate['rate'] / 100);

                    $amount = $value - ($value / $divider);

                    $total += $amount;
                    $return[$rate['id']]['type'] = $tax_profile;
                    $return[$rate['id']]['rate'] = $rate['rate'];
                    $return[$rate['id']]['amount'] = $amount;
                }
            }
            $taxtotal = $total;
        } else {
            $total = 0;
            $tax_rates = $this->getTaxRates($value, $rates);
            foreach ($tax_rates as $tax_rate) {
                $return[$tax_rate['id']]['type'] = $tax_profile;
                $return[$tax_rate['id']]['rate'] = $tax_rate['rate'];
                $return[$tax_rate['id']]['amount'] = $tax_rate['amount'];
                $total += $tax_rate['amount'];
            }
            $taxtotal = $total;

        }
        $item = array();
        $item['taxes'] = $return;
        $item['taxtotal'] = $taxtotal;

        return $item;
    }

    /**
     *To Get Tax Rates based on the tax profile
     *
     * @param $tax_profile
     * @return array List of tax profile's tax rates
     */
    public function getRates($tax_profile)
    {
        $rates = (new Tax())->processTax();
        foreach ($rates['rates'] as $rate) {
            if ($rate->getName() == $tax_profile) {
                foreach ($rate->getAmounts() as $amount) {
                    $tax_rate[$amount->getID()] = array(
                        'id' => $amount->getID(),
                        'rate' => $amount->getAmount()
                    );
                }
            }
        }
        return $tax_rate;
    }
}