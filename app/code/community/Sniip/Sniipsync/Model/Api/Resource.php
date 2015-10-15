<?php
/**
 * Sniip
 *
 * @package     Sniip_Sniipsync
 * @author      Sniip Magento Team <cliff.viegas@sniip.com>
 */
class Sniip_Sniipsync_Model_Api_Resource extends Mage_Api_Model_Resource_Abstract
{
    /**
     * Customer address types
     */
    const ADDRESS_BILLING    = Mage_Sales_Model_Quote_Address::TYPE_BILLING;
    const ADDRESS_SHIPPING   = Mage_Sales_Model_Quote_Address::TYPE_SHIPPING;

    /**
     * Customer checkout types
     */
    const MODE_CUSTOMER = Mage_Checkout_Model_Type_Onepage::METHOD_CUSTOMER;
    const MODE_REGISTER = Mage_Checkout_Model_Type_Onepage::METHOD_REGISTER;
    const MODE_GUEST    = Mage_Checkout_Model_Type_Onepage::METHOD_GUEST;

    /**
     * Attributes map array per entity type
     *
     * @var array
     */
    protected $_attributesMap = array(
        'global' => array(),
    );

    /**
     * Default ignored attribute codes per entity type
     *
     * @var array
     */
    protected $_ignoredAttributeCodes = array(
        'global'    =>  array('entity_id', 'attribute_set_id', 'entity_type_id')
    );

    /**
     * Field name in session for saving store id
     * @var string
     */
    protected $_storeIdSessionField   = 'store_id';




    /**
     * Check if quote already exist with provided quoteId for creating
     *
     * @param int $quoteId
     * @return bool
     */
    protected function _isQuoteExist($quoteId)
    {
        if (empty($quoteId)) {
            return false;
        }

        try {
            $quote = $this->_getQuote($quoteId);
        } catch (Mage_Api_Exception $e) {
            return false;
        }

        if (!is_null($quote->getId())) {
            $this->_fault('quote_already_exist');
        }

        return false;
    }
    /**
     * Base preparation of product data
     *
     * @param mixed $data
     * @return null|array
     */
    protected function _prepareProductsData($data)
    {
        return is_array($data) ? $data : null;
    }
    protected function _prepareCustomerData($data)
    {
        foreach ($this->_attributesMap['quote_customer'] as $attributeAlias=>$attributeCode) {
            if(isset($data[$attributeAlias]))
            {
                $data[$attributeCode] = $data[$attributeAlias];
                unset($data[$attributeAlias]);
            }
        }
        return $data;
    }
    protected function _getProductInfo($productId, $store = null, $identifierType = null)
    {
        $product = Mage::helper('catalog/product')->getProduct($productId,
            $this->_getStoreId($store),
            $identifierType
        );
        return $product;
    }
    /**
     * Retrieves store id from store code, if no store id specified,
     * it use set session or admin store
     *
     * @param string|int $store
     * @return int
     */
    protected function _getStoreId($store = null)
    {
        if (is_null($store)) {
            $store = ($this->_getSession()->hasData($this->_storeIdSessionField)
                ? $this->_getSession()->getData($this->_storeIdSessionField) : 0);
        }

        try {
            $storeId = Mage::app()->getStore($store)->getId();

        } catch (Mage_Core_Model_Store_Exception $e) {
            $this->_fault('store_not_exists');
        }

        return $storeId;
    }
    /**
     * Retrieves quote by quote identifier and store code or by quote identifier
     *
     * @param int|string $storeId
     * @param int $quoteId
     * @return Mage_Sales_Model_Quote
     */
    protected function _getQuote($storeId =null,$quoteId = null)
    {
        /** @var $quote Mage_Sales_Model_Quote */
        if($quoteId){
            $quote = Mage::getModel("sales/quote");
            if (!(is_string($storeId) || is_integer($storeId))) {
                $quote->loadByIdWithoutStore($quoteId);
            } else {
                $storeId = $this->_getStoreId($storeId);
                $quote->setStoreId($storeId)
                    ->load($quoteId);
            }
            if (is_null($quote->getId())) {
                $this->_fault('quote_not_exists');
            }
        }else{
            $quote = Mage::getSingleton('checkout/session')->getQuote();
            $quote->setStoreId($storeId);
        }

        return $quote;
    }

