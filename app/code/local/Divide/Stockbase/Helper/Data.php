<?php

/**
 * @property Divide_Stockbase_Helper_HTTP _requestHelper
 */
class Divide_Stockbase_Helper_Data extends Mage_Core_Helper_Abstract
{

    /**
     * Holds the request helper HTTP for calls to Stockbase
     *
     * @var Divide_Stockbase_Helper_HTTP
     */
    protected $_requestHelper;

    /**
     * Divide_Stockbase_Helper_Data constructor.
     */
    public function __construct()
    {
        $this->_requestHelper = Mage::getSingleton('Divide_Stockbase_Helper_HTTP');
    }

    /**
     * Returns array with all product eans from this shop. Uses
     * the configured field in Stockbase settings to retrieve the ean values.
     *
     * @return array
     */
    public function getAllEans()
    {
        $attribute = Mage::getStoreConfig('stockbase_options/login/stockbase_ean_field');
        $collection = Mage::getModel('catalog/product')->getCollection();
        $storeId = Mage::app()->getStore()->getStoreId();

        $eans = array();
        foreach ($collection as $product) {
            $value = Mage::getResourceModel('catalog/product')
                ->getAttributeRawValue($product->getId(), $attribute, $storeId);

            if ($value) {
                $eans[] = $value;
            }
        }

        return $eans;
    }

    /**
     * Collect all EANS from user Stockbase account in a array.
     *
     * @return type
     */
    public function getAllEansFromStockbase()
    {
        $stockbaseStock = $this->getStock();
        $eans = array();

        foreach ($stockbaseStock->{'Groups'} as $group) {
            foreach ($group->{'Items'} as $brand) {
                $eans[] = $brand->EAN;
            }
        }

        return $eans;
    }

    public function getStock()
    {
        return $this->_requestHelper->getStock();
    }
}
