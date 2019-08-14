<?php

class Divide_Stockbase_Block_Config extends Mage_Adminhtml_Block_Template
{
    /**
     * Config Block constructor
     * Constructs the page and loads the phtml-template
     * See app/design/default/default/layout & /template for these files.
     */
    public function __construct()
    {
        parent::__construct();
        $this->setTemplate('stockbase/config.phtml');
    }
}
