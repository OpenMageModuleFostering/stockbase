<?php

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

    protected $authToken;
    protected $authTokenExpiration;
    protected $refreshToken;
    protected $accessToken;
    protected $awnser;
    protected $client;
    protected $env;

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
        $this->client = new Varien_Http_Client();
        // Varien File IO for write/read actions to filesystem
        $this->file = new Varien_Io_File();
        
        // Server configured to use (production or development)
        $this->env = Mage::getStoreConfig('stockbase_options/login/stockbase_enviroment');
        // Client uses the REST Interface, set to be application/json for all calls.
        $this->client->setHeaders('Content-Type', 'application/json');
        $this->accessToken = Mage::getStoreConfig('stockbase_options/token/access');
        $this->authToken = Mage::getStoreConfig('stockbase_options/token/auth');
        $this->authTokenExpiration = Mage::getStoreConfig('stockbase_options/token/auth_expiration_date');
        $this->accessTokenExpiration = Mage::getStoreConfig('stockbase_options/token/access_expiration_date');
        $this->refreshToken = Mage::getStoreConfig('stockbase_options/token/refresh');
        $this->username = Mage::getStoreConfig('stockbase_options/login/username');
        $this->password = Mage::getStoreConfig('stockbase_options/login/password');

        // Check at instantiation for legit & non-expired tokens, otherwise refresh.
        if (strtotime($this->authTokenExpiration) < $now->getTimestamp()) {
            $this->authenticate(true);
        }
    }

    /**
     * Authenticate with the authentication token
     * for access and refresh tokens. refresh parameter
     * forces to refresh tokens, otherwise accesstoken will
     * be used by default.
     *
     * @param  bool $refresh
     * @return bool
     */
    public function authenticate($refresh = false)
    {
        $authClient = new Zend_Http_Client($this->env . self::ENDPOINT_AUTH);
        $token = null;
        
        // if refresh true, use refresh, but if not set, use auth instead
        if($refresh){
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
        
        $authClient->setHeaders([
            'Content-Type' => 'application/json',
            'Authentication' => $token
        ]);
        
        $result = json_decode($authClient->request('GET')->getBody());
        $response = $result->{'nl.divide.iq'};

        if ($response->answer == self::ANSWER_TOKEN_EXPIRED) {
            $this->login($this->username, $this->password);
        }

        if ($response->answer == self::ANSWER_SUCCESS) {
            if(isset($response->refresh_token)){
                Mage::getModel('core/config')
                    ->saveConfig('stockbase_options/token/refresh', $response->refresh_token);                
            }
            Mage::getModel('core/config')
                    ->saveConfig('stockbase_options/token/access', $response->access_token);
            Mage::getModel('core/config')
                    ->saveConfig('stockbase_options/token/access_expiration_date', $response->expiration_date);
        

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
     * @return   bool
     */
    public function login($username = false, $password = false)
    {
        $loginClient = new Zend_Http_Client($this->env . self::ENDPOINT_LOGIN);
        
        if ($username == false || $password == false) {
            $username = Mage::getStoreConfig('stockbase_options/login/username');
            $password = Mage::getStoreConfig('stockbase_options/login/password');
        }
        
        $loginClient->setHeaders([
            'Content-Type' => 'application/json',
            'Username' => $username,
            'Password' => $password
        ]);
        
        $result = json_decode($loginClient->request('GET')->getBody());
        $response = $result->{'nl.divide.iq'};

        if ($response->answer == self::ANSWER_SUCCESS) {
            Mage::getModel('core/config')
                    ->saveConfig('stockbase_options/token/auth', $response->authentication_token);
            Mage::getModel('core/config')
                    ->saveConfig('stockbase_options/token/auth_expiration_date', $response->expiration_date);

            return $response->authentication_token;
        }

        return false;
    }

    /**
     * Sends order to Stockbase if ordered quantity
     * is not met by own stocklevels.
     *
     * @param Mage_Sales_Model_Order $mageOrder
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
        $sbEans = $this->getAllEansFromStockbase();
        // Using UTC TimeZone as standard
        $now = new DateTime('now', new DateTimeZone('UTC'));
        // Used for collecting items from order to send
        $Orderlines = null;
        
        // If splitStreet fails, fallback to fullstreet.
        if (!$parsedAddress['street'] || !$parsedAddress['number']) {
            $parsedAddress['street'] = $shippingAddress->getStreetFull();
            $parsedAddress['number'] = '-';
        }
        
        // Parsing Address fields from the Magento Order
        $Address = [
            'Street' => $parsedAddress['street'],
            'StreetNumber' => (int) $parsedAddress['number'],
            'StreetNumberAddition' => $parsedAddress['numberAddition'],
            'ZipCode' => $shippingAddress->getPostcode(),
            'City' => $shippingAddress->getCity(),
            'CountryCode' => $shippingAddress->getCountryModel()->getIso3Code(),
        ];
        
        // Filling Person details
        $Person = [
            'Gender' => $mageOrder->getCustomerGender() ? 'Female' : 'Male',
            'Initials' => strtoupper($mageOrder->getCustomerFirstname()[0]),
            'FirstName' => $mageOrder->getCustomerFirstname(),
            'SurnamePrefix' => $mageOrder->getCustomerPrefix() ? : ' ',
            'Surname' => $mageOrder->getCustomerLastname(),
            'Company' => $mageOrder->getShippingAddress()->getCompany() ? : " ",
        ];
        
        // Put the person and Adres in OrderDelivery Array
        $OrderDelivery = [
            'Person' => $Person,
            'Address' => $Address,
        ];

        // Loop over ordered items and check if there is a shortage on own stock
        foreach ($mageOrder->getAllItems() as $key => $item) {
            // Get stockitem from the orderedProduct to check with.
            $stock = $item->getProduct()->getStockItem()->getQty();

            // If out of stock && known by Stockbase,
            // TypeCasting it into integer cuz Magento uses decimals for qty.
            if ($stock < (int) $item->getQtyOrdered()) {
                // Find the configured EAN for the product so we can identify it.
                $ean = $item->getProduct()->getData($configuredEan);
                // Now check if the ordered shortage exsists in your stockbase account.
                if (in_array($ean, $sbEans)) {
                    // Finally fill up the orderlines to be send to stockbase.
                    $Orderlines[] = [
                        'Number' => $key + 1, // orderLineNumber starting from 1
                        'EAN' => $ean,
                        'Amount' => (int) $item->getQtyOrdered(),
                    ];
                }
            }
        }
        
        // Compose the OrderHeader with the filled up data
        $OrderHeader = [
            'OrderNumber' => $orderPrefix . '#' . $mageOrder->getRealOrderId(),
            'TimeStamp' => $now->format('Y-m-d h:i:s'),
            'Attention' => $mageOrder->getCustomerNote() ? $mageOrder->getCustomerNote() : ' ',
        ];
        
        // Compose the OrderRequest in final form for Stockbase
        $OrderRequest = [
            'OrderHeader' => $OrderHeader,
            'OrderLines' => $Orderlines,
            'OrderDelivery' => $OrderDelivery,
        ];

        // Only send the order if we have collected items to be ordered.
        if (count($Orderlines) > 1) {
            $posted = $this->post(self::ENDPOINT_STOCKBASE_ORDER, $OrderRequest)->{'nl.divide.iq'};
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
     * @param type $street
     * @return type
     */
    protected function splitStreet($street)
    {
        $aMatch = [];
        $pattern = '#^([\w[:punct:] ]+) ([0-9]{1,5})([\w[:punct:]\-/]*)$#';
        $matchResult = preg_match($pattern, $street, $aMatch);

        $street = (isset($aMatch[1])) ? $aMatch[1] : '';
        $number = (isset($aMatch[2])) ? $aMatch[2] : '';
        $numberAddition = (isset($aMatch[3])) ? $aMatch[3] : '';

        return [
            'street' => $street,
            'number' => $number, 
            'numberAddition' => $numberAddition,
        ];
    }

    /**
     * Collect all EANS from user Stockbase account in a array.
     *
     * @return type
     */
    protected function getAllEansFromStockbase()
    {
        $stockbaseStock = $this->getStock();
        $eans = [];

        foreach ($stockbaseStock->Groups as $group) {
            foreach ($group->Items as $brand) {
                $eans[] = $brand->EAN;
            }
        }
        
        return $eans;
    }

    /**
     * Returns simpleXMLElement with Stock (beginning from group)
     * Debug param defaults to false, gives debug info about request if true.
     *
     * @param bool $debug
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
     * @return $response (json_decoded body)
     */
    protected function call($endpoint, $debug = false)
    {
        if (!$this->hasAccessToken()) {
            $this->authenticate(true);
        }

        $this->client->setUri($this->env . $endpoint);
        $this->client->setHeaders('Authentication', $this->accessToken);

        $result = json_decode($this->client->request()->getBody());

        if ($result->{'nl.divide.iq'}->response->answer == self::ANSWER_SUCCESS) {
            if (!$debug) {
                return $result->{'nl.divide.iq'}->response->content;
            }

            return $result->{'nl.divide.iq'};
        }
    }

    /**
     * Check if accesstoken is set from magento's config.
     *
     * @return bool
     */
    protected function hasAccessToken()
    {
        return (bool)$this->accessToken;
    }

    /**
     * Post a payload to a endpoint. See
     * defined ENDPOINT constants in HTTP Helper.
     *
     * @param $endpoint
     * @param array $payload
     * @return mixed
     */
    protected function post($endpoint, $payload = [])
    {
        if (!$this->hasAccessToken()) {
            $this->authenticate(true);
        }

        $this->client->setUri($this->env . $endpoint);
        $this->client->setHeaders('Authentication', $this->accessToken);
        $result = $this->client->setRawData(json_encode($payload), "application/json;charset=UTF-8")->request('POST');
        $response = json_decode($result->getBody());

        if (!$response) {
            Mage::log(var_export($result->getBody(), true), false, 'stockbase-curl-failure.txt');

            return $result;
        }

        return $response;
    }

    /**
     * Will return image if available at stockbase for given EAN.
     *
     * @param string|array $ean
     */
    public function getImageForEan($ean)
    {
        $collectionEan = [];

        if (is_array($ean)) {
            foreach ($ean as $singleEAN) {
                $collectionEan[] = $singleEAN;
            }
            $collectionEan = implode(',', $collectionEan);
        } else {
            $collectionEan = $ean;
        }

        $imageClient = new Zend_Http_Client($this->env . self::ENDPOINT_STOCKBASE_IMAGES . '?ean='.$collectionEan);
        
        $imageClient->setHeaders([
            'Content-Type' => 'application/json',
            'Authentication' => Mage::getStoreConfig('stockbase_options/token/access')
        ]);
        
        $result = json_decode($imageClient->request()->getBody())->{'nl.divide.iq'};

        if($result->response->answer == self::ANSWER_SUCCESS){
            return $result->response->content->Items;
        }
        
        return false;
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
        $addedProducts = [];
        
        foreach ($images as $image) {
            $product = $productModel->loadByAttribute($eanField, $image->EAN);
            $io->checkAndCreateFolder($tempFolder);
            $filePath = $tempFolder . DS . basename($image->Url);
            
            // Continue looping, if we dont have product, we have nothing.
            if(!$product){
                continue;
            }

            $client = new Varien_Http_Client($image->Url);
            $client->setMethod(Varien_Http_Client::GET);
            $client->setHeaders('Authentication', $accessToken);
            $protectedImage = $client->request()->getBody();

            if($io->isWriteable($tempFolder)){
                $io->write($filePath, $protectedImage);
            }
            
            // Verify written file exsist
            if ($io->fileExists($filePath)) {
                if ($product->getMediaGallery() == null) {
                    $product->setMediaGallery(['images' => [], 'values' => []]);
                }
                $product->addImageToMediaGallery(
                    $filePath,
                    ['image', 'small_image', 'thumbnail'],
                    false,
                    false
                );
                $product->save();
            }
        }
        
        return true;
    }
}
