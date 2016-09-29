<?php

namespace Flycartinc\Order\Model;

use CommerceGuys\Tax\TaxableInterface;
use Illuminate\Support\Collection;


/**
 * Class Fee
 * @package CartRabbit\Models
 */
class Fee extends Collection implements TaxableInterface
{

	/**
	 * @var
	 */
	var $id;

	/**
	 * @var
	 */
	var $name;

	/**
	 * @var
	 */
	var $amount;

	/**
	 * @var
	 */
	var $taxClass;

	/**
	 * @var
	 */
	var $taxable;

	/**
	 * @var
	 */
	var $tax;

	/**
	 * @var
	 */
	var $tax_data;

	/**
	 * @var array
	 */
	var $fees = array();

	/**
	 * Fee constructor.
	 * @param array $attributes
	 */
	public function __construct($attributes = array())
	{
		parent::__construct($attributes);
	}

	/**
	 * @return bool
	 */
	public function isPhysical()
	{
		// TODO: Implement isPhysical() method.
		return false;
	}


	/**
	 * @return mixed
	 */
	public function getTaxClass()
	{
		return $this->taxClass;
	}

	/**
	 * @param $name
	 * @param $amount
	 * @param bool $taxable
	 * @param string $tax_class
	 */
	public function addFee($name, $amount, $taxable = false, $tax_class = '')
	{

		$new_fee_id = $name;

		// Only add each fee once
		foreach ($this->fees as $fee) {
			if ($fee->id == $new_fee_id) {
				return;
			}
		}

		$fee = clone $this;

		$fee->id = $new_fee_id;
		$fee->name = esc_attr($name);
		$fee->amount = (float)esc_attr($amount);
		$fee->taxClass = $tax_class;
		$fee->taxable = $taxable ? true : false;
		$fee->tax = 0;
		$fee->tax_data = array();
		$this->fees[] = $fee;
	}

	/**
	 * @return array
	 */
	public function getFees()
	{
		return array_filter((array)$this->fees);
	}


}