    /**
     * Get store identifier by quote identifier
     *
     * @param int $quoteId
     * @return int
     */
    protected function _getStoreIdFromQuote($quoteId)
    {
        /** @var $quote Mage_Sales_Model_Quote */
        $quote = Mage::getModel('sales/quote')
            ->loadByIdWithoutStore($quoteId);

        return $quote->getStoreId();
    }
    /**
     * Update attributes for entity
     *
     * @param array $data
     * @param Mage_Core_Model_Abstract $object
     * @param string $type
     * @param array|null $attributes
     * @return Mage_Checkout_Model_Api_Resource
     */
    protected function _updateAttributes($data, $object, $type,  array $attributes = null)
    {
        foreach ($data as $attribute=>$value) {
            if ($this->_isAllowedAttribute($attribute, $type, $attributes)) {
                $object->setData($attribute, $value);
            }
        }

        return $this;
    }

    /**
     * Retrieve entity attributes values
     *
     * @param Mage_Core_Model_Abstract $object
     * @param string $type
     * @param array $attributes
     * @return Mage_Checkout_Model_Api_Resource
     */
    protected function _getAttributes($object, $type, array $attributes = null)
    {
        $result = array();

        if (!is_object($object)) {
            return $result;
        }

        foreach ($object->getData() as $attribute=>$value) {
            if (is_object($value)) {
                continue;
            }

            if ($this->_isAllowedAttribute($attribute, $type, $attributes)) {
                $result[$attribute] = $value;
            }
        }

        foreach ($this->_attributesMap['global'] as $alias=>$attributeCode) {
            $result[$alias] = $object->getData($attributeCode);
        }

        if (isset($this->_attributesMap[$type])) {
            foreach ($this->_attributesMap[$type] as $alias=>$attributeCode) {
                $result[$alias] = $object->getData($attributeCode);
            }
        }

        return $result;
    }

