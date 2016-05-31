<?php
/**
 * Order management package for StorePress
 *
 * (c) Ramesh Elamathi <ramesh@flycart.org>
 * For the full copyright and license information, please view the LICENSE file
 * that was distribute as a part of the source code
 *
 */

namespace Flycartinc\Order\Model;

class OrderItem extends BaseModel implements OrderItemInterface {

	protected $table = 'storepress_order_items';

	protected $primaryKey = 'id';

	protected $fillable = array(
		'order_item_type',
		'order_id'

	);
	protected $with = ['meta'];

	public $timestamps = true;


	public function order($ref = false) {

		return $this->belongsTo('Flycartinc\Order\Model\Order', 'order_id', 'id');
	}


	public function meta()
	{
		return $this->hasMany('Flycartinc\Order\Model\OrderItemMeta', 'order_item_id');
	}

	public function getQuantity() {
		// TODO: Implement getQuantity() method.
	}

	public function getTotal() {
		// TODO: Implement getTotal() method.
	}


	public function getUnitPrice() {
		// TODO: Implement getUnitPrice() method.
	}

	public function setUnitPrice( $unitPrice ) {
		// TODO: Implement setUnitPrice() method.
	}

	public function getBasePrice() {
		$option_price = $this->getOptionPrice();
		$this->item->price + $option_price;
	}

	public function getOptionPrice() {
		return 0;
	}

	public function getLineItemPrice() {
		return $this->getBasePrice() * $this->getQuantity();
	}

}