<?php
/**
 * Order management package for StorePress
 *
 * (c) Ramesh Elamathi <ramesh@flycart.org>
 * For the full copyright and license information, please view the LICENSE file
 * that was distribute as a part of the source code
 *
 */

namespace FlycartInc\Order\Model;

interface OrderInterface {

	public function setItems();

	public function getItems();

/*	public function setId();

	public function getId();

	public function getItemsCount();

	public function addItem(OrderitemInterface $item);

	public function removeItem(OrderitemInterface $item);

	public function hasItem(OrderitemInterface $item);

	public function getTotal();

	public function recalculateTotal();

	public function getItemsTotal();

	public function getSubtotal();

	public function clearItems();

	public function isEmpty();*/




}