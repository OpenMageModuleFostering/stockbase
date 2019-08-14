<?php

/**
 * Class Divide_Stockbase_Helper_HTTP
 */
class Divide_Stockbase_Helper_HTTP extends Mage_Core_Helper_Abstract
{
    /**
     * Webservice ENDPOINTS, & ANSWERS
     * Live: https://iqservice.divide.nl/
     * Dev:  http://server.divide.nl/divide.api/
     * Docs: http://server.divide.nl/divide.api/docs
     */
    const ENDPOINT_LOGIN = 'services/login';
    const ENDPOINT_AUTH = 'authenticate';
    const ENDPOINT_STOCKBASE_STOCK = 'stockbase/stock';
    const ENDPOINT_STOCKBASE_ORDER = 'stockbase/orderRequest';
    const ENDPOINT_STOCKBASE_IMAGES = 'stockbase/images';
    const ANSWER_INVALID_CREDENTIALS = 'LoginCredentialsNotValid';
    const ANSWER_EMPTY_CREDENTIALS = 'CredentialsEmpty';
    const ANSWER_SUCCESS = 'Success';
    const ANSWER_UNKNOWN = 'UnknownError';
    const ANSWER_TOKEN_EXPIRED = 'TokenExpired';

    protected $_authToken;
    protected $_authTokenExpiration;
    protected $_refreshToken;
    protected $_accessToken;
    protected $_client;
    protected $_env;

    /**
     * Divide_Stockbase_Helper_HTTP constructor.
     */
    public function __construct()
    {
        // Set mage storeview to default admin
        Mage::app()->getStore()->setId(Mage_Core_Model_App::ADMIN_STORE_ID);
        // Using UTC standards
        $now = new DateTime('NOW', new DateTimeZone('UTC'));
        // Varien HTTP Client for making request
        $this->_client = new Varien_Http_Client();
        // Varien File IO for write/read actions to filesystem
        $this->file = new Varien_Io_File();

        // Server configured to use (production or development)
        $this->_env = Mage::getStoreConfig('stockbase_options/login/stockbase_enviroment');
        // Client uses the REST Interface, set to be application/json for all calls.
        $this->_client->setHeaders('Content-Type', 'application/json');
        $this->_accessToken = Mage::getStoreConfig('stockbase_options/token/access');
        $this->_authToken = Mage::getStoreConfig('stockbase_options/token/auth');
        $this->_authTokenExpiration = Mage::getStoreConfig('stockbase_options/token/auth_expiration_date');
        $this->accessTokenExpiration = Mage::getStoreConfig('stockbase_options/token/access_expiration_date');
        $this->_refreshToken = Mage::getStoreConfig('stockbase_options/token/refresh');
        $this->username = Mage::getStoreConfig('stockbase_options/login/username');
        $this->password = Mage::getStoreConfig('stockbase_options/login/password');
    }

    /**
     * Authenticate with the authentication token
     * for access and refresh tokens. refresh parameter
     * forces to refresh tokens, otherwise accesstoken will
     * be used by default.
     *
     * @param  bool $refresh
     *
     * @return bool
     */
    public function authenticate($refresh = false)
    {
        $authClient = new Zend_Http_Client($this->_env . self::ENDPOINT_AUTH);
        $token = null;

        // if refresh true, use refresh, but if not set, use auth instead
        if ($refresh) {
            $token = Mage::getStoreConfig('stockbase_options/token/refresh');
        }

        // Possible that we dont have refresh token yet, then fallback on the auth token to present
        if (!$token) {
            $token = Mage::getStoreConfig('stockbase_options/token/auth');
        }

        // If we still dont have token, login first then.
        if ($token == null) {
            $token = $this->login();
        }

        $headers = array(
            'Content-Type' => 'application/json',
            'Authentication' => $token,
        );
        $authClient->setHeaders($headers);

        $result = json_decode($authClient->request('GET')->getBody());
        $response = $result->{'nl.divide.iq'};

        if ($response->answer == self::ANSWER_TOKEN_EXPIRED) {
            $this->login($this->username, $this->password);
        }

        if ($response->answer == self::ANSWER_SUCCESS) {
            if (isset($response->{'refresh_token'})) {
                Mage::getModel('core/config')
                    ->saveConfig('stockbase_options/token/refresh', $response->{'refresh_token'});
            }
            Mage::getModel('core/config')
                ->saveConfig('stockbase_options/token/access', $response->{'access_token'});
            Mage::getModel('core/config')
                ->saveConfig('stockbase_options/token/access_expiration_date', $response->{'expiration_date'});


            return true;
        }

        return false;
    }

