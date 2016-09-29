<?php
/**
 * Order management package for CartRabbit
 *
 * (c) Ramesh Elamathi <ramesh@flycart.org>
 * For the full copyright and license information, please view the LICENSE file
 * that was distribute as a part of the source code
 *
 */

namespace Flycartinc\Order\Model;

interface OrderItemInterface {

	/**
	 * @return int
	 */
	public function getQuantity();
	/**
	 * @return int
	 */
	public function getUnitPrice();
	/**
	 * @param int $unitPrice
	 */
	public function setUnitPrice($unitPrice);
	/**
	 * @return int
	 */
	public function getTotal();

}
