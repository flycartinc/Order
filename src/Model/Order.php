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

use Carbon\Carbon;
use CartRabbit\Helper\SPCache;
use CartRabbit\Helper\Util;
use CommerceGuys\Tax\Model\TaxRateAmount;
use Herbert\Framework\Notifier;
use Illuminate\Support\Collection;
use Flycartinc\Cart\Cart;
use CartRabbit\Helper\Currency;
use CartRabbit\Models\Customer;
use CartRabbit\Models\OrderMeta;
use CartRabbit\Models\ProductInterface;
use CartRabbit\Models\Settings;
use CartRabbit\Models\Shipping;
use CartRabbit\Models\Tax;

/**
 * Class Order
 * @package Flycartinc\Order\Model
 */
class Order extends BaseModel
{
    /** @var array */
    protected static $postTypes = [];
    /**
     * @var string
     */
    protected $table = 'cartrabbit_orders';
    /**
     * @var string
     */
    protected $primaryKey = 'id';
    /**
     * @var array
     */
    protected $fillable = array(
        'order_user_id',
        'order_mail'
    );
    /**
     * @var array
     */
    protected $with = ['meta'];
    /**
     * @var bool
     */
    public $timestamps = true;
    /** @var array Contains an array of cart items. */
    public $cart_contents = array();
    /** @var float The total cost of the cart items. */
    public $cart_contents_total;
    /**
     * @var array
     */
    public $order_items = array();
    /**
     * @var int
     */
    public $cart_item_quantity = 0;
    /** @var float Cart grand total. */
    public $total;
    /** @var float Cart subtotal. */
    public $subtotal;
    /** @var float Cart subtotal without tax. */
    public $subtotal_ex_tax;
    /** @var float Total cart tax. */
    public $tax_total;
    /** @var array An array of taxes/tax rates for the cart. */
    public $taxes = array();
    /** @var array An array of taxes/tax rates for the shipping. */
    public $shipping_taxes = array();

    /**
     * @var array
     */
    public $shipping_rates = array();
    /** @var array Holding the Shipping Info. */
    public $shipping_info = array();
    /** @var float Discount amount before tax */
    public $discount_cart;
    /** @var float Discounted tax amount. Used predominantly for displaying tax inclusive prices correctly */
    public $discount_cart_tax;
    /** @var float Total for additional fees. */
    public $fee_total;
    /** @var float Shipping cost. */
    public $shipping_total;
    /** @var bool Represents the Cart Status */
    public $cart_status = true;
    /** @var float Shipping tax. */
    public $shipping_tax_total;
    /** @var array cart_session_data. Array of data the cart calculates and stores in the session with defaults */
    public $cart_session_data = array(
        'cart_contents_total' => 0,
        'total' => 0,
        'subtotal' => 0,
        'subtotal_ex_tax' => 0,
        'tax_total' => 0,
        'taxes' => array(),
        'shipping_taxes' => array(),
        'discount_cart' => 0,
        'discount_cart_tax' => 0,
        'shipping_total' => 0,
        'shipping_tax_total' => 0,
        'coupon_discount_amounts' => array(),
        'coupon_discount_tax_amounts' => array(),
        'fee_total' => 0,
        'fees' => array()
    );
    /**
     * An array of fees.
     *
     * @var array
     */
    public $fees = array();
    /**
     * Prices include tax.
     *
     * @var bool
     */
    public $prices_include_tax;
    /**
     * Round at subtotal.
     *
     * @var bool
     */
    public $round_at_subtotal;
    /**
     * Tax display cart.
     *
     * @var string
     */
    public $tax_display_cart;
    /**
     * @var array
     */
    public $items = array();
    /**
     * @var
     */
    protected $line_subtotal;
    /**
     * @var
     */
    protected $line_subtotal_ex_tax;
    /**
     * @var
     */
    protected $taxrates;
    /**
     * @var
     */
    protected $total_cost;
    /**
     * @var
     */
    protected $total_tax;
    /**
     * @var array
     */
    protected $params = array();
    /**
     * @var
     */
    protected $taxmodel;
    /**
     * @var
     */
    protected $order_id;
    /**
     * @var
     */
    private $transaction_id;
    /**
     * @var
     */
    private $transaction_data;