    /**
     * Check is attribute allowed to usage
     *
     * @param Mage_Eav_Model_Entity_Attribute_Abstract $attribute
     * @param string $type
     * @param array $attributes
     * @return bool
     */
    protected function _isAllowedAttribute($attributeCode, $type, array $attributes = null)
    {
        if (!empty($attributes)
            && !(in_array($attributeCode, $attributes))) {
            return false;
        }

        if (in_array($attributeCode, $this->_ignoredAttributeCodes['global'])) {
            return false;
        }

        if (isset($this->_ignoredAttributeCodes[$type])
            && in_array($attributeCode, $this->_ignoredAttributeCodes[$type])) {
            return false;
        }

        return true;
    }
    /**
     * Get QuoteItem by Product and request info
     *
     * @param Mage_Sales_Model_Quote $quote
     * @param Mage_Catalog_Model_Product $product
     * @param Varien_Object $requestInfo
     * @return Mage_Sales_Model_Quote_Item
     * @throw Mage_Core_Exception
     */
    protected function _getQuoteItemByProduct(Mage_Sales_Model_Quote $quote,
                                              Mage_Catalog_Model_Product $product,
                                              Varien_Object $requestInfo)
    {
        $cartCandidates = $product->getTypeInstance(true)
            ->prepareForCartAdvanced($requestInfo,
                $product,
                Mage_Catalog_Model_Product_Type_Abstract::PROCESS_MODE_FULL
            );

        /**
         * Error message
         */
        if (is_string($cartCandidates)) {
//            throw Mage::throwException($cartCandidates);
        }

        /**
         * If prepare process return one object
         */
        if (!is_array($cartCandidates)) {
            $cartCandidates = array($cartCandidates);
        }

        /** @var $item Mage_Sales_Model_Quote_Item */
        $item = null;
        foreach ($cartCandidates as $candidate) {
            if ($candidate->getParentProductId()) {
                continue;
            }
            $item = $quote->getItemByProduct($candidate);
        }
        if (is_null($item)) {
            $item = Mage::getModel("sales/quote_item");
        }

        return $item;
    }
    /**
     * Get request for product add to cart procedure
     *
     * @param   mixed $requestInfo
     * @return  Varien_Object
     */
    protected function _getProductRequest($requestInfo)
    {
        if ($requestInfo instanceof Varien_Object) {
            $request = $requestInfo;
        } elseif (is_numeric($requestInfo)) {
            $request = new Varien_Object();
            $request->setQty($requestInfo);
        } else {
            $request = new Varien_Object($requestInfo);
        }

        if (!$request->hasQty()) {
            $request->setQty(1);
        }
        return $request;
    }
    /**
     *
     */
    protected function _getCustomer($customerId)
    {
        /** @var $customer Mage_Customer_Model_Customer */
        $customer = Mage::getModel('customer/customer')
            ->load($customerId);
        if (!$customer->getId()) {
            $this->_fault('customer_not_exists');
        }

        return $customer;
    }
    protected function _prepareCustomerAddressData($data)
    {
        if (!is_array($data) || !is_array($data[0])) {
            return null;
        }

        $dataAddresses = array();
        foreach($data as $addressItem) {
            foreach ($this->_attributesMap['quote_address'] as $attributeAlias=>$attributeCode) {
                if(isset($addressItem[$attributeAlias]))
                {
                    $addressItem[$attributeCode] = $addressItem[$attributeAlias];
                    unset($addressItem[$attributeAlias]);
                }
            }
            $dataAddresses[] = $addressItem;
        }
        return $dataAddresses;
    }
    /**
     * Get customer address by identifier
     *
     * @param   int $addressId
     * @return  Mage_Customer_Model_Address
     */
    protected function _getCustomerAddress($addressId)
    {
        $address = Mage::getModel('customer/address')->load((int)$addressId);
        if (is_null($address->getId())) {
            $this->_fault('invalid_address_id');
        }

        $address->explodeStreetAddress();
        if ($address->getRegionId()) {
            $address->setRegion($address->getRegionId());
        }
        return $address;
    }
    protected function _setErrorLog($domain,$method,$error){
        $service_url = 'http://'.$domain.'/api/v1/trackingLogs';
        $curl = curl_init($service_url);
        $curl_post_data = array(
            "hosting" => Mage::getBaseUrl (),
            "method" => $method,
            "error" => $error,
        );
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $curl_post_data);
        curl_exec($curl);
        curl_close($curl);
    }
    public function getLinkSamlpeUrl($link)
    {
        return $this->getUrl('downloadable/download/linkSample', array('link_id' => $link->getId()));
    }
    public function getSampleUrl($sample)
    {
        return $this->getUrl('downloadable/download/sample', array('sample_id' => $sample->getId()));
    }
    /**
     * Generate url by route and parameters
     *
     * @param   string $route
     * @param   array $params
     * @return  string
     */
    public function getUrl($route = '', $params = array())
    {
        return $this->_getUrlModel()->getUrl($route, $params);
    }
    /**
     * Create and return url object
     *
     * @return Mage_Core_Model_Url
     */
    protected function _getUrlModel()
    {
        return Mage::getModel($this->_getUrlModelClass());
    }
    /**
     * Returns url model class name
     *
     * @return string
     */
    protected function _getUrlModelClass()
    {
        return 'core/url';
    }
    /**
     * Return formated price with two digits after decimal point
     *
     * @param decimal $value
     * @return decimal
     */
    public function getPriceValue($value)
    {
        return number_format($value, 2, null, '');
    }
    /**
     * Return true if price in website scope
     *
     * @return bool
     */
    public function getIsPriceWebsiteScope()
    {
        $scope =  (int) Mage::app()->getStore()->getConfig(Mage_Core_Model_Store::XML_PATH_PRICE_SCOPE);
        if ($scope == Mage_Core_Model_Store::PRICE_SCOPE_WEBSITE) {
            return true;
        }
        return false;
    }
}

?>