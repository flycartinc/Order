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

use Herbert\Framework\Notifier;
use Illuminate\Support\Collection;
use Flycartinc\Cart\Cart;
use StorePress\Models\Customer;
use StorePress\Models\Fee;
use StorePress\Models\Fees;
use StorePress\Models\OrderMeta;
use StorePress\Models\Product;
use StorePress\Models\Settings;
use StorePress\Models\Shipping;
use StorePress\Models\Tax;
use Symfony\Component\VarDumper\Caster\CutArrayStub;

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
    protected $table = 'storepress_orders';
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
    /** @var float Discount amount before tax */
    public $discount_cart;
    /** @var float Discounted tax amount. Used predominantly for displaying tax inclusive prices correctly */
    public $discount_cart_tax;
    /** @var float Total for additional fees. */
    public $fee_total;
    /** @var float Shipping cost. */
    public $shipping_total;
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

    protected $order_id;

    private $transaction_id;

    private $transaction_data;

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
        $this->prices_include_tax = Settings::pricesIncludeTax();
        $this->tax_display_cart = Settings::get('tax_display_cart');
        parent::__construct($attributes);
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
        return $this->hasMany('Flycartinc\Order\Model\OrderMeta', 'order_id');
    }

    /**
     * @param mixed $order_id
     */
    public function setOrderId($order_id)
    {
        $this->order_id = $order_id;
    }

    public function paymentComplete($status = false)
    {
        if ($status == false) {
            $status = 'complete';
        }

        $this->updateOrderStatus($status);
    }

    public function updateOrderStatus($status)
    {
        //TODO: Improve this Process
        $order_meta = new OrderMeta();
        $order_status = $order_meta->where('order_id', $this->order_id)->where('meta_key', 'order_status')->get();
        if ($order_status->count()) {
            $order_status->first()->delete();
        }
        $orderMeta = new OrderMeta();
        $orderMeta->order_id = $this->order_id;
        $orderMeta->meta_key = 'order_status';
        $orderMeta->meta_value = $status;
        $orderMeta->save();

        $this->saveTransaction();
    }

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
     * @param $settings
     * @param $taxmodel
     * @return $this
     */
    public function initOrder($settings, $taxmodel)
    {
        //first get the items from cart and set them
        //TODO:: add the getter once cart refactoring has been finished.
        //initialise the variables
        $this->params = $settings;
        $this->taxmodel = $taxmodel;
        $cartitems = array();
        return $this;
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
//        do_action('storepress_before_calculate_totals', $this);

        if ($this->isEmpty()) {
            $this->setSession();
        }

        $productModel = new Product();
        $tax_rates = array();
        $shop_tax_rates = array();
        $taxModel = new Tax();
        $cartitems = $this->getCart();

        if (empty($cartitems) or (!is_array($cartitems) and !is_object($cartitems))) return array();

        /**
         * Calculate subtotals for items. This is done first so that discount logic can use the values.
         */
        foreach ($cartitems as $cart_item_key => $cartitem) {
            if (!$cartitem instanceof Collection) {
                $cartitem = collect($cartitem);
            }
//            $product = $cartitem->get('product');
            $product = $productModel->setProductId($cartitem['product_id']);
            $price_obj = $product->getPrice($cartitem->get('quantity', 1));

            if ((!is_null($price_obj->special_price) or isset($price_obj->special_price)) and $price_obj->special_price != 0) {
                $line_price = $price_obj->special_price;
            } else {
                $line_price = $price_obj->price;
            }

            $line_price = $line_price * $cartitem->get('quantity', 1);

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
                 * The woocommerce_adjust_non_base_location_prices filter can stop base taxes being taken off when dealing with out of base locations.
                 * e.g. If a product costs 10 including tax, all users will pay 10 regardless of location and taxes.
                 * This feature is experimental @since 2.4.7 and may change in the future. Use at your risk.
                 */
                if ($item_tax_rates !== $base_tax_rates && apply_filters('storepress_adjust_non_base_location_prices', true)) {
                    // Work out a new base price without the shop's base tax
                    $taxes = $taxModel->calculateTax($line_price, $base_tax_rates, true);
                    // Now we have a new item price (excluding TAX)
                    $line_subtotal = $line_price - array_sum($taxes);
                    // Now add modified taxes (The price is excluding tax. See the above line )
                    $tax_result = $taxModel->calculateTax($line_subtotal, $item_tax_rates);
                    $line_subtotal_tax = array_sum($tax_result);
                    /**
                     * Regular tax calculation (customer inside base and the tax class is unmodified.
                     */
                } else {
                    // Calc tax normally
                    $taxes = $taxModel->calculateTax($line_price, $item_tax_rates, true);
                    $line_subtotal_tax = array_sum($taxes);
                    $line_subtotal = $line_price - array_sum($taxes);
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
                $line_subtotal_tax = array_sum($taxes);
                $line_subtotal = $line_price;
            }
            // Add to main subtotal
            $this->subtotal += $line_subtotal + $line_subtotal_tax;
            $this->subtotal_ex_tax += $line_subtotal;
        }
        // Order cart items by price so coupon logic is 'fair' for customers and not based on order added to cart.
        uasort($cartitems->toArray(), array($this, 'sort_by_subtotal'));
        /**
         * Calculate totals for items.
         */
        foreach ($cartitems as $cart_item_key => $cartitem) {
            $product = $this->getProductFromCartItem($cartitem);

            $price_obj = $product->getPrice($cartitem->get('quantity', 1));

            if ((!is_null($price_obj->special_price) or isset($price_obj->special_price)) and $price_obj->special_price != 0) {
                $line_price = $price_obj->special_price;
            } else {
                $line_price = $price_obj->price;
            }

//            $line_price = $line_price * $cartitem->get('quantity', 1);
            // Prices
            $base_price = $line_price;
            $line_price = $line_price * $cartitem->get('quantity');
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
                $line_total = $taxModel->round($discounted_price * $cartitem->get('quantity'));
                /**
                 * Prices include tax.
                 */
            } elseif ($this->prices_include_tax) {
                $base_tax_rates = $shop_tax_rates[$product->getTaxClass()];
                $item_tax_rates = $tax_rates[$product->getTaxClass()];
                /**
                 * ADJUST TAX - Calculations when base tax is not equal to the item tax.
                 *
                 * The woocommerce_adjust_non_base_location_prices filter can stop base taxes being taken off when dealing with out of base locations.
                 * e.g. If a product costs 10 including tax, all users will pay 10 regardless of location and taxes.
                 * This feature is experimental @since 2.4.7 and may change in the future. Use at your risk.
                 */
                if ($item_tax_rates !== $base_tax_rates && apply_filters('storepress_adjust_non_base_location_prices', true)) {
                    // Work out a new base price without the shop's base tax
                    $taxes = $taxModel->calculateTax($line_price, $base_tax_rates, true);
                    // Now we have a new item price (excluding TAX)
                    $line_subtotal = round($line_price - array_sum($taxes), $taxModel->precision());
                    $taxes = $taxModel->calculateTax($line_subtotal, $item_tax_rates);
                    $line_subtotal_tax = array_sum($taxes);
                    // Adjusted price (this is the price including the new tax rate)
                    $adjusted_price = ($line_subtotal + $line_subtotal_tax) / $cartitem->get('quantity');
                    // Apply discounts
                    $discounted_price = $this->getDiscountedPrice($cartitem, $adjusted_price, true);
                    $discounted_taxes = $taxModel->calculateTax($discounted_price * $cartitem->get('quantity'), $item_tax_rates, true);
                    $line_tax = array_sum($discounted_taxes);
                    $line_total = ($discounted_price * $cartitem->get('quantity')) - $line_tax;
                    /**
                     * Regular tax calculation (customer inside base and the tax class is unmodified.
                     */

                } else {
                    // Work out a new base price without the item tax
                    $taxes = $taxModel->calculateTax($line_price, $item_tax_rates, true);
                    // Now we have a new item price (excluding TAX)
                    $line_subtotal = $line_price - array_sum($taxes);
                    $line_subtotal_tax = array_sum($taxes);
                    // Calc prices and tax (discounted)
                    $discounted_price = $this->getDiscountedPrice($cartitem, $base_price, true);
                    $discounted_taxes = $taxModel->calculateTax($discounted_price * $cartitem->get('quantity'), $item_tax_rates, true);
                    $line_tax = array_sum($discounted_taxes);
                    $line_total = ($discounted_price * $cartitem->get('quantity')) - $line_tax;
                }
                // Tax rows - merge the totals we just got
                foreach (array_keys($this->taxes + $discounted_taxes) as $key) {
                    $this->taxes[$key] = (isset($discounted_taxes[$key]) ? $discounted_taxes[$key] : 0) + (isset($this->taxes[$key]) ? $this->taxes[$key] : 0);
                }
                /**
                 * Prices exclude tax.
                 */
            } else {
                $item_tax_rates = $tax_rates[$product->getTaxClass()];
                // Work out a new base price without the shop's base tax
                $taxes = $taxModel->calculateTax($line_price, $item_tax_rates);
                // Now we have the item price (excluding TAX)
                $line_subtotal = $line_price;
                $line_subtotal_tax = array_sum($taxes);
                // Now calc product rates
                $discounted_price = $this->getDiscountedPrice($cartitem, $base_price, true);
                $discounted_taxes = $taxModel->calculateTax($discounted_price * $cartitem->get('quantity'), $item_tax_rates);

                $discounted_tax_amount = array_sum($discounted_taxes);
                $line_tax = $discounted_tax_amount;
                $line_total = $discounted_price * $cartitem->get('quantity');

                // Tax rows - merge the totals we just got
                foreach (array_keys($this->taxes + $discounted_taxes) as $key) {
                    $this->taxes[$key] = (isset($discounted_taxes[$key]) ? $discounted_taxes[$key] : 0) + (isset($this->taxes[$key]) ? $this->taxes[$key] : 0);
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
                $this->tax_total = array_sum($this->taxes);
                $this->shipping_tax_total = array_sum($this->shipping_taxes);
            }
            // VAT exemption done at this point - so all totals are correct before exemption
            if ($taxModel->isCustomerVatExcepted()) {
                $this->removeTaxes();
            }
            // Allow plugins to hook and alter totals before final total is calculated
            do_action('storepress_calculate_totals', $this);
            // Grand Total - Discounted product prices, discounted tax, shipping cost + tax
            $this->total = max(0, apply_filters('storepress_calculated_total', round($this->cart_contents_total + $this->tax_total + $this->shipping_tax_total + $this->shipping_total + $this->fee_total, $this->dp), $this));
        } else {
            // Set tax total to sum of all tax rows
            $this->tax_total = $taxModel->getTaxTotal($this->taxes);
            // VAT exemption done at this point - so all totals are correct before exemption
            if ($taxModel->isCustomerVatExcepted()) {
                $this->removeTaxes();
            }
        }
        do_action('storepress_after_calculate_totals', $this);

        /** Here, List of tax rates are loaded */
        $this->taxes = array();
        foreach ($taxModel->getAmounts() as $id => $value) {
            $this->taxes[$value->getId()] = ($value->getAmount() * 100) . '%';
        }

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
        do_action('storepress_cart_calculate_fees', $this);
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
     * @param $config
     */
    public static function TaxProfiles(&$config)
    {
        $tax = new \Flycartinc\Order\Model\Tax();
        //Get the Tax Profile Only for Store
        $config['shop_tax'] = $tax->processTax($isStore = true);
        //Get the Tax profile for Store and Customer
        $config['item_tax'] = $tax->processTax();
    }

    /**
     * @return mixed
     */
    public function getTaxDetails()
    {
        $tax['subtotal'] = $this->subtotal;
        $tax['tax'] = $this->total_tax;
        $tax['total'] = $this->total_cost;
        $tax['rates'] = $this->taxrates;
        return $tax;
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
        if (empty($this->cart_contents)) {
            $this->cart_contents = Cart::getItems(true, true);
        }
        return $this->cart_contents;

//        return array_filter((array)$this->cart_contents);
    }

    /**
     * @return $this
     */
    public function getCartFromSession()
    {
        //initialise
        $update_cart_session = false;
        //get items from the cart
        $cart_items = Cart::getItems(true);

        foreach ($cart_items as $key => $cartitem) {
            //let us find the product
            $product = $this->getProductFromCartItem($cartitem);
            //does the product exists
            if ($product->getId() && $product->exists() && $cartitem['quantity'] > 0) {
                if (!$product->isPurchasable()) {
                    //product is unavailable. Set a flag indicating that the cart session has to be updated.
                    $update_cart_session = true;
                } else {
                    $cartitem->put('product', $product);
                    $this->cart_contents[$key] = apply_filters('storepress_get_cart_item_from_session', $cartitem, $key);
                }
            }
        }
//        if ((!$this->subtotal) && (!$this->isEmpty())) {
        if (!$update_cart_session) {
            if ((!$this->subtotal)) {
//                $this->calculateTotals($cart_items);
            }
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
        return count($this->cart_contents);
    }

    /**
     * @param Collection $cartitem
     * @return Product
     */
    public function getProductFromCartItem(Collection $cartitem)
    {
        $product = Product::find($cartitem->get('product_id'));
        if (isset($product->ID) && $product->ID) return $product;
        return new Product();
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
        if (!$price) {
            return $price;
        }
        $undiscounted_price = $price;
        $taxModel = new Tax();
        if (!empty($this->coupons)) {
            $product = $this->getProductFromCartItem($cartitem);
            foreach ($this->coupons as $code => $coupon) {
                if ($coupon->is_valid() && ($coupon->is_valid_for_product($product, $values) || $coupon->is_valid_for_cart())) {
                    $discount_amount = $coupon->get_discount_amount('yes' === get_option('woocommerce_calc_discounts_sequentially', 'no') ? $price : $undiscounted_price, $values, true);
                    $discount_amount = min($price, $discount_amount);
                    $price = max($price - $discount_amount, 0);
                    // Store the totals for DISPLAY in the cart
                    if ($add_totals) {
                        $total_discount = $discount_amount * $cartitem->get('quantity');
                        $total_discount_tax = 0;
                        if ($taxModel->enabled()) {
                            $tax_rates = $taxModel->getItemRates($product);
                            $taxes = $taxModel->calculateTax($discount_amount, $tax_rates, $this->prices_include_tax);
                            $total_discount_tax = $taxModel->getTaxTotal($taxes) * $cartitem->get('quantity');
                            $total_discount = $this->prices_include_tax ? $total_discount - $total_discount_tax : $total_discount;
                            $this->discount_cart_tax += $total_discount_tax;
                        }
                        $this->discount_cart += $total_discount;
                        $this->increase_coupon_discount_amount($code, $total_discount, $total_discount_tax);
                        $this->increase_coupon_applied_count($code, $cartitem->get('quantity'));
                    }
                }
                // If the price is 0, we can stop going through coupons because there is nothing more to discount for this product.
                if (0 >= $price) {
                    break;
                }
            }
        }
        return apply_filters('storepress_get_discounted_price', $price, $values, $this);
    }

    /**
     * Store how much discount each coupon grants.
     *
     * @access private
     * @param string $code
     * @param double $amount
     * @param double $tax
     */
    private function increase_coupon_discount_amount($code, $amount, $tax)
    {
        $this->coupon_discount_amounts[$code] = isset($this->coupon_discount_amounts[$code]) ? $this->coupon_discount_amounts[$code] + $amount : $amount;
        $this->coupon_discount_tax_amounts[$code] = isset($this->coupon_discount_tax_amounts[$code]) ? $this->coupon_discount_tax_amounts[$code] + $tax : $tax;
    }

    /**
     * Store how many times each coupon is applied to cart/items.
     *
     * @access private
     * @param string $code
     * @param int $count
     */
    private function increase_coupon_applied_count($code, $count = 1)
    {
        if (empty($this->coupon_applied_count[$code])) {
            $this->coupon_applied_count[$code] = 0;
        }
        $this->coupon_applied_count[$code] += $count;
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
    }

    /**
     * Looks through the cart to see if shipping is actually required.
     *
     * @return bool whether or not the cart needs shipping
     */
    public function needs_shipping()
    {
        if (Settings::get('enableShipping') === 'no') {
            return false;
        }
        $needs_shipping = false;
        if ($this->cart_contents) {
            foreach ($this->cart_contents as $cart_item_key => $cartitem) {
                $product = $this->getProductFromCartItem($cartitem);
                if ($product->requiresShipping()) {
                    $needs_shipping = true;
                }
            }
        }
        return apply_filters('storepress_cart_needs_shipping', $needs_shipping);
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
        if (Settings::get('enableShipping') == 'off' || empty($this->cart_contents)) return false;

        if ('yes' === Settings::get('cartconfig_shippingCostRequiresAddress')) {
            $customer = new Customer();
            if (!$customer->has_calculated_shipping()) {
                if (!$customer->get_shipping_country() && !$customer->get_shipping_state() && !$customer->get_shipping_postcode()) {
                    return false;
                }
            }
        }
        $show_shipping = true;
        return apply_filters('storepress_cart_ready_to_calc_shipping', $show_shipping);
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
        return apply_filters('storepress_cart_shipping_packages', $packages);
    }
}