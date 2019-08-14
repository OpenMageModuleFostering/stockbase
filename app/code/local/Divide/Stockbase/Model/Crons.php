<?php

class Divide_Stockbase_Model_Crons
{
    /**
     * Method to be called by cron in config.xml
     *
     * @return boolean
     */
    public function runNoos()
    {
        if (Mage::getStoreConfig('stockbase_options/login/stockbase_noos_active', Mage::app()->getStore())) {
            $http_helper = Mage::getSingleton('Divide_Stockbase_Helper_HTTP');
            $data_helper = Mage::getSingleton('Divide_Stockbase_Helper_Data');
            $productModel = Mage::getModel('catalog/product');
            $stockModel = Mage::getModel('cataloginventory/stock_item');
            $noosEnabled = Mage::getStoreConfig('stockbase_options/login/stockbase_noos_active');
            $fieldSet = Mage::getStoreConfig('stockbase_options/login/stockbase_ean_field');
            Mage::getModel('core/config')->saveConfig('stockbase_options/cron/last_noos', date('Y-m-d h:i:s'));
            $http_helper->authenticate(true);
            $skus = $data_helper->getAllEans();
            $stockbaseEans = [];

            foreach ($http_helper->getStock()->Groups as $group) {
                foreach ($group->Items as $brand) {
                    if (in_array($brand->EAN, $skus)) {
                        $stockItem = $stockModel->loadByProduct($productModel->loadByAttribute($fieldSet, $brand->EAN));
                        if ($stockItem && $brand->NOOS && $brand->Amount >= 1 && $noosEnabled) {
                            $stockItem
                                ->setData('use_config_backorders', 0)
                                ->setData('is_in_stock', 1)
                                ->setData('backorders', 1);
                            $stockItem->save();
                        }
                    }
                }
            }
            Mage::log('done running never out of stock');

            return true;
        }
    }
    
    /**
     * Sync images with stockbase if configured. 
     * 
     * @return void
     */
    public function runImageImport()
    {
        if (Mage::getStoreConfig('stockbase_options/login/stockbase_image_import', Mage::app()->getStore())) {
            $cron = Mage::getSingleton('Divide_Stockbase_Model_Crons');
            $data_helper = Mage::getSingleton('Divide_Stockbase_Helper_Data');
            $http_helper = Mage::getSingleton('Divide_Stockbase_Helper_HTTP');
            $skus = $data_helper->getAllEans();
            $processed = Mage::getStoreConfig('stockbase_options/images/processed') ? : [];
            $processedEanList = json_decode($processed);
            $max = 5;

            foreach ($skus as $sku) {
                if (!in_array($sku, $processedEanList) && $max != 0) {
                    $images = $http_helper->getImageForEan($sku);
                    $http_helper->saveImageForProduct($images, $sku);
                    $processedEanList[] = $sku;
                    Mage::getConfig()->saveConfig('stockbase_options/images/processed', json_encode($processedEanList));
                    $max--;
                }
            }
        }
    }
}
