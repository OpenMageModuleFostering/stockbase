<?php

/**
 * Enviroment options selection for in admin panel.
 * Switching production/development server by setting it in the MageAdmin.
 */
class Divide_Stockbase_Model_Enviroment
{
    const DEVELOPMENT = 'http://server.divide.nl/divide.api/';
    const PRODUCTION = 'https://iqservice.divide.nl/';

    /**
     * Returns option array for server selection in admin configuration.
     *
     * @return array
     */
    public function toOptionArray()
    {
        $options = array(
            array('value' => self::PRODUCTION, 'label' => 'Production'),
            array('value' => self::DEVELOPMENT, 'label' => 'Development'),
        );

        return $options;
    }
}
