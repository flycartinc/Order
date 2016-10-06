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

use Illuminate\Database\Eloquent\Collection;
use cartRabbit\Models\Price;

/**
 * Class OrderItem
 * @package Flycartinc\Order\Model
 */
class OrderItem extends BaseModel implements OrderItemInterface
{

    /**
     * @var string
     */
    protected $table = 'cartrabbit_order_items';

    /**
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * @var array
     */
    protected $fillable = array(
        'order_item_type',
        'order_id',
        'order_item_name',
        'product_id'
    );
    /**
     * @var array
     */
    protected $with = ['meta'];

    /**
     * @var bool
     */
    public $timestamps = true;

    /**
     * @var array|Collection
     */
    protected $item;

    /**
     * @var
     */
    protected $order_item_id;


    /**
     * OrderItem constructor.
     * @param array $attributes
     */
    public function __construct($attributes = array())
    {
        $this->item = new Collection();
        parent::__construct($attributes);
    }

    /**
     * @param $order_item_id
     */
    public function setOrderItemID($order_item_id)
    {
        $this->order_item_id = $order_item_id;
    }

    /**
     * @return mixed
     */
    public function getOrderItemID()
    {
        if (is_null($this->order_item_id)) {
            if (isset($this->item)) {
                $this->order_item_id = $this->item->product_id;
            }
        }
        return $this->order_item_id;
    }

    /**
     * @param bool $ref
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function order($ref = false)
    {

        return $this->belongsTo('Flycartinc\Order\Model\Order', 'order_id', 'id');
    }

    /**
     * @param $itemMeta
     */
    public function setItem($item)
    {
        $this->item = (object)$item;
    }

    /**
     * @return array|Collection
     */
    public function getItem()
    {
        return $this->item;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function meta()
    {
        return $this->hasMany('Flycartinc\Order\Model\OrderItemMeta', 'order_item_id');
    }

    /**
     *
     */
    public function getQuantity()
    {
        return (int)$this->orderitem_quantity;
    }

    public function setQuantity($quantity)
    {
        $this->orderitem_quantity = $quantity;
    }

    public function getMetaAttribute()
    {
        return (object)$this->meta()->pluck('meta_value', 'meta_key')->toArray();
    }

    /**
     *
     */
    public function getTotal()
    {
        return $this->getLineItemPrice();
    }

    /**
     *
     */
    public function getUnitPrice()
    {
        dd($this->meta);
        return $this->item->regular_price;
    }

    /**
     * @param int $unitPrice
     */
    public function setUnitPrice($unitPrice)
    {
        $this->item->regular_price = $unitPrice;
    }

    /**
     *
     */
    public function getBasePrice()
    {
        $option_price = $this->getOptionPrice();
        return $this->base_price + $option_price;
    }

    /**
     * @return int
     */
    public function getOptionPrice()
    {
        return 0;
    }

    /**
     * @return mixed
     */
    public function getSpecialPrice()
    {
        return $this->item->org_price;
    }

    public function getTaxProfile()
    {

        return $this->taxprofile_id;
    }

    /**
     *
     */
    public function getLineItemPrice()
    {
        return $this->getBasePrice() * $this->getQuantity();
    }

    public function prepareItem($cartitem)
    {


    }

}
