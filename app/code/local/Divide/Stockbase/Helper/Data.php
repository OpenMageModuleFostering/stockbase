<?php

class Divide_Stockbase_Helper_Data extends Mage_Core_Helper_Abstract
{
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

        $eans = [];
        foreach ($collection as $product) {
            $value = Mage::getResourceModel('catalog/product')
                ->getAttributeRawValue($product->getId(), $attribute, $storeId);

            if ($value) {
                $eans[] = $value;
            }
        }

        return $eans;
    }
}