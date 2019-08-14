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
            $httpHelper = Mage::getSingleton('Divide_Stockbase_Helper_HTTP');
            $dataHelper = Mage::getSingleton('Divide_Stockbase_Helper_Data');
            $productModel = Mage::getModel('catalog/product');
            $stockModel = Mage::getModel('cataloginventory/stock_item');
            $noosEnabled = Mage::getStoreConfig('stockbase_options/login/stockbase_noos_active');
            $fieldSet = Mage::getStoreConfig('stockbase_options/login/stockbase_ean_field');
            $lastNoos = Mage::getSingleton('core/date')->{'date'}();
            Mage::getModel('core/config')->saveConfig('stockbase_options/cron/last_noos', $lastNoos);
            $httpHelper->authenticate(true);
            $skus = $dataHelper->getAllEans();
            $stockbaseEans = array();
            foreach ($httpHelper->getStock()->Groups as $group) {
                foreach ($group->{'Items'} as $brand) {
                    if (in_array($brand->EAN, $skus)) {
                        $product = $productModel->loadByAttribute($fieldSet, $brand->EAN);

                        // Product information adding by custom attributes
                        if ($product) {
                            $product->setData('stockbase_product', 1);
                            $product->setData('stockbase_ean', $brand->EAN);
                            $product->setData('stockbase_stock', $brand->{'Amount'});
                            $product->setData('stockbase_noos', (bool)$brand->NOOS);
                            $product->{'save'}();
                        }

                        // Product stock information update
                        $stockItem = $stockModel->loadByProduct($product);
                        if ($stockItem && ($brand->{'Amount'} >= 1 || $brand->NOOS == true) && $noosEnabled) {
                            $stockItem
                                ->setData('use_config_backorders', 0)
                                ->setData('is_in_stock', 1)
                                ->setData('backorders', 1);
                            $stockItem->{'save'}();
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
            $dataHelper = Mage::getSingleton('Divide_Stockbase_Helper_Data');
            $httpHelper = Mage::getSingleton('Divide_Stockbase_Helper_HTTP');
            $allEans = $dataHelper->getAllEans();
            $processedEans = json_decode(Mage::getStoreConfig('stockbase_options/images/processed')) ?: array();

            // Process 100 unprocessed EANs at a time.
            $eans = array_slice(array_diff($allEans, $processedEans), 0, 100);

            try {
                $images = $httpHelper->getImageForEan($eans);
            } catch (Exception $e) {
                // Error connecting to Stockbase. Stop processing.
                return;
            }

            // Download and save the images locally.
            $httpHelper->saveImageForProduct($images);

            // Update the `processed images` configuration.
            $processedEans = array_merge($processedEans, $eans);
            Mage::getConfig()->saveConfig('stockbase_options/images/processed', json_encode($processedEans));
        }

        return true;
    }
}
