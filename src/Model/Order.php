<?php

/**
 * Order management package for StorePress
 *
 * (c) Ramesh Elamathi <ramesh@flycart.org>
 * For the full copyright and license information, please view the LICENSE file
 * that was distribute as a part of the source code
 *
 */

namespace FlycartInc\Order\Models;

class Order extends Model implements OrderInterface {

	/** @var array */
	protected static $postTypes = [];

	protected $table = 'storepress_orders';

	protected $fillable = array(
		'order_user_id',
		'order_mail'
	);

	public function __construct(array $attributes = [])
	{
		foreach ($this->fillable as $field) {
			if (!isset($attributes[$field])) {
				$attributes[$field] = '';
			}
		}

		parent::__construct($attributes);
	}

	public function items(){
		return $this->hasMany('Flycartinc\Order\Models\OrderItem', 'order_id', 'order_id');
	}


	public function meta()
	{
		return $this->hasMany('Flycartinc\Order\Models\OrderMeta', 'order_id', 'order_id');
	}

	public function getItems() {

		return $this->items();
	}

	public function setItems() {
		// TODO: Implement setItems() method.
	}



}