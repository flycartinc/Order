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
use StorePress\Models\Tax;
use StorePress\Models\Settings;

class Order extends BaseModel
{

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

    protected $line_subtotal;

    protected $line_subtotal_ex_tax;

    protected $taxrates;

    protected $subtotal;

    protected $total_cost;

    protected $total_tax;

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

    public function items()
    {
        return $this->hasMany('Flycartinc\Order\Model\OrderItem', 'order_id', 'id');
    }


    public function meta()
    {
        return $this->hasMany('Flycartinc\Order\Model\OrderMeta', 'order_id');
    }

    public function getItems($type = 'lineitem')
    {
        if ($this->isValidType($type)) {
            return $this->order_items->where('order_item_type', $type);
        }
        return $this->order_items;
    }

    public function getItemByID($product_id)
    {
        if ($product_id) {
            return $this->order_items->where('product_id', $product_id);
        }
    }

    public function getItemsCount()
    {
        return $this->order_items->count();
    }

    public function setItems($items)
    {
        if (isset($items)) {
            $this->order_items = $items;
        }

    }

    public function getItemMetaByID($product_id)
    {
        $meta = $this->order_items->where('product_id', $product_id)->first();
        return $meta['items'][0];
    }

    public function addItem(OrderItemInterface $item)
    {
        if ($this->hasItem($item)) {
            return false;
        }
        $this->order_items->push($item);
//		$this->recalculateTotal();
        return $this;
    }

    public function hasItem(OrderItemInterface $item)
    {
        return $this->order_items->contains($item);
    }

    public function calculateTotals()
    {
        //this is where the real calculation takes place.

        //first get the subtotals done
        $this->calculateSubtotal();

        return $this->getTaxDetails();

    }

    public function calculateSubtotal()
    {
        //initialise the primary values

        $line_subtotal = 0;
        $line_subtotal_tax = 0;

        //get the line items
        $items = $this->getItems('lineitem');
        $config = self::basicConfigSetup();
        $taxModel = new TaxProfile();

        if (empty($items)) return array();
        foreach ($items as $item) {
            $orderItem = new OrderItem();
            $orderItem->setItem($item->meta->product_meta);
            $taxProfile = $orderItem->getTaxProfile();
            $product_id = $orderItem->getOrderItemID();
            $line_price = $orderItem->getLineItemPrice();
            if (!isset($taxProfile)) {
                $this->line_subtotal += $line_price;
                $this->line_subtotal_ex_tax += $line_price;
                /**
                 * Tax Calculation [Inclusive of Tax]
                 *
                 * e.g. $100 bike with $10 coupon = customer pays $90 and tax worked backwards from that
                 */
            } elseif (isset($taxProfile) && $config['displaySetup'] == 'includeTax') {
                $shop_taxrate = $taxModel->getBaseTaxRates($line_price, $taxProfile, $config['shop_tax'], 1);
                $item_taxrate = $taxModel->getTaxWithRates($line_price, $taxProfile, $config['item_tax'], 1);

                /** Adjust the Tax when the base tax is not equal to the item tax */
                if ($shop_taxrate['taxtotal'] !== $item_taxrate['taxtotal']) {
                    $this->taxrates = $item_taxrate;

                    /** New Item price with Ex.Tax */
                    $line_subtotal = $line_price - $item_taxrate['taxtotal'];
                    /** Modified tax rates */
                    $modified_tax = $taxModel->getTaxWithRates($line_subtotal, $taxProfile, $config['item_tax']['rates'][0], 1);
                    $line_subtotal_tax = $modified_tax['taxtotal'];
                } else {
                    $this->taxrates = $shop_taxrate;
                    $line_subtotal = $line_price - $item_taxrate['taxtotal'];
                    $line_subtotal_tax = $item_taxrate['taxtotal'];
                }
                /** Tax Calculation [Exclusive of Tax] */
            } else {
                /** Price Exclude Tax
                 *
                 * This will work with base, untaxed price
                 */

                $item_ex_taxrate = (new TaxProfile())->getTaxWithRates($line_price, $taxProfile, $config['item_tax']['rates'], 0);
                $this->taxrates = $item_ex_taxrate;
                $line_subtotal_tax = $item_ex_taxrate['taxtotal'];
                $line_subtotal = $line_price - $line_subtotal_tax;
            }
            $this->total_tax += $line_subtotal_tax;
            $this->subtotal += $line_subtotal + $line_subtotal_tax;
            $this->subtotal_ex_tax += $line_subtotal;
        }
        $this->taxrates['taxtotal'] = $this->total_tax;

        $this->checkDisplayType($config);

        /** Calculate actual totals for items */

        $this->processDiscountPrice();
    }


    public static function basicConfigSetup()
    {
        $config['displaySetup'] = parent::getMetaOf('storepress_tax', 'displayPriceDuringCart');

        self::TaxProfiles($config);

        return $config;
    }

    public function checkDisplayType($config)
    {
        $tax_config = $config['displaySetup'];

        if ($tax_config == 'includeTax') {
            $this->subtotal = $this->subtotal + $this->total_tax;
            $this->total_cost = $this->subtotal;
        } elseif ($tax_config == 'excludeTax') {
            $this->total_cost = $this->subtotal + $this->total_tax;
        }
    }

    /**
     * To Process the discount price of the product
     *
     */
    public function processDiscountPrice()
    {
        //
    }


    public static function TaxProfiles(&$config)
    {
        $tax = new \Flycartinc\Order\Model\Tax();
        //Get the Tax Profile Only for Store
        $config['shop_tax'] = $tax->processTax($isStore = true);

        //Get the Tax profile for Store and Customer
        $config['item_tax'] = $tax->processTax();

    }

    public function getTaxDetails()
    {
        $tax['subtotal'] = $this->subtotal;
        $tax['tax'] = $this->total_tax;
        $tax['total'] = $this->total_cost;
        $tax['rates'] = $this->taxrates;
        return $tax;
    }

    public function isValidType($type)
    {

        $allowed = array('lineitem', 'tax', 'shipping');
        $result = in_array($type, $allowed);
        return $result;
    }


}