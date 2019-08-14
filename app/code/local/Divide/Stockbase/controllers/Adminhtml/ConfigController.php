<?php

class Divide_Stockbase_Adminhtml_ConfigController extends Mage_Adminhtml_Controller_Action
{
    /**
     * indexAction for config page of Stockbase
     *
     * First loads a block and then adds the block with data to the layout.
     * Loads the given stockbase phtml template and fills it up
     * with the configured configuration found in the mage core config.
     *
     * @return void
     */
    public function indexAction()
    {
        // First start loading the default adminhtml layout
        $this->loadLayout();

        // Fetch array with key-value pair Stockbase configuration
        $configuration = $this->getCurrentConfigurationForStockbase();

        // Make the block with the retrieved configuration for rendering
        $configBlock = $this->getLayout()->createBlock('Mage_Core_Block_Template')
            ->setTemplate('stockbase/config.phtml')
            ->setStockbaseConfiguration($configuration);

        // Using the Mage_Core_Block_Template now get the
        // content Block and append it with our block.
        $this->getLayout()->getBlock('content')->append($configBlock);

        // Render the total Mage_Core_Block_Template with our given config.
        $this->renderLayout();
    }

    /**
     * Returns array with set configuration for stockbase.
     *
     * @return array
     */
    protected function getCurrentConfigurationForStockbase()
    {
        $configuration = array();
        $processedImages = Mage::getStoreConfig('stockbase_options/images/processed') ?: array();
        $configuration['module_version'] = Mage::getConfig()->getModuleConfig("Divide_Stockbase")->version;
        $configuration['enabled_module'] = Mage::getStoreConfig('stockbase_options/login/stockbase_active');
        $configuration['enabled_noos'] = Mage::getStoreConfig('stockbase_options/login/stockbase_noos_active');
        $configuration['enabled_image'] = Mage::getStoreConfig('stockbase_options/login/stockbase_image_import');
        $configuration['processed_images'] = count(json_decode($processedImages));
        $configuration['order_prefix'] = Mage::getStoreConfig('stockbase_options/login/order_prefix');
        $configuration['username'] = Mage::getStoreConfig('stockbase_options/login/username');
        $configuration['enviroment'] = Mage::getStoreConfig('stockbase_options/login/stockbase_enviroment');
        $configuration['last_cron'] = Mage::getStoreConfig('stockbase_options/cron/last_noos');

        return $configuration;
    }

    /**
     * Mage ACL rules for adminhtml
     *
     * @return boolean
     */
    protected function _isAllowed()
    {
        return true;
    }
}