    /**
     * Array with username & password for getting
     * the authentication token and expiration date
     *
     * @param    $username
     * @param    $password
     *
     * @return   bool
     */
    public function login($username = false, $password = false)
    {
        $loginClient = new Zend_Http_Client($this->_env . self::ENDPOINT_LOGIN);

        if ($username == false || $password == false) {
            $username = Mage::getStoreConfig('stockbase_options/login/username');
            $password = Mage::getStoreConfig('stockbase_options/login/password');
        }

        $headers = array(
            'Content-Type' => 'application/json',
            'Username' => $username,
            'Password' => $password,
        );
        $loginClient->setHeaders($headers);

        $result = json_decode($loginClient->request('GET')->getBody());
        $response = $result->{'nl.divide.iq'};

        if ($response->answer == self::ANSWER_SUCCESS) {
            Mage::getModel('core/config')
                ->saveConfig('stockbase_options/token/auth', $response->{'authentication_token'});
            Mage::getModel('core/config')
                ->saveConfig('stockbase_options/token/auth_expiration_date', $response->{'expiration_date'});

            return $response->{'authentication_token'};
        }

        return false;
    }

    /**
     * Sends order to Stockbase if ordered quantity
     * is not met by own stocklevels.
     *
     * @param Mage_Sales_Model_Order $mageOrder
     *
     * @return bool
     */
    public function sendMageOrder(Mage_Sales_Model_Order $mageOrder)
    {
        // Configured orderPrefix for keeping ordernumbers unique for merchant.
        $orderPrefix = Mage::getStoreConfig('stockbase_options/login/order_prefix');
        // Configured EAN field from stockbase configuration
        $configuredEan = Mage::getStoreConfig('stockbase_options/login/stockbase_ean_field');
        $shippingAddress = $mageOrder->getShippingAddress();
        // Parsing street for seperation of street, number, and additional
        $parsedAddress = $this->splitStreet($shippingAddress->getStreet1());
        // StockbaseEANs from users Stockbase account
        $sbEans = Mage::getSingleton('Divide_Stockbase_Helper_Data')->getAllEansFromStockbase();
        // Using UTC TimeZone as standard
        $now = new DateTime('now', new DateTimeZone('UTC'));
        // Used for collecting items from order to send
        $orderLines = array();

        // If splitStreet fails, fallback to fullstreet.
        if (!$parsedAddress['street'] || !$parsedAddress['number']) {
            $parsedAddress['street'] = $shippingAddress->getStreetFull();
            $parsedAddress['number'] = '-';
        }

        // Parsing Address fields from the Magento Order
        $address = array(
            'Street' => $parsedAddress['street'],
            'StreetNumber' => (int)$parsedAddress['number'],
            'StreetNumberAddition' => $parsedAddress['numberAddition'],
            'ZipCode' => $shippingAddress->getPostcode(),
            'City' => $shippingAddress->getCity(),
            'CountryCode' => $shippingAddress->getCountryModel()->getIso3Code(),
        );

        // Filling Person details
        $person = array(
            'Gender' => $mageOrder->getCustomerGender() ? 'Female' : 'Male',
            'Initials' => strtoupper($mageOrder->getCustomerFirstname()[0]),
            'FirstName' => $mageOrder->getCustomerFirstname(),
            'SurnamePrefix' => $mageOrder->getCustomerPrefix() ?: ' ',
            'Surname' => $mageOrder->getCustomerLastname(),
            'Company' => $mageOrder->getShippingAddress()->getCompany() ?: " ",
        );

        // Put the person and Adres in OrderDelivery Array
        $orderDelivery = array(
            'Person' => $person,
            'Address' => $address,
        );

        // Loop over ordered items and check if there is a shortage on own stock
        foreach ($mageOrder->getAllItems() as $key => $item) {
            // Get stockitem from the orderedProduct to check with.
            $stock = $item->getProduct()->getStockItem()->getQty();

            // If out of stock && known by Stockbase,
            // TypeCasting it into integer cuz Magento uses decimals for qty.
            if ($stock < (int)$item->getQtyOrdered()) {
                // Find the configured EAN for the product so we can identify it.
                $ean = $item->getProduct()->getData($configuredEan);
                // Now check if the ordered shortage exsists in your stockbase account.
                if (in_array($ean, $sbEans)) {
                    // Finally fill up the orderlines to be send to stockbase.
                    $orderLines[] = array(
                        'Number' => $key + 1, // orderLineNumber starting from 1
                        'EAN' => $ean,
                        'Amount' => (int)$item->getQtyOrdered(),
                    );
                }
            }
        }

        // Compose the OrderHeader with the filled up data
        $orderHeader = array(
            'OrderNumber' => $orderPrefix . '#' . $mageOrder->getRealOrderId(),
            'TimeStamp' => $now->format('Y-m-d h:i:s'),
            'Attention' => $mageOrder->getCustomerNote() ? $mageOrder->getCustomerNote() : ' ',
        );

        // Compose the OrderRequest in final form for Stockbase
        $orderRequest = array(
            'OrderHeader' => $orderHeader,
            'OrderLines' => $orderLines,
            'OrderDelivery' => $orderDelivery,
        );

        // Only send the order if we have collected items to be ordered.
        if (count($orderLines) > 1) {
            $posted = $this->post(self::ENDPOINT_STOCKBASE_ORDER, $orderRequest)->{'nl.divide.iq'};
        }

        // If failure, we log request and response for debugging.
        if (!$posted->response->content->StatusCode == 1) {
            // We log errors to stockbase-failure.txt in the /var/log/ folder of Magento
            Mage::log(var_export($posted, true), false, 'stockbase-failure.txt');

            return false;
        }

        return true;
    }

