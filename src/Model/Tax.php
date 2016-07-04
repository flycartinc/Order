<?php

namespace Flycartinc\Order\Model;

use Illuminate\Database\Eloquent\Model as Eloquent;
use StorePress\Helper\Address;
use StorePress\Helper\Taxable;
use StorePress\Models\Settings;

use CommerceGuys\Tax\Repository\TaxTypeRepository;
use CommerceGuys\Tax\Resolver\TaxType\ChainTaxTypeResolver;
use CommerceGuys\Tax\Resolver\TaxType\CanadaTaxTypeResolver;
use CommerceGuys\Tax\Resolver\TaxType\EuTaxTypeResolver;
use CommerceGuys\Tax\Resolver\TaxType\DefaultTaxTypeResolver;
use CommerceGuys\Tax\Resolver\TaxRate\ChainTaxRateResolver;
use CommerceGuys\Tax\Resolver\TaxRate\DefaultTaxRateResolver;
use CommerceGuys\Tax\Resolver\TaxResolver;
use CommerceGuys\Tax\Resolver\Context;

/**
 * Class products
 * @package StorePress\Models
 */
class Tax extends Eloquent
{

    protected static $tax_repository;

    /**
     * To Process the Tax by Getting the Store address and the Customer Address.
     * Customer address is taken from Session, If Customer not select the Address
     * then the address of customer is consider as Store address
     *
     *
     * @return array
     */
    public function processTax($isStore = false)
    {
        $storeAddress = (new Settings())->getStoreAddress();
        $customerAddress = ((new Settings())->getCustomerAddress());

        if ($customerAddress == null) $customerAddress = $storeAddress;

        //if the tax is calculated in based on the Store,
        //then the customer address is act as same as the store address
        if ($isStore) $customerAddress = $storeAddress;

        $Address = new Address();
        $tax_address1 = $Address->format($storeAddress);
        $tax_address2 = $Address->format($customerAddress);
        $tax = $this->tax($tax_address1, $tax_address2);
        return $tax;
    }

    /**
     * To  Return the tax classes in based on Store's Tax profile classes
     *
     * @return array of Classes
     */
    public function getTaxClasses()
    {
        $Address = new Address();
        $defaultAddress = (new Settings())->getStoreAddress();
        $tax_address_default = $Address->format($defaultAddress);
        $tax = $this->tax($tax_address_default, $tax_address_default);

        foreach ($tax['rates'] as $rate) {
            $classes[] = $rate->getName();
        }
        return $classes;
    }

    /**
     * Tax Calculation based on the Addresses
     */
    public function tax($storeAddress, $customerAddress)
    {
        $result = array();

        $resolver = self::getTaxInstance();
        $context = new Context($storeAddress, $customerAddress);
        $taxable = new Taxable();
        $taxable->isPhysical();

        $result['amount'] = $resolver->resolveAmounts($taxable, $context);
        $result['rates'] = $resolver->resolveRates($taxable, $context);
        $result['types'] = $resolver->resolveTypes($taxable, $context);

        return (!empty($result)) ? $result : array();
    }

    /** Implement Singleton Instance to limit data access */
    public static function getTaxInstance()
    {
        if (is_null(self::$tax_repository)) {
            self::taxInstance();
            return self::$tax_repository;
        }
        return self::$tax_repository;
    }

    public static function taxInstance()
    {
        $taxTypeRepository = new TaxTypeRepository();
        $chainTaxTypeResolver = new ChainTaxTypeResolver();
        $chainTaxTypeResolver->addResolver(new CanadaTaxTypeResolver($taxTypeRepository));
        $chainTaxTypeResolver->addResolver(new EuTaxTypeResolver($taxTypeRepository));
        $chainTaxTypeResolver->addResolver(new DefaultTaxTypeResolver($taxTypeRepository));
        $chainTaxRateResolver = new ChainTaxRateResolver();
        $chainTaxRateResolver->addResolver(new DefaultTaxRateResolver());
        $resolver = new TaxResolver($chainTaxTypeResolver, $chainTaxRateResolver);
        self::$tax_repository = $resolver;
    }
}