    /**
     * Order constructor.
     * @param array $attributes
     */
    public function __construct(array $attributes = [])
    {
        foreach ($this->fillable as $field) {
            if (!isset($attributes[$field])) {
                $attributes[$field] = '';
            }
        }
        parent::__construct($attributes);
        $this->prices_include_tax = Settings::pricesIncludeTax();
        $this->tax_display_cart = Settings::get('tax_display_price_during_cart', 'no');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function items()
    {
        return $this->hasMany('Flycartinc\Order\Model\OrderItem', 'order_id', 'id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function meta()
    {
        return $this->hasMany('Flycartinc\Order\Model\OrderMeta', 'order_id', 'id');
    }

    /**
     * @param mixed $order_id
     */
    public function setOrderId($order_id)
    {
        $this->order_id = $order_id;
    }

    /**
     * @param mixed $transaction_data
     */
    public function setTransactionData($transaction_data)
    {
        $this->transaction_data = $transaction_data;
    }

    /**
     * @param mixed $transaction_id
     */
    public function setTransactionId($transaction_id)
    {
        $this->transaction_id = $transaction_id;
    }

    /**
     * @param bool $status
     */
    public function paymentComplete($status = false)
    {
        if ($status == false) {
            $status = 'completed';
        }
        if ($this->order_id) {
            if ($this->order_status != 'completed') {
                $this->updateOrderStatus($status);
                do_action('send_complete_order_mail');
            }
        }
    }

    /**
     * @return object
     */
    public function getMetaAttribute()
    {
        return (object)$this->meta()->pluck('meta_value', 'meta_key')->toArray();
    }

    /**
     * @param $status
     */
    public function updateOrderStatus($status, $is_row_id = false)
    {
        $field = 'unique_order_id';
        if ($is_row_id) {
            $field = 'id';
            $order_id = Util::extractDataFromHTTP('order_id');
        } else {
            $order_id = $this->order_id;
        }
        /** Clearing Cache */
        SPCache::forget('order_single_' . $this->order_id);
        SPCache::forget('order_list');

        //TODO: Improve this Process
        $order = Order::where($field, $order_id)->get()->first();
        $order->order_status = $status;
        $order->save();

        if ($this->order_id and $status != 'completed') {
            do_action('send_confirm_order_mail', $status);
        }

        $this->saveTransaction();
        self::emptyCart();
    }

    /**
     *
     */
    public static function emptyCart()
    {
        /** For Clear Cart */
        Cart::destroy_cart();
    }

    /**
     * @return bool
     */
    public function saveTransaction()
    {
        if (empty($this->transaction_id)) return false;
        $transaction_id = $this->transaction_id;
        $transaction_data = $this->transaction_data;
        $orderMeta = new OrderMeta();
        $orderMeta->order_id = $this->order_id;
        $orderMeta->meta_key = 'transaction_id';
        $orderMeta->meta_value = $transaction_id;
        $orderMeta->save();
        $orderMeta = new OrderMeta();
        $orderMeta->order_id = $this->order_id;
        $orderMeta->meta_key = 'transaction_data';
        $orderMeta->meta_value = $transaction_data;
        $orderMeta->save();
    }

    /**
     * @return $this
     */
    public function initOrder()
    {
        $order_items = $this->getCart();
        foreach ($order_items as $index => &$item) {
            $this->cart_item_quantity += $item['quantity'];

            $item['line_price'] = $this->getLineItemPrice($item['product'], $item['quantity']);
            $item['line_final_total'] = $this->getLineItemSubtotal($item['product'], $item['quantity']);
            $item['product']->processProduct(false);
            $item['product']->setRelation('meta', $item['product']->meta->pluck('meta_value', 'meta_key'));
        }
        /** To Verify the Cart status. */
        $this->validateCart();
    }

    /**
     * @return bool
     */
    public function vertifyStock()
    {
        //TODO: Improve this.
        return true;
    }

    /**
     *
     */
    public function validateCart()
    {
        if (is_array($this->cart_contents)) {
            if (count($this->cart_contents)) {
                $this->cart_status = true;
            }
        } else {
            $this->cart_status = false;
        }
    }

    /**
     * @param string $type
     * @return array
     */
    public function getItems($type = 'lineitem')
    {
        if ($this->isValidType($type)) {
            return $this->items->where('order_item_type', $type);
        }
        return $this->items;
    }

    /**
     * @param $product_id
     * @return array
     */
    public function getItemByID($product_id)
    {
        if ($product_id) {
            return $this->items->where('product_id', $product_id);
        }
        return array();
    }

    /**
     * @return mixed
     */
    public function getItemsCount()
    {
        return $this->items->count();
    }

    /**
     * @param $items
     */
    public function setItems($items)
    {
        if (isset($items)) {
            $this->items = $items;
        }
    }

    /**
     * @param $product_id
     * @return mixed
     */
    public function getItemMetaByID($product_id)
    {
        $meta = $this->items->where('product_id', $product_id)->first();
        return $meta['items'][0];
    }

    /**
     * @param OrderItemInterface $item
     * @return $this|bool
     */
    public function addItem(OrderItemInterface $item)
    {
        if ($this->hasItem($item)) {
            return false;
        }
        $this->items->push($item);
//		$this->recalculateTotal();
        return $this;
    }

    /**
     * @param OrderItemInterface $item
     * @return mixed
     */
    public function hasItem(OrderItemInterface $item)
    {
        return $this->items->contains($item);
    }

    /**
     *
     */
    public function reset()
    {
        //TODO reset the order object so that each time a clean object is presented.
    }

    /**
     *
     */
    public function setSession()
    {
    }

    /**
     * Looks through cart items and checks the posts are not trashed or deleted.
     *
     * @return bool|Notifier
     */
    public function checkCartItemValidity()
    {
        $return = true;
        foreach ($this->getCart() as $cart_item_key => $cartitem) {
            $product = $cartitem->get('product');
            if (!$product || !$product->exists() || 'trash' === $product->getStatus()) {
                $this->setQuantity($cart_item_key, 0);
                $return = Notifier::notify('An item which is no longer available was removed from your cart.');
            }
        }
        return $return;
    }

    /**
     * @return $this
     */
    public function calculateTotals()
    {
        //this is where the real calculation takes place.
        $this->reset();
        //TODO: Verify this
//        do_action('cartrabbit_before_calculate_totals', $this);
        if ($this->isEmpty()) {
            $this->setSession();
        }

        $tax_rates = array();
        $shop_tax_rates = array();
        $taxModel = new Tax();
        $cartitems = $this->getCart();
        if (empty($cartitems) or (!is_array($cartitems) and !is_object($cartitems))) return array();
        /**
         * Calculate subtotals for items. This is done first so that discount logic can use the values.
         */
        foreach ($cartitems as $cart_item_key => $cartitem) {

            $product = $cartitem->getProduct();
            $pricing = $product->getPrice($cartitem->getQuantity());
            $line_price = $pricing->price;
            $line_price = $line_price * $cartitem->getQuantity();
            $line_subtotal = 0;
            $line_subtotal_tax = 0;
            /**
             * No tax to calculate.
             */
            if (!$product->isTaxable()) {
                // Subtotal is the undiscounted price
                $this->subtotal += $line_price;
                $this->subtotal_ex_tax += $line_price;
                /**
                 * Prices include tax.
                 *
                 * To prevent rounding issues we need to work with the inclusive price where possible.
                 * otherwise we'll see errors such as when working with a 9.99 inc price, 20% VAT which would.
                 * be 8.325 leading to totals being 1p off.
                 *
                 * Pre tax coupons come off the price the customer thinks they are paying - tax is calculated.
                 * afterwards.
                 *
                 * e.g. $100 bike with $10 coupon = customer pays $90 and tax worked backwards from that.
                 */
            } elseif ($this->prices_include_tax) {
                // Get base tax rates
                if (empty($shop_tax_rates[$product->getTaxClass()])) {
                    $shop_tax_rates[$product->getTaxClass()] = $taxModel->getBaseTaxRates($product);
                }
                // Get item tax rates
                if (empty($tax_rates[$product->getTaxClass()])) {
                    $tax_rates[$product->getTaxClass()] = $taxModel->getItemRates($product);
                }
                $base_tax_rates = $shop_tax_rates[$product->getTaxClass()];
                $item_tax_rates = $tax_rates[$product->getTaxClass()];
                /**
                 * ADJUST TAX - Calculations when base tax is not equal to the item tax.
                 *
                 * The cartrabbit_adjust_non_base_location_prices filter can stop base taxes being taken off when dealing with out of base locations.
                 * e.g. If a product costs 10 including tax, all users will pay 10 regardless of location and taxes.
                 * This feature is experimental @since 2.4.7 and may change in the future. Use at your risk.
                 */
                if ($item_tax_rates !== $base_tax_rates && apply_filters('cartrabbit_adjust_non_base_location_prices', true)) {
                    // Work out a new base price without the shop's base tax
                    $taxes = $taxModel->calculateTax($line_price, $base_tax_rates, true);
                    // Now we have a new item price (excluding TAX)
                    $line_subtotal = $line_price - $taxModel->getTaxTotal($taxes);
                    // Now add modified taxes (The price is excluding tax. See the above line )
                    $tax_result = $taxModel->calculateTax($line_subtotal, $item_tax_rates);
                    $line_subtotal_tax = $taxModel->getTaxTotal($tax_result);
                    /**
                     * Regular tax calculation (customer inside base and the tax class is unmodified.
                     */
                } else {
                    // Calc tax normally
                    $taxes = $taxModel->calculateTax($line_price, $item_tax_rates, true);
                    $line_subtotal_tax = $taxModel->getTaxTotal($taxes);
                    $line_subtotal = $line_price - $taxModel->getTaxTotal($taxes);
                }
                /**
                 * Prices exclude tax.
                 *
                 * This calculation is simpler - work with the base, untaxed price.
                 */
            } else {
                // Get item tax rates
                if (empty($tax_rates[$product->getTaxClass()])) {
                    $tax_rates[$product->getTaxClass()] = $taxModel->getItemRates($product);
                }
                $item_tax_rates = $tax_rates[$product->getTaxClass()];
                // Base tax for line before discount - we will store this in the order data
                $taxes = $taxModel->calculateTax($line_price, $item_tax_rates);
                $line_subtotal_tax = $taxModel->getTaxTotal($taxes);
                $line_subtotal = $line_price;
            }
            // Add to main subtotal
            $this->subtotal += $line_subtotal + $line_subtotal_tax;
            $this->subtotal_ex_tax += $line_subtotal;
        }
        // Order cart items by price so coupon logic is 'fair' for customers and not based on order added to cart.
        uasort($cartitems, array($this, 'sort_by_subtotal'));
        /**
         * Calculate totals for items.
         */
        foreach ($cartitems as $cart_item_key => $cartitem) {
            $product = $cartitem->getProduct();
            $pricing = $product->getPrice($cartitem->getQuantity());
            $base_price = $pricing->price;
            $line_price = $pricing->price * $cartitem->getQuantity();
            // Tax data
            $taxes = array();
            $discounted_taxes = array();
            /**
             * No tax to calculate.
             */
            if (!$product->isTaxable()) {
                // Discounted Price (price with any pre-tax discounts applied)
                $discounted_price = $this->getDiscountedPrice($cartitem, $base_price, true);
                $line_subtotal_tax = 0;
                $line_subtotal = $line_price;
                $line_tax = 0;
                $line_total = $discounted_price * $cartitem->getQuantity();
                /**
                 * Prices include tax.
                 */
            } elseif ($this->prices_include_tax && $product->isTaxable()) {
                $base_tax_rates = $shop_tax_rates[$product->getTaxClass()];
                $item_tax_rates = $tax_rates[$product->getTaxClass()];
                /**
                 * ADJUST TAX - Calculations when base tax is not equal to the item tax.
                 *
                 * The woocommerce_adjust_non_base_location_prices filter can stop base taxes being taken off when dealing with out of base locations.
                 * e.g. If a product costs 10 including tax, all users will pay 10 regardless of location and taxes.
                 * This feature is experimental @since 2.4.7 and may change in the future. Use at your risk.
                 */
                if ($item_tax_rates !== $base_tax_rates && apply_filters('cartrabbit_adjust_non_base_location_prices', true)) {
                    // Work out a new base price without the shop's base tax
                    $taxes = $taxModel->calculateTax($line_price, $base_tax_rates, true);
                    // Now we have a new item price (excluding TAX)
                    $line_subtotal = $line_price - $taxModel->getTaxTotal($taxes);
                    $taxes = $taxModel->calculateTax($line_subtotal, $item_tax_rates);
                    $line_subtotal_tax = $taxModel->getTaxTotal($taxes);
                    // Adjusted price (this is the price including the new tax rate)
                    $adjusted_price = ($line_subtotal + $line_subtotal_tax) / $cartitem->getQuantity();
                    // Apply discounts
                    $discounted_price = $this->getDiscountedPrice($cartitem, $adjusted_price, true);
                    $discounted_taxes = $taxModel->calculateTax($discounted_price * $cartitem->getQuantity(), $item_tax_rates, true);
                    $line_tax = $taxModel->getTaxTotal($discounted_taxes);
                    $line_total = ($discounted_price * $cartitem->getQuantity()) - $line_tax;
                    /**
                     * Regular tax calculation (customer inside base and the tax class is unmodified.
                     */
                } else {
                    // Work out a new base price without the item tax
                    $taxes = $taxModel->calculateTax($line_price, $item_tax_rates, true);
                    // Now we have a new item price (excluding TAX)
                    $line_subtotal = $line_price - $taxModel->getTaxTotal($taxes);
                    $line_subtotal_tax = $taxModel->getTaxTotal($taxes);
                    // Calc prices and tax (discounted)
                    $discounted_price = $this->getDiscountedPrice($cartitem, $base_price, true);
                    $discounted_taxes = $taxModel->calculateTax($discounted_price * $cartitem->getQuantity(), $item_tax_rates, true);
                    $line_tax = $taxModel->getTaxTotal($discounted_taxes);
                    $line_total = ($discounted_price * $cartitem->getQuantity()) - $line_tax;
                }
                // Tax rows - merge the totals we just got
                foreach (array_keys($this->taxes + $discounted_taxes) as $key) {
                    $this->taxes[$key]['amount'] = (isset($discounted_taxes[$key]) ? $discounted_taxes[$key]['amount'] : 0) + (isset($this->taxes[$key]) ? $this->taxes[$key]['amount'] : 0);
                    $this->taxes[$key]['rate'] = (isset($this->taxes[$key]['rate']) ? $this->taxes[$key]['rate'] : $discounted_taxes[$key]['rate']);
                }
                /**
                 * Prices exclude tax.
                 */
            } elseif ($product->isTaxable()) {
                $item_tax_rates = $tax_rates[$product->getTaxClass()];
                // Work out a new base price without the shop's base tax
                $taxes = $taxModel->calculateTax($line_price, $item_tax_rates);
                // Now we have the item price (excluding TAX)
                $line_subtotal = $line_price;
                $line_subtotal_tax = $taxModel->getTaxTotal($taxes);
                // Now calc product rates
                $discounted_price = $this->getDiscountedPrice($cartitem, $base_price, true);
                $discounted_taxes = $taxModel->calculateTax($discounted_price * $cartitem->getQuantity(), $item_tax_rates);
                $discounted_tax_amount = $taxModel->getTaxTotal($discounted_taxes);
                $line_tax = $discounted_tax_amount;
                $line_total = $discounted_price * $cartitem->getQuantity();
                // Tax rows - merge the totals we just got
                foreach (array_keys($this->taxes + $discounted_taxes) as $key) {
                    $this->taxes[$key]['amount'] = (isset($discounted_taxes[$key]) ? $discounted_taxes[$key]['amount'] : 0) + (isset($this->taxes[$key]) ? $this->taxes[$key]['amount'] : 0);
                    $this->taxes[$key]['rate'] = (isset($this->taxes[$key]['rate']) ? $this->taxes[$key]['rate'] : $discounted_taxes[$key]['rate']);
                }
            }
            // Cart contents total is based on discounted prices and is used for the final total calculation
            $this->cart_contents_total += $line_total;
            // Store costs + taxes for lines
            $this->cart_contents[$cart_item_key]['line_total'] = $line_total;
            $this->cart_contents[$cart_item_key]['line_tax'] = $line_tax;
            $this->cart_contents[$cart_item_key]['line_subtotal'] = $line_subtotal;
            $this->cart_contents[$cart_item_key]['line_subtotal_tax'] = $line_subtotal_tax;
            // Store rates ID and costs - Since 2.2
            $this->cart_contents[$cart_item_key]['line_tax_data'] = array('total' => $discounted_taxes, 'subtotal' => $taxes);
        }

        // Only calculate the grand total + shipping if on the cart/checkout
        if (Settings::isCheckoutPage() || Settings::isCartPage()) {
            // Calculate the Shipping
            $this->calculateShipping();
            // Trigger the fees API where developers can add fees to the cart
            $this->calculateFees();
            // Total up/round taxes and shipping taxes
            if ($this->round_at_subtotal) {
                $this->tax_total = $taxModel->getTaxTotal($this->taxes);
                $this->shipping_tax_total = $taxModel->getTaxTotal($this->shipping_taxes);
                $this->taxes = array_map(array($taxModel, 'round'), $this->taxes);
                $this->shipping_taxes = array_map(array($taxModel, 'round'), $this->shipping_taxes);
            } else {
                $this->tax_total = $taxModel->getTaxTotal($this->taxes);
                $this->shipping_tax_total = $taxModel->getTaxTotal($this->shipping_taxes);
            }
            // VAT exemption done at this point - so all totals are correct before exemption
            if ($taxModel->isCustomerVatExcepted()) {
                $this->removeTaxes();
            }
            // Allow plugins to hook and alter totals before final total is calculated
            do_action('cartrabbit_calculate_totals', $this);
            // Grand Total - Discounted product prices, discounted tax, shipping cost + tax
            $total = $this->cart_contents_total + $this->tax_total + $this->shipping_tax_total + $this->shipping_total + $this->fee_total;
            $this->total = max(0, apply_filters('cartrabbit_calculated_total', $total, $this));
        } else {
            // Set tax total to sum of all tax rows
            $this->tax_total = $taxModel->getTaxTotal($this->taxes);
            // VAT exemption done at this point - so all totals are correct before exemption
            if ($taxModel->isCustomerVatExcepted()) {
                $this->removeTaxes();
            }
        }
        do_action('cartrabbit_after_calculate_totals', $this);
        $this->setSession();
        return $this;
    }

    /**
     *
     */
    public function calculateFees()
    {
// Reset fees before calculation
        $this->fee_total = 0;
        $this->fees = (new Fee())->getFees();
        // Fire an action where developers can add their fees
        do_action('cartrabbit_cart_calculate_fees', $this);
        $taxModel = new Tax();
        // If fees were added, total them and calculate tax
        if (!empty($this->fees)) {
            foreach ($this->fees as $fee_key => $fee) {
                $this->fee_total += $fee->amount;
                if ($fee->taxable) {
                    // Get tax rates
                    $tax_rates = $taxModel->getItemRates($fee);
                    $fee_taxes = $taxModel->calculateTax($fee->amount, $tax_rates, false);
                    if (!empty($fee_taxes)) {
                        // Set the tax total for this fee
                        $this->fees[$fee_key]->tax = array_sum($fee_taxes);
                        // Set tax data - Since 2.2
                        $this->fees[$fee_key]->tax_data = $fee_taxes;
                        // Tax rows - merge the totals we just got
                        foreach (array_keys($this->taxes + $fee_taxes) as $key) {
                            $this->taxes[$key] = (isset($fee_taxes[$key]) ? $fee_taxes[$key] : 0) + (isset($this->taxes[$key]) ? $this->taxes[$key] : 0);
                        }
                    }
                }
            }
        }
    }

    /**
     * Add additional fee to the cart.
     *
     * @param string $name Unique name for the fee. Multiple fees of the same name cannot be added.
     * @param float $amount Fee amount.
     * @param bool $taxable (default: false) Is the fee taxable?
     * @param string $tax_class (default: '') The tax class for the fee if taxable. A blank string is standard tax class.
     */
    public function addFee($name, $amount, $taxable = false, $tax_class = '')
    {
        (new Fee())->addFee($name, $amount, $taxable = false, $tax_class = '');
    }

    /**
     *
     */
    public function removeTaxes()
    {
        //unset the taxes.
    }

    /**
     * @param $type
     * @return bool
     */
    public function isValidType($type)
    {
        $allowed = array('lineitem', 'tax', 'shipping');
        $result = in_array($type, $allowed);
        return $result;
    }

    /**
     * @return array|\Illuminate\Database\Eloquent\Collection|mixed
     */
    public function getCart()
    {
        //  $this->cart_contents = Cart::getItems(true, true);
        if (!did_action('sp_cart_loaded_from_session')) {
            $this->getCartFromSession();
        }
        //   return $this->cart_contents;
        return array_filter((array)$this->cart_contents);
    }

    /**
     *
     */
    public function populateOrder()
    {
        //TODO: Implement DB interaction
    }

    /**
     * @return $this
     */
    public function getCartFromSession()
    {
        //initialise
        $update_cart_session = false;
        //get items from the cart
        /**
         * If we process the product with "Cart::getItems", then item meta are move to array format.
         * Raw array format is not suitable for using "$this->meta->meta_key", so product is not yet
         * processing.
         */
        $cart_items = Cart::getItems(true);

        foreach ($cart_items as $key => $cartitem) {

            //let us find the product
            $product = $cartitem->getProduct();

            if ($product && !is_null($product)) {
                //does the product exists
                if ($product->getId() && $product->exists() && $cartitem->getQuantity() > 0) {
                    if (!$product->isPurchasable()) {
                        //product is unavailable. Set a flag indicating that the cart session has to be updated.
                        $update_cart_session = true;
                        do_action('sp_remove_cart_item_from_session', $key, $cartitem);
                    } else {
                        $cartitem->put('product', $product);
                        $this->cart_contents[$key] = apply_filters('cartrabbit_get_cart_item_from_session', $cartitem, $key);
                    }
                }
            }
        }

        // Trigger action
        do_action('sp_cart_loaded_from_session', $this);
        if ((!$this->subtotal) && (!$this->isEmpty())) {
            $this->calculateTotals();
        }
        return $this;
    }

    /**
     * @return array
     */
    public function getCartContents()
    {
        return $this->cart_contents;
    }

    /**
     * @return int
     */
    public function isEmpty()
    {
        return 0 === sizeof($this->getCart());
    }

    /**
     * Sort by subtotal.
     * @param  array $a
     * @param  array $b
     * @return int
     */
    private function sort_by_subtotal($a, $b)
    {
        $first_item_subtotal = isset($a['line_subtotal']) ? $a['line_subtotal'] : 0;
        $second_item_subtotal = isset($b['line_subtotal']) ? $b['line_subtotal'] : 0;
        if ($first_item_subtotal === $second_item_subtotal) {
            return 0;
        }
        return ($first_item_subtotal < $second_item_subtotal) ? 1 : -1;
    }

    /**
     * Function to apply discounts to a product and get the discounted price (before tax is applied).
     *
     * @param Collection $cartitem
     * @param mixed $price
     * @param bool $add_totals (default: false)
     * @return float price
     */
    public function getDiscountedPrice($cartitem, $price, $add_totals = false)
    {
        //return the price for now
        //TODO change this after implementing the discount logic.
        return $price;
    }

    /**
     *
     */
    public function calculateShipping()
    {
        $shipping = new Shipping();

        if ($this->needs_shipping() && $this->show_shipping()) {
            $shipping->calculateShipping($this->get_shipping_packages());
        } else {
            $shipping->resetShipping();
        }

        // Get totals for the chosen shipping method
        $this->shipping_total = $shipping->shipping_total;    // Shipping Total
        $this->shipping_taxes = $shipping->shipping_taxes;    // Shipping Taxes
        $this->shipping_rates = $shipping->shipping_rates; // Shipping Methods with its Totals
    }

    /**
     * Looks through the cart to see if shipping is actually required.
     *
     * @return bool whether or not the cart needs shipping
     */
    public function needs_shipping()
    {
        $this->shipping_info['isEnable'] = true;
        if (Settings::get('shipping_enable', 'no') === 'no') {
            $this->shipping_info['isEnable'] = false;
            return false;
        }
        $this->shipping_info['shipping_dont_allow_if_no_shipping'] = Settings::get('shipping_dont_allow_if_no_shipping', 'off');
        $needs_shipping = false;
        if ($this->cart_contents) {
            foreach ($this->cart_contents as $cart_item_key => $cartitem) {
                $product = $cartitem->getProduct();
                if ($product->requiresShipping()) {
                    $needs_shipping = true;
                }
            }
        }
        $this->shipping_info['needShipping'] = $needs_shipping;
        return apply_filters('cartrabbit_cart_needs_shipping', $needs_shipping);
    }

    /**
     * Should the shipping address form be shown.
     *
     * @return bool
     */
    function needs_shipping_address()
    {
        $needs_shipping_address = false;
        if ($this->needs_shipping() === true && !wc_ship_to_billing_address_only()) {
            $needs_shipping_address = true;
        }
        return apply_filters('woocommerce_cart_needs_shipping_address', $needs_shipping_address);
    }

    /**
     * Sees if the customer has entered enough data to calc the shipping yet.
     *
     * @return bool
     */
    public function show_shipping()
    {
        if (Settings::get('shipping_enable', 'no') == 'no' || empty($this->cart_contents)) return false;
        if ('yes' === Settings::get('cartconfig_is_shipping_address_required', 'yes')) {
            $customer = new Customer();
            if (!$customer->has_calculated_shipping()) {
                if (!$customer->get_shipping_country() && !$customer->get_shipping_state() && !$customer->get_shipping_postcode()) {
                    return false;
                }
            }
        }
        $show_shipping = true;
        return apply_filters('cartrabbit_cart_ready_to_calc_shipping', $show_shipping);
    }

    /**
     * Get packages to calculate shipping for.
     *
     * This lets us calculate costs for carts that are shipped to multiple locations.
     *
     * Shipping methods are responsible for looping through these packages.
     *
     * By default we pass the cart itself as a package - plugins can change this.
     * through the filter and break it up.
     *
     * @since 1.5.4
     * @return array of cart items
     */
    public function get_shipping_packages()
    {
        // Packages array for storing 'carts'
        $customer = new Customer();
        $packages = array();
        $packages[0]['contents'] = $this->getCart();        // Items in the package
        $packages[0]['contents_cost'] = 0;                        // Cost of items in the package, set below
        $packages[0]['applied_coupons'] = $this->applied_coupons;
        $packages[0]['user']['ID'] = get_current_user_id();
        $packages[0]['destination']['country'] = $customer->get_shipping_country();
        $packages[0]['destination']['state'] = $customer->get_shipping_state();
        $packages[0]['destination']['postcode'] = $customer->get_shipping_postcode();
        $packages[0]['destination']['city'] = $customer->get_shipping_city();
        $packages[0]['destination']['address'] = $customer->get_shipping_address();
        $packages[0]['destination']['address_2'] = $customer->get_shipping_address_2();
        foreach ($this->cart_contents as $item) {
            //TODO: Simplify this
            if ($item['product']->requiresShipping()) {
                if (isset($item['line_total'])) {
                    $packages[0]['contents_cost'] += $item['line_total'];
                }
            }
        }
        return apply_filters('cartrabbit_cart_shipping_packages', $packages);
    }
    /**
     * TOTALS
     */
    /**
     * Product functions
     */
    /**
     * Get the line item price
     *
     * @param ProductInterface $product
     * @return string formatted price
     */
    public function getLineItemPrice(ProductInterface $product, $quantity)
    {
        if ($this->tax_display_cart == 'excludeTax') {
            $product_price = $product->get_price_excluding_tax($quantity, '', true);
        } else {
            $product_price = $product->get_price_including_tax($quantity, '', true);
        }
        return apply_filters('sp_cart_product_price', $product_price, $product);
    }

    /**
     * Get the line item subtotal.
     *
     * Gets the tax etc to avoid rounding issues.
     *
     * When on the checkout (review order), this will get the subtotal based on the customer's tax rate rather than the base rate.
     *
     * @param ProductInterface $product
     * @param int quantity
     * @return string formatted price
     */
    public function getLineItemSubtotal(ProductInterface $product, $quantity)
    {
        // Taxable
        if ($product->isTaxable()) {
            if ($this->tax_display_cart == 'excludeTax') {
                $row_price = $product->get_price_excluding_tax($quantity);
                $product_subtotal = $row_price;
                if ($this->prices_include_tax && $this->tax_total > 0) {
                    $product_subtotal .= ' <small class="tax_label">(ex. Tax)</small>';
                }
            } else {
                $row_price = $product->get_price_including_tax($quantity);
                $product_subtotal = $row_price;
                if (!$this->prices_include_tax && $this->tax_total > 0) {
                    $product_subtotal .= ' <small class="tax_label">(incl. Tax)</small>';
                }
            }
            // Non-taxable
        } else {
            $pricing = $product->getPrice($quantity);
            $price = $pricing->price;
            $row_price = $price * $quantity;
            $product_subtotal = $row_price;
        }
        return apply_filters('sp_cart_product_subtotal', $product_subtotal, $product, $quantity, $this);
    }
    /**
     * Tax functions
     */
    /**
     * Returns the cart and shipping taxes, merged.
     *
     * @return array merged taxes
     */
    public function getTaxes()
    {
        $taxes = array();
        // Merge
        foreach (array_keys($this->taxes + $this->shipping_taxes) as $key) {
            $taxes[$key] = (isset($this->shipping_taxes[$key]['rate']) ? $this->shipping_taxes[$key] : $this->taxes[$key]);
        }
        return apply_filters('sp_cart_get_taxes', $taxes, $this);
    }

    /**
     * Get taxes, merged by code, formatted ready for output.
     *
     * @return array
     */
    public function getItemisedTaxTotals()
    {
        $taxes = $this->getTaxes();
        $tax_totals = array();
        $currency = new Currency();
        foreach ($taxes as $key => $tax) {
            if (isset($tax['rate']) && $tax['rate'] instanceof TaxRateAmount) {
                $rate = $tax['rate'];
                $taxType = $rate->getRate()->getType();
                $tax_rate_id = $rate->getId();
                $tax_totals[$tax_rate_id] = new \stdClass();
                $tax_totals[$tax_rate_id]->tax_rate_id = $rate->getId();
                $tax_totals[$tax_rate_id]->is_compound = $taxType->isCompound();
                $tax_totals[$tax_rate_id]->label = $taxType->getName();
                $tax_totals[$tax_rate_id]->amount += $tax['amount'];
                $tax_totals[$tax_rate_id]->formatted_amount = $currency->format($tax_totals[$tax_rate_id]->amount);
            }
        }
        return apply_filters('sp_cart_tax_totals', $tax_totals, $this);
    }

    /**
     * @param bool $compound
     * @return mixed
     */
    public function getTaxesTotal($compound = true)
    {
        $total = 0;
        $taxes = $this->getItemisedTaxTotals();
        foreach ($taxes as $key => $tax) {
            if (!$compound && $tax->is_compound) continue;
            $total += $tax->amount;
        }
        return apply_filters('sp_cart_taxes_total', $total, $compound, $this);
    }

    /**
     * @param $tax
     * @return \stdClass
     */
    public function getSingleTaxRate($tax)
    {
        if (!isset($tax['rate']) || !$tax['rate'] instanceof TaxRateAmount) {
            $tax_rate_id = 0;
            $tax_totals[$tax_rate_id] = new \stdClass();
            $tax_totals[$tax_rate_id]->amount = 0;
        }
        $rate = $tax['rate'];
        $taxType = $rate->getRate()->getType();
        $tax_rate_id = $rate->getId();
        $single_rate = new \stdClass();
        $single_rate->tax_rate_id = $rate->getId();
        $single_rate->is_compound = $taxType->isCompound();
        $single_rate->label = $taxType->getName();
        $single_rate->amount += $tax['amount'];
        $single_rate->formatted_amount = (new Currency())->format($single_rate->amount);
        return $single_rate;
    }

    /**
     * @return mixed
     */
    public function getCartTaxTotal()
    {
        $cart_total_tax = $this->tax_total + $this->shipping_tax_total;
        return apply_filters('sp_get_cart_tax', $cart_total_tax ? $cart_total_tax : '');
    }
    /**
     * Cart, shipping, order
     */
    /**
     * Gets the order total (after calculation).
     *
     * @return string formatted price
     */
    public function getTotal()
    {
        return apply_filters('sp_cart_total', $this->total);
    }

    /**
     * Gets the total excluding taxes.
     *
     * @return string formatted price
     */
    public function getTotalExTax()
    {
        $total = $this->total - $this->tax_total - $this->shipping_tax_total;
        if ($total < 0) {
            $total = 0;
        }
        return apply_filters('sp_cart_total_ex_tax', $total);
    }

    /**
     * Gets the cart contents total (after calculation).
     *
     * @return string formatted price
     */
    public function getCartTotal()
    {
        if (!$this->prices_include_tax) {
            $cart_contents_total = $this->cart_contents_total;
        } else {
            $cart_contents_total = $this->cart_contents_total + $this->tax_total;
        }
        return apply_filters('sp_cart_contents_total', $cart_contents_total);
    }

    /**
     * Gets the sub total (after calculation).
     *
     * @params bool whether to include compound taxes
     * @return string formatted price
     */
    public function getCartSubtotal($compound = false)
    {
        // If the cart has compound tax, we want to show the subtotal as
        // cart + shipping + non-compound taxes (after discount)
        if ($compound) {
            $cart_subtotal = $this->cart_contents_total + $this->shipping_total + $this->getTaxesTotal(false);
            // Otherwise we show cart items totals only (before discount)
        } else {
            // Display varies depending on settings
            if ($this->tax_display_cart == 'excludeTax') {
                $cart_subtotal = (new Currency())->format($this->subtotal_ex_tax);
                if ($this->tax_total > 0 && !$this->prices_include_tax) {
                    $cart_subtotal .= ' <small class="tax_label">(ex. Tax)</small>';
                }
            } else {
                $cart_subtotal = (new Currency())->format($this->subtotal);
                if ($this->tax_total > 0 && $this->prices_include_tax) {
                    $cart_subtotal .= ' <small class="tax_label">(incl. Tax)</small>';
                }
            }
        }
        return apply_filters('sp_cart_subtotal', $cart_subtotal, $compound, $this);
    }

    /**
     * Gets the shipping total (after calculation).
     *
     * @return string price or string for the shipping total
     */
    public function getCartShippingTotal()
    {
        if (isset($this->shipping_total)) {
            if ($this->shipping_total > 0) {
                // Display varies depending on settings
                if ($this->tax_display_cart == 'excludeTax') {
                    $return = $this->shipping_total;
                    if ($this->shipping_tax_total > 0 && $this->prices_include_tax) {
                        $return .= ' <small class="tax_label">(ex. Tax)</small>';
                    }
                    return $return;
                } else {
                    $return = $this->shipping_total + $this->shipping_tax_total;
                    if ($this->shipping_tax_total > 0 && !$this->prices_include_tax) {
                        $return .= ' <small class="tax_label">(incl. Tax)</small>';
                    }
                    return $return;
                }
            } else {
                return __('Free', 'cartrabbit');
            }
        }
        return '';
    }

    /**
     * @return mixed
     */
    public function getCartOrderTotal()
    {
        $value = '<strong>' . (new Currency())->format($this->getTotal()) . '</strong> ';
        // If prices are tax inclusive, show taxes here
        if (Settings::isTaxEnabled() && $this->tax_display_cart == 'includeTax') {
            $tax_string_array = array();
            if (Settings::get('tax_display_tax_total', 'itemized') == 'itemized') {
                foreach ($this->getItemisedTaxTotals() as $code => $tax)
                    $tax_string_array[] = sprintf('%s %s', $tax->formatted_amount, $tax->label);
            } else {
                $tax_string_array[] = sprintf('%s %s', $this->getTaxesTotal(true), Settings::taxOrVat());
            }
            if (!empty($tax_string_array)) {
                $customer = new Customer();
                $taxable_address = $customer->get_taxable_address();
                $estimated_text = $customer->is_customer_outside_base() && !$customer->has_calculated_shipping()
                    ? sprintf(' ' . __('estimated for %s', 'cartrabbit'), $taxable_address[0])
                    : '';
                $value .= '<small class="includes_tax">' . sprintf(__('(includes %s)', 'cartrabbit'), implode(', ', $tax_string_array) . $estimated_text) . '</small>';
            }
        }
        return apply_filters('sp_cart_totals_order_total_html', $value);
    }
}