    /**
     * Magento street comes with housenumber attached to it, this
     * method tries to seperate the Street, housnumber, and additions.
     *
     * @param string $street
     *
     * @return array
     */
    protected function splitStreet($street)
    {
        $aMatch = array();
        $pattern = '#^([\w[:punct:] ]+) ([0-9]{1,5})([\w[:punct:]\-/]*)$#';
        $matchResult = preg_match($pattern, $street, $aMatch);

        $street = (isset($aMatch[1])) ? $aMatch[1] : '';
        $number = (isset($aMatch[2])) ? $aMatch[2] : '';
        $numberAddition = (isset($aMatch[3])) ? $aMatch[3] : '';

        return array(
            'street' => $street,
            'number' => $number,
            'numberAddition' => $numberAddition,
        );
    }

    /**
     * Post a payload to a endpoint. See
     * defined ENDPOINT constants in HTTP Helper.
     *
     * @param $endpoint
     * @param array $payload
     *
     * @return mixed
     */
    protected function post($endpoint, $payload = array())
    {
        if (!$this->hasAccessToken()) {
            $this->authenticate(true);
        }

        $this->_client->setUri($this->_env . $endpoint);
        $this->_client->setHeaders('Authentication', $this->_accessToken);
        $result = $this->_client->setRawData(json_encode($payload), "application/json;charset=UTF-8")->request('POST');
        $response = json_decode($result->getBody());

        if (!$response) {
            Mage::log(var_export($result->getBody(), true), false, 'stockbase-curl-failure.txt');

            return $result;
        }

        return $response;
    }

