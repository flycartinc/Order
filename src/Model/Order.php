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

use Illuminate\Support\Collection;

class Order extends BaseModel  {

	/** @var array */
	protected static $postTypes = [];

	protected $table = 'storepress_orders';

	protected $primaryKey = 'id';

	protected $fillable = array(
		'order_user_id',
		'order_mail'
	);

	protected $with = ['meta'];

	public $timestamps = true;

	public $order_items = array();

	public function __construct(array $attributes = [])
	{
		foreach ($this->fillable as $field) {
			if (!isset($attributes[$field])) {
				$attributes[$field] = '';
			}
		}

		$this->order_items = new Collection();

		parent::__construct($attributes);
	}

	public function items(){
		return $this->hasMany('Flycartinc\Order\Model\OrderItem', 'order_id', 'id');
	}


	public function meta()
	{
		return $this->hasMany('Flycartinc\Order\Model\OrderMeta', 'order_id');
	}

	public function getItems($type = 'lineitem') {
		if($this->isValidType($type)) {
			return $this->items->where('order_item_type', $type);
		}
		return $this->items;
	}

	public function getItemsCount() {

		return $this->items()->count();
	}

	public function setItems(Collection $items) {
	//TODO improve this method
			$this->items = $items;
	}

	public function addItem(OrderItemInterface $item)
	{
		if ($this->hasItem($item)) {
			return;
		}
		$this->items->push($item);
	//	$item->setOrder($this);
//		$this->recalculateTotal();

		return $this;
	}

	public function hasItem(OrderItemInterface $item)
	{
		return $this->items->contains($item);
	}

	public function calculateTotals() {
		//this is where the real calculation takes place.

		//first get the subtotals done
		$this->calculateSubtotal();

	}

	public function calculateSubtotal() {

		//initialise the primary values
		$subtotal = 0;
		$subtotal_ex_tax = 0;

		//get the line items
		$items = $this->getItems('lineitem');
		foreach($items as $item) {

			$base_price = $item->getBasePrice();
			$line_price = $item->getLineItemPrice();




		}

	}

	public function isValidType($type) {

		$allowed = array('lineitem', 'tax', 'shipping');
		$result = in_array($type, $allowed);
		return $result;
	}





}