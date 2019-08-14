<?php

class Divide_Stockbase_Model_Options
{
    /**
     * Return options for EAN selection from all the created
     * attributes in magento. When user adds new fields to productattributes
     * this will fetch them and display them to be used as custom EAN field.
     * We default to 'sku' field
     *
     * @return array
     */
    public function toOptionArray()
    {
        $productAttrs = Mage::getResourceModel('catalog/product_attribute_collection');
        $options = array();

        foreach ($productAttrs as $productAttr) {
            $code = $productAttr->getAttributeCode();
            $label = $productAttr->getFrontendLabel();
            if (!$label) {
                $label = $productAttr->getAttributeCode();
            }

            $options[] = array(
                'value' => $code,
                'label' => $label,
            );
        }

        return $options;
    }
}