    /**
     * Check if accesstoken is set from magento's config.
     *
     * @return bool
     */
    protected function hasAccessToken()
    {
        return (bool)$this->_accessToken;
    }

    /**
     * Returns simpleXMLElement with Stock (beginning from group)
     * Debug param defaults to false, gives debug info about request if true.
     *
     * @param bool $debug
     *
     * @return SimpleXMLElement
     */
    public function getStock($debug = false)
    {
        return $this->call(self::ENDPOINT_STOCKBASE_STOCK, $debug);
    }

    /**
     * Makes the final call (the request)to the
     * given webservice endpoint. Should only be
     * used internally by this class.
     *
     * @param  $endpoint
     * @param  bool $debug
     *
     * @return $response (json_decoded body)
     */
    protected function call($endpoint, $debug = false)
    {
        if (!$this->hasAccessToken()) {
            $this->authenticate(true);
        }

        $this->_client->setUri($this->_env . $endpoint);
        $this->_client->setHeaders('Authentication', $this->_accessToken);

        $result = json_decode($this->_client->request()->getBody());

        if ($result->{'nl.divide.iq'}->response->answer == self::ANSWER_SUCCESS) {
            if (!$debug) {
                return $result->{'nl.divide.iq'}->response->content;
            }

            return $result->{'nl.divide.iq'};
        }
    }

    /**
     * Will return image if available at stockbase for given EAN.
     *
     * @param string|array $eans
     *
     * @return array
     */
    public function getImageForEan($eans)
    {
        // Make sure `$eans` is an array.
        $eans = is_array($eans) ? $eans : array($eans);

        // Create the HTTP client.
        $url = $this->_env . self::ENDPOINT_STOCKBASE_IMAGES;
        $imageClient = new Zend_Http_Client("{$url}?ean=" . implode(',', $eans));

        // Add the required headers.
        $headers = array(
            'Content-Type' => 'application/json',
            'Authentication' => Mage::getStoreConfig('stockbase_options/token/access'),
        );
        $imageClient->setHeaders($headers);

        $result = json_decode($imageClient->request()->getBody())->{'nl.divide.iq'};

        if ($result->response->answer == self::ANSWER_SUCCESS) {
            return $result->response->content->Items;
        }

        return array();
    }

    /**
     * Saves images array from stockbase for given $ean
     *
     * @param array $images
     *
     * @return bool
     */
    public function saveImageForProduct($images)
    {
        $productModel = Mage::getModel('catalog/product');
        $eanField = Mage::getStoreConfig('stockbase_options/login/stockbase_ean_field');
        $accessToken = Mage::getStoreConfig('stockbase_options/token/access');
        $tempFolder = Mage::getBaseDir('media') . DS . 'tmp';
        $io = new Varien_Io_File();
        $addedProducts = array();

        foreach ($images as $image) {
            $product = $productModel->loadByAttribute($eanField, $image->EAN);
            $io->checkAndCreateFolder($tempFolder);
            $filePath = $tempFolder . Mage::getSingleton('core/url')->parseUrl($image->{'Url'})->getPath();

            // Continue looping, if we dont have product, we have nothing.
            if (!$product) {
                continue;
            }

            $client = new Varien_Http_Client($image->{'Url'});
            $client->setMethod(Varien_Http_Client::GET);
            $client->setHeaders('Authentication', $accessToken);
            $protectedImage = $client->request()->getBody();

            if ($io->isWriteable($tempFolder)) {
                $io->write($filePath, $protectedImage);
            }

            // Verify written file exsist
            if ($io->fileExists($filePath)) {
                if ($product->getMediaGallery() == null) {
                    $product->setMediaGallery(array('images' => array(), 'values' => array()));
                }
                $product->addImageToMediaGallery(
                    $filePath,
                    array('image', 'small_image', 'thumbnail'),
                    false,
                    false
                );
                $product->{'save'}();
            }
        }

        return true;
    }
}
