<?php

/**
 * Sniip
 *
 * @package     Sniip_Sniipsync
 * @author      Sniip Magento Team <cliff.viegas@sniip.com>
 */


class Sniip_Sniipsync_Model_Sniipsync_Api extends Sniip_Sniipsync_Model_Api_Resource
{
    /**
     * Initialize attributes map
     */
    public function __construct()
    {
        $this->_storeIdSessionField = "cart_store_id";
        $this->_attributesMap['quote'] = array('quote_id' => 'entity_id');
        $this->_attributesMap['quote_customer'] = array('customer_id' => 'entity_id');
        $this->_attributesMap['quote_address'] = array('address_id' => 'entity_id');
    }
    protected function _preparePaymentData($data)
    {
        if (!(is_array($data) && is_null($data[0]))) {
            return array();
        }

        return $data;
    }
    /**
     * Create new invoice for order
     *
     * @param string $orderIncrementId
     * @param array $itemsQty
     * @param string $comment
     * @param bool|\booleam $email
     * @param boolean $includeComment
     * @return string
     */
    public function createInvoice($orderIncrementId, $itemsQty, $comment = null, $email = false, $includeComment = false)
    {
        $order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);

        /* @var $order Mage_Sales_Model_Order */
        /**
         * Check order existing
         */
        if (!$order->getId()) {
            $this->_fault('order_not_exists');
        }

        /**
         * Check invoice create availability
         */
        if (!$order->canInvoice()) {
            $this->_fault('data_invalid', Mage::helper('sales')->__('Cannot do invoice for order.'));
        }

        $invoice = $order->prepareInvoice($itemsQty);

        $invoice->register();

        if ($comment !== null) {
            /**
             * set visible on frondend
             */
            $invoice->addComment($comment, $email,true);
        }

        if ($email) {
            $invoice->setEmailSent(true);
        }

        $invoice->getOrder()->setIsInProcess(true);

        try {
            Mage::getModel('core/resource_transaction')
                ->addObject($invoice)
                ->addObject($invoice->getOrder())
                ->save();

            $invoice->sendEmail($email, ($includeComment ? $comment : ''));
        } catch (Mage_Core_Exception $e) {
            $this->_fault('data_invalid', $e->getMessage());
        }
        return $invoice->getIncrementId();
    }
    /**
     * Create new Cart and Get shipping for Cart
     *
     * @param array $productsData
     * @param array $customerData
     * @param array $customerAddressData
     * @param string|int $quoteId
     * @param string|int $store
     * @return string
     */
    public function getCartInfo($productsData,$customerData,$customerAddressData, $quoteId= null, $store = null)
    {
        try {
            $quote = $this->_createOrGetCartInfo($store,$quoteId);
            $quoteId = $quote->getId();
            if(!$quoteId){
                $this->_insert_updateProductToCart($quoteId,$productsData,$store);
                $this->_setCustomerToCart($quoteId,$customerData,$customerAddressData,$store);
            }
            $ratesResult = $this->_getShippingMethod($store,$quoteId);
            $result = $this->_getCartInfo($ratesResult,$quoteId,$store);
        }catch (Exception $e){
            return json_encode($e->getMessage());
        }
        return json_encode($result);
    }
    /**
     * Get list shipping info by cart(quote_id)
     *
     * @param string|int $quoteId
     * @param string|int $store
     * @return array
     */
    private function _getShippingMethod($store,$quoteId){
        $quote = $this->_getQuote($store,$quoteId);

        $quoteShippingAddress = $quote->getShippingAddress();
        if (is_null($quoteShippingAddress->getId())) {
            $this->_fault("shipping_address_is_not_set");
        }
        $ratesResult = array();
        try {
            $quoteShippingAddress->collectShippingRates()->save();
            $groupedRates = $quoteShippingAddress->getGroupedAllShippingRates();
            $_sole = $this->getSole($groupedRates);
            $_options = $this->getOptions();
            foreach ($groupedRates as $carrierCode => $rates ) {
                $carrierName = $carrierCode;
                if (!is_null(Mage::getStoreConfig('carriers/'.$carrierCode.'/title'))) {
                    $carrierName = Mage::getStoreConfig('carriers/'.$carrierCode.'/title');
                }
                foreach ($rates as $rate) {
//                    $_excl =$this->getShippingPrice((float)$rate->getPrice(), Mage::helper('tax')->displayShippingPriceIncludingTax());
//                    $_incl =$this->getShippingPrice((float)$rate->getPrice(),true);
//                    $price = $_excl . ($_excl !== $_incl ? '(Incl. Tax'.$_incl.')' :'' );
                    $shippingType= "";
                    if(!$_sole && $carrierCode == 'temando'){
                        foreach($_options as $_option_id => $_option){
                            if ($_option instanceof Temando_Temando_Model_Option_Boolean){
                                if ($_option->getForcedValue() !== Temando_Temando_Model_Option_Boolean::NO)
                                {
                                    $shippingType .= '_'.$_option_id.'_'.Temando_Temando_Model_Option_Boolean::YES;
                                }else{
                                    $shippingType .= '_'.$_option_id.'_'.$_option->getForcedValue();
                                }
                            }
                        }
                        if (strpos($rate->getCode(), $shippingType) === false)
                            continue;
                    }

                    $rateItem = $this->_getAttributes($rate, "quote_shipping_rate");
                    $rateItem['carrierName'] = $carrierName;
                    $ratesResult[] = $rateItem;
                    unset($rateItem);
                }
            }
            $quote->collectTotals()->save();
        } catch (Mage_Core_Exception $e) {
            $this->_fault('shipping_methods_list_could_not_be_retrived'. $e->getMessage());
        }
        return $ratesResult;
    }
    protected function getOptions()
    {
        $options = Mage::registry('temando_current_options');
        if(!$options) {
            $options = array();
        }

        return $options;
    }
    protected  function getSole($groups)
    {
        // by default the following will return false.  remove the
        // line below to actually check if it is the only method
//        return false;
        if(count($groups) == 1) {
            $rates = array_pop($groups);
            if(count($rates) == 1) return true;
        }
        return false;
    }

    /**
     * Create customer and Add address for customer with guess account
     *
     * @param Mage_Sales_Model_Quote $quote
     * @param array $customerData
     * @param array $customerAddressData
     * @param string|int $store
     * @return bool
     */
    private function _setCustomerToCart($quoteId,$customerData,$customerAddressData,$store){
        $quote = $this->_getQuote($store,$quoteId);
        $customerData = $this->_prepareCustomerData($customerData);
        if (!isset($customerData['mode'])) {
            $this->_fault('customer_mode_is_unknown');
        }
        switch($customerData['mode']) {
            case self::MODE_CUSTOMER:
                /** @var $customer Mage_Customer_Model_Customer */
                $customer = $this->_getCustomer($customerData['entity_id']);
                $customer->setMode(self::MODE_CUSTOMER);
                break;
            case self::MODE_REGISTER:
            case self::MODE_GUEST:
                /** @var $customer Mage_Customer_Model_Customer */
                $customer = Mage::getModel('customer/customer')
                    ->setData($customerData);

                if ($customer->getMode() == self::MODE_GUEST) {
                    $password = $customer->generatePassword();
                    $customer
                        ->setPassword($password)
                        ->setPasswordConfirmation($password);
                }
                $isCustomerValid = $customer->validate();
                if ($isCustomerValid !== true && is_array($isCustomerValid)) {
                    $this->_fault('customer_data_invalid: '. json_encode($isCustomerValid) );
                }
                break;
        }
        try {
            $customerData['remote_ip'] = (isset($customerData['remote_ip'])) ? $customerData['remote_ip']  : '';
            $quote
                ->setCustomer($customer)
                ->setis_active(true)
//                ->setcustomer_group_id($customerData['customer_group_id'])
                ->setremote_ip($customerData['remote_ip'])
                ->setCheckoutMethod($customer->getMode())
                ->save();

        } catch (Mage_Core_Exception $e) {
            $this->_fault('customer_not_set', $e->getMessage());
        }
//--------------------------------------set Address ----------------------------------------------------
        $quote = $this->_getQuote($store,$quoteId);

        $customerAddressData = $this->_prepareCustomerAddressData($customerAddressData);
        if (is_null($customerAddressData)) {
            $this->_fault('customer_address_data_empty');
        }
        foreach ($customerAddressData as $addressItem) {
            /** @var $address Mage_Sales_Model_Quote_Address */
            $address = Mage::getModel("sales/quote_address");
            $addressMode = $addressItem['mode'];
            unset($addressItem['mode']);
            if (!empty($addressItem['entity_id'])) {
                $customerAddress = $this->_getCustomerAddress($addressItem['entity_id']);
                if ($customerAddress->getCustomerId() != $quote->getCustomerId()) {
                    $this->_fault('address_not_belong_customer');
                }
                $address->importCustomerAddress($customerAddress);

            } else {
                $address->setData($addressItem);
            }
            $address->implodeStreetAddress();

            if (($validateRes = $address->validate())!==true) {
                $this->_fault('customer_address_invalid', implode(PHP_EOL, $validateRes));
            }
            $address->setSaveInAddressBook(1);
            switch($addressMode) {
                case self::ADDRESS_BILLING:
                    $address->setEmail($quote->getCustomer()->getEmail());
                    if (!$quote->isVirtual()) {

                        $usingCase = isset($addressItem['use_for_shipping']) ? (int)$addressItem['use_for_shipping'] : 0;
                        switch($usingCase) {
                            case 0:
                                $shippingAddress = $quote->getShippingAddress();
                                $shippingAddress->setSameAsBilling(0);
                                break;
                            case 1:
                                $billingAddress = clone $address;
                                $billingAddress->unsAddressId()->unsAddressType();
                                $shippingAddress = $quote->getShippingAddress();
                                $shippingMethod = $shippingAddress->getShippingMethod();
                                $shippingAddress->addData($billingAddress->getData())
                                    ->setSameAsBilling(1)
                                    ->setShippingMethod($shippingMethod)
                                    ->setCollectShippingRates(true);

                                break;
                        }
                    }
                    $quote->setBillingAddress($address);
                    break;
                case self::ADDRESS_SHIPPING:
                    $address->setCollectShippingRates(true)
                        ->setSameAsBilling(0);
                    $quote->setShippingAddress($address);
                    break;
            }

        }

        try {
            $quote
                ->collectTotals()
                ->save();
        } catch (Exception $e) {
            $this->_fault('address_is_not_set', $e->getMessage());
        }
        return true;
    }
    /**
     * Retrieve full information about quote
     *
     * @param string|int $store
     * @param string|int $quoteId
     * @return Mage_Sales_Model_Quote
     */
    private function _createOrGetCartInfo($store,$quoteId){
        $quote = $this->_getQuote($store,$quoteId);
        if ($quote->getGiftMessageId() > 0) {
            $quote->setGiftMessage(
                Mage::getSingleton('giftmessage/message')->load($quote->getGiftMessageId())->getMessage()
            );
        }
        return $quote;
    }
    /**
     * Add or Update production to Cart
     *
     * @param Mage_Sales_Model_Quote $quote
     * @param array $productsData
     * @param string|int $store
     * @return boolean
     */
    private function _insert_updateProductToCart($quoteId, $productsData, $store = null){
        $quote = $this->_getQuote($store,$quoteId);
        $productsData = $this->_prepareProductsData($productsData);
        if (empty($productsData)) {
            $this->_fault('invalid_product_data');
        }
        $quote->removeAllItems();
        $errors = array();
        foreach ($productsData as $productItem) {
            if (isset($productItem['product_id'])) {
                $productByItem = $this->_getProductInfo($productItem['product_id'], $store, "id");
            } else if (isset($productItem['sku'])) {
                $productByItem = $this->_getProductInfo($productItem['sku'], $store, "sku");
            } else {
                $errors[] = Mage::helper('checkout')->__("One item of products do not have identifier or sku");
                continue;
            }
            $productRequest = $this->_getProductRequest($productItem);
            try {
                $result = $quote->addProduct($productByItem, $productRequest);
                if (is_string($result)) {
                    Mage::throwException($result);
                }
            } catch (Mage_Core_Exception $e) {
                $errors[] = $e->getMessage();
            }
        }
        if (!empty($errors)) {
            $this->_fault("update_product_fault".implode(' ', $errors));
        }
        try {
            $quote->collectTotals()->save();
        } catch (Exception $e) {
            $this->_fault("update_product_quote_save_fault", $e->getMessage());
        }

        return true;
    }
    /**
     * Retrieve full cart info and shipping
     *
     * @param array $ratesResult
     * @param string|int $quoteId
     * @param string|int $store
     * @return array
     */
    private function _getCartInfo($ratesResult,$quoteId,$store){
        $quote = $this->_getQuote($store,$quoteId);
        if ($quote->getGiftMessageId() > 0) {
            $quote->setGiftMessage(
                Mage::getSingleton('giftmessage/message')->load($quote->getGiftMessageId())->getMessage()
            );
        }
        $result = $this->_getAttributes($quote, 'quote');
        $result['quote_id'] = $quote->getId();
        $result['shipping_address'] = $this->_getAttributes($quote->getShippingAddress(), 'quote_address');
        $result['billing_address'] = $this->_getAttributes($quote->getBillingAddress(), 'quote_address');
        $result['items'] = array();
        foreach ($quote->getAllItems() as $item) {
            if ($item->getGiftMessageId() > 0) {
                $item->setGiftMessage(
                    Mage::getSingleton('giftmessage/message')->load($item->getGiftMessageId())->getMessage()
                );
            }

            $result['items'][] = $this->_getAttributes($item, 'quote_item');
        }
        $result['payment'] = $this->_getAttributes($quote->getPayment(), 'quote_payment');
        $result['shippingMethod'] = $ratesResult;
        return $result;
    }
    /**
     * Retrieve Products by page
     *
     * @param int $pageSize
     * @param int $curPage
     * @param date $fromDate
     * @return array
     */
    public function items($pageSize = 100 ,$curPage = 1,$fromDate = null)
    {
        $todayDate = date('Y-m-d', strtotime("+1 day"));
        if($fromDate){
            $fromDate = date('Y-m-d', strtotime($fromDate));
            $filterDate = array(
                'from'=> $fromDate,
                'to'=> $todayDate,
                'date'=>true
            );
        }else{
            $filterDate = array(
                'to'=> $todayDate,
                'date'=>true
            );
        }

        $collection = Mage::getModel('catalog/product')->getCollection()
            ->addAttributeToSelect('*') // select all attributes
            ->addAttributeToFilter('updated_at',$filterDate)
            ->setPageSize($pageSize) // limit number of results returned
            ->setCurPage($curPage); // set the offset (useful for pagination)
        $products = array();
        if($collection){
            // we iterate through the list of products to get attribute values
            foreach ($collection as $product) {
                $children = array();
                $childrenVariant= array();
                $childrenVariantV1= array();
                if($product->isConfigurable()){
                    $productAttributeOptions = $product->getTypeInstance(true)->getConfigurableAttributesAsArray($product);
                    $attributeOptions = array();
                    foreach ($productAttributeOptions as $productAttribute) {
                        foreach ($productAttribute['values'] as $attribute) {
                            $attributeOptions[$productAttribute['label']][$attribute['value_index']] = $attribute['store_label'];
                        }
                    }
                    $childProducts = Mage::getModel('catalog/product_type_configurable')->getUsedProducts(null,$product);
                    foreach ($childProducts as $child) {
                        $childrenTempV1 =array();
                        $type = $child->getData('type_id');
                        $parent_id = $child->getData('parent_id');
                        $temp = array();
                        foreach($productAttributeOptions as $productAttribute){
                            $value_index = $child->getData($productAttribute['attribute_code']);
                            $tempV1 = array();
                            foreach ($productAttribute['values'] as $attribute) {
                                if($attribute['value_index'] === $value_index){
                                    $tempV1[]=$temp[] = array(
                                            'value'=>$attribute,
                                            'product_id' =>$child->getId(),
                                            'parent_id' =>$parent_id,
                                            'sku' =>$child->getSku(),
                                            'name' =>$child->getName(),
                                            'type' =>$type,
                                            'stocklevel' =>Mage::getModel('cataloginventory/stock_item')->loadByProduct($child)->getQty(),
                                    );
                                }
                            }
                            $childrenTempV1[] =array(
                                'attribute_id'=>$productAttribute['attribute_id'],
                                'attribute_code'=>$productAttribute['attribute_code'],
                                'frontend_label'=>$productAttribute['frontend_label'],
                                'store_label'=>$productAttribute['store_label'],
                                'position'=>$productAttribute['position'],
                                'label'=>$productAttribute['label'],
                                'use_default'=>$productAttribute['use_default'],
                                'childValue' => $tempV1
                            );
                        }
                        $childrenVariantV1[] =$childrenTempV1;
                        $childrenVariant[] =$temp;
                        $color = $child->getData('color');
                        $size = $child->getData('size');
                        $children[] =array(
                            "product_id" => $child->getId(),
                            "parent_id" => $parent_id,
                            'sku'        => $child->getSku(),
                            'size'        => isset($attributeOptions['Size'][$size]) ? $attributeOptions['Size'][$size] : "",
                            'color'        => isset($attributeOptions['Color'][$color]) ? $attributeOptions['Color'][$color] : "" ,
                            'type'        => $type,
                            'stocklevel'       =>Mage::getModel('cataloginventory/stock_item')->loadByProduct($child)->getQty()

                        );
                    }

                }
                $type_id = $product->getTypeId();
                $productsEntity = new stdClass();
                $productsEntity->product_id 	= $product->getId();
                $productsEntity->product_name 	= $product->getName();
                $productsEntity->sku 	= $product->getSku();
                $productsEntity->short_description	= base64_encode($product->getShortDescription());
                $productsEntity->description	= base64_encode($product->getDescription());
                $productsEntity->type_id	= $type_id;
                $productsEntity->price 	= $product->getPrice();
                $productsEntity->special_price  = $product->getSpecialPrice();
                $productsEntity->special_from_date  = $product->getSpecialFromDate();
                $productsEntity->special_to_date  = $product->getSpecialToDate();
                $productsEntity->tax_class_id  = $product->getTaxClassId();
                $productsEntity->tier_price  = $product->getTierPrice();
                $productsEntity->weight  = $product->getWeight();
                $productsEntity->product_url = $product->getProductUrl();
                $productsEntity->visibility  = $product->getVisibility();
                $productsEntity->status	= $product->getStatus();
                $productsEntity->created_at 	= $product->getCreatedAt();
                $productsEntity->updated_at 	= $product->getUpdatedAt();
                $productsEntity->img 	= $product->getImageUrl();
                $productMediaConfig = Mage::getModel('catalog/product_media_config');
                $baseImageUrl = $productMediaConfig->getMediaUrl($product->getImage());
                $smallImageUrl =  $product->getImageUrl();
                $productsEntity->imgGroup = array(
                    'baseImageUrl' => $baseImageUrl,
                    'smallImageUrl' => $smallImageUrl
                );
//                $galleryData = $product->getData('media_gallery');
                //gets the image url of the product
//                $imageObject = $product->load('media_gallery');
                $imageObject = Mage::getModel('catalog/product')->load($product->getId())->getMediaGalleryImages();//->toArray(array('id','label','product_id','position','url','path'));
                $arrImages = $imageObject->toArray(array('id','label','product_id','position','url','path'));
                $productsEntity->totalRecords = 0;//Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA).'catalog/product';
                $productsEntity->product_images = array();
                if(count($arrImages)>0){
                    $productsEntity->totalRecords = $arrImages['totalRecords'];
                    $i=0;
                    foreach($imageObject as $_image){
                        $arrImages['items'][$i]['thumbnail'] = (string)Mage::helper('catalog/image')->init($product, 'thumbnail', $_image->getFile())->resize(200);
                        $arrImages['items'][$i]['small_image'] = (string)Mage::helper('catalog/image')->init($product, 'small_image', $_image->getFile())->resize(640,960);
                        $i++;
                    }
                    unset($i);
                    $productsEntity->product_images = $arrImages['items'];
                }
                $productsEntity->quantity = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product)->getQty();
                $productsEntity->getIsInStock = Mage::getModel('cataloginventory/stock_item')->loadByProduct($product)->getIsInStock();
                $productsEntity->childrenVariant = $childrenVariant;
                $productsEntity->childrenVariantV1 = $childrenVariantV1;
                $productsEntity->children  = $children;
                $productsEntity->current_category = $product->getCategoryId();
                $productsEntity->categories = $product->getCategoryIds();
                $productsEntity->websites = $product->getWebsiteIds();
                $productsEntity->attribute = $this->_getAttrGroup($product->getId());

                if (strtolower($type_id) === 'downloadable' && $product->hasData('links_exist')) {
                    $linkSamplesArr = $this->getLinkData($product);
                    $productsEntity->linkSamples = $linkSamplesArr;
                }
                $products[]     = $productsEntity;
            }
        }

        return json_encode($products);
    }
    /**
     * Retrieve samples array
     *
     * @param Mage_Catalog_Model_Product $product
     * @return array
     *
     */
    public function getSampleData($product = null)
    {
        if($product === null)
        {
            return array();
        }
        $samplesArr = array();
        if (is_null($product->getDownloadableSamples())) {
            $_sampleCollection = Mage::getModel('downloadable/sample')->getCollection()
                ->addProductToFilter($product->getId())
                ->addTitleToResult($product->getStoreId());
            $product->setDownloadableSamples($_sampleCollection);
        }
        $samples =  $product->getDownloadableSamples();
        foreach ($samples as $item) {
            $tmpSampleItem = array(
                'sample_id' => $item->getId(),
                'title' => Mage::helper('core')->escapeHtml($item->getTitle(), null),
                'sample_url' => $item->getSampleUrl(),
                'sample_type' => $item->getSampleType(),
                'sort_order' => $item->getSortOrder(),
            );
            $file = Mage::helper('downloadable/file')->getFilePath(
                Mage_Downloadable_Model_Sample::getBasePath(), $item->getSampleFile()
            );
            if ($item->getSampleFile() && !is_file($file)) {
                Mage::helper('core/file_storage_database')->saveFileToFilesystem($file);
            }
            if ($item->getSampleFile() && is_file($file)) {
                $tmpSampleItem['file_save'] = array(
                    array(
                        'sampleUrl' => $this->getSampleUrl($item),
                        'file' => $item->getSampleFile(),
                        'name' => Mage::helper('downloadable/file')->getFileFromPathFile($item->getSampleFile()),
                        'size' => filesize($file),
                        'status' => 'old'
                    ));
            }
            if ($product && $item->getStoreTitle()) {
                $tmpSampleItem['store_title'] = $item->getStoreTitle();
            }
            $samplesArr[] = $tmpSampleItem;
        }

        return $samplesArr;
    }
    /**
     * Return array of links
     *
     * @param Mage_Catalog_Model_Product $product
     * @return array
     *
     */
    public function getLinkData($product = null)
    {
        if($product === null)
        {
            return array();
        }
        $linkArr = array();
        $links = $product->getTypeInstance(true)->getLinks($product);
        $downloadHelper = Mage::helper('downloadable');
        foreach ($links as $item) {
            $file = Mage::helper('downloadable/file')->getFilePath(
                Mage_Downloadable_Model_Link::getBasePath(), $item->getLinkFile()
            );
            $tmpLinkItem = array(
                'link_id' => $item->getId(),
                'product_id' => $item->getProductId(),
                'title' => $item->getTitle(),
                'store_title' => $item->getStoreTitle(),
                'price' => $item->getPrice(),
                'number_of_downloads' => $item->getNumberOfDownloads(),
                'shareable' => $item->getIsShareable(),
                'is_shareable' => Mage::helper('downloadable')->getIsShareable($item),
//                'link_file' => $item->getLinkFile(),
//                'link_name' => Mage::helper('downloadable/file')->getFileFromPathFile($item->getLinkFile()),
//                'link_url' => $item->getLinkUrl(),
//                'link_type' => $item->getLinkType(),
                'linkSampleUrl' => $this->getLinkSamlpeUrl($item),
                'sample_file' => $item->getSampleFile(),
                'sample_name' => Mage::helper('downloadable/file')->getFileFromPathFile($item->getSampleFile()),
                'sample_url' => $item->getSampleUrl(),
                'sample_type' => $item->getSampleType(),
                'sort_order' => $item->getSortOrder(),
                'size' => filesize($file)
            );
            if ($item->getNumberOfDownloads() == '0') {
                $tmpLinkItem['is_unlimited'] = 1;
            }
            if ($product->getStoreId() && $item->getStoreTitle()) {
                $tmpLinkItem['store_title'] = $item->getStoreTitle();
            }
            if ($product->getStoreId() && $downloadHelper->getIsPriceWebsiteScope()) {
                $tmpLinkItem['website_price'] = $item->getWebsitePrice();
            }
            $linkArr[] = $tmpLinkItem;
        }
        unset($item);
        unset($tmpLinkItem);
        unset($links);

        $samples = $product->getTypeInstance(true)->getSamples($product);
        $samplesArr = array();
        foreach ($samples as $item) {
            $file = Mage::helper('downloadable/file')->getFilePath(
                Mage_Downloadable_Model_Sample::getBasePath(), $item->getSampleFile()
            );
            $sampleName = Mage::helper('downloadable/file')->getFileFromPathFile($item->getSampleFile());
            $tmpSampleItem = array(
                'sample_id' => $item->getId(),
                'product_id' => $item->getProductId(),
                'title' => Mage::helper('core')->escapeHtml($item->getTitle(), null),
                'store_title' =>  $item->getStoreTitle(),
                'linkSampleUrl' => $this->getSampleUrl($item),
                'sample_name' => (!empty($sampleName))? $sampleName: '',
                'sample_url' => $item->getSampleUrl(),
                'sample_file' => $item->getSampleFile(),
                'sample_type' => $item->getSampleType(),
                'sort_order' => $item->getSortOrder(),
                'size' => filesize($file)
            );
            $samplesArr[] = $tmpSampleItem;
        }
        return array('links' => $linkArr, 'samples' => $samplesArr);
    }


    /**
     * Retrieve Page info
     *
     * @param int $pageSize
     * @param int $mode
     * @param date $fromDate
     * @return array
     */
    public function pageInfo($pageSize,$mode,$fromDate = null){
        $todayDate = date('Y-m-d', strtotime("+1 day"));
        if($fromDate){
            $fromDate = date('Y-m-d', strtotime($fromDate));
            $filterDate = array(
                'from'=> $fromDate,
                'to'=> $todayDate,
                'date'=>true
            );
        }else{
            $filterDate = array(
                'to'=> $todayDate,
                'date'=>true
            );
        }

        $productsEntity = new stdClass();
        // mode = 1 : product
        if($mode === 1){
            $collection = Mage::getModel('catalog/product')->getCollection()->addAttributeToFilter('updated_at',$filterDate);
        }
        // mode = 2 : category
        else if($mode === 2){
            $collection = Mage::getModel('catalog/category')->getCollection()->addAttributeToFilter('updated_at',$filterDate);
        }else
        {
            return $productsEntity;
        }
        // limit number of results returned
        $collection =  $collection->setPageSize($pageSize)
            ->setCurPage(1); // set the offset (useful for pagination)
        if($collection) {
            $productsEntity->limit = $collection->getPageSize();
            $productsEntity->page = $collection->getCurPage();
            $productsEntity->totalPage = $collection->getLastPageNumber();
            $productsEntity->totalItems = $collection->getSize();
        }
//        $result = json_encode($productsEntity);
        return json_encode($productsEntity);
    }
    /**
     * Retrieve Category by page
     *
     * @param int $pageSize
     * @param int $curPage
     * @param date $fromDate
     * @return array
     */
    public function categoryList($pageSize = 100 ,$curPage = 1,$fromDate = null)
    {
        $todayDate= date('Y-m-d', strtotime("+1 day"));
        if($fromDate){
            $fromDate = date('Y-m-d', strtotime($fromDate));
            $filterDate = array(
                'from'=> $fromDate,
                'to'=> $todayDate
            );
        }else{
            $filterDate = array(
                'to'=> $todayDate
            );
        }


        try{
            $categories = Mage::getModel('catalog/category')->getCollection()
                ->addAttributeToSelect('*')
                ->addAttributeToFilter('updated_at',$filterDate)
                ->setPageSize($pageSize) // limit number of results returned
                ->setCurPage($curPage); // set the offset (useful for pagination)
            $data = array();
            foreach ($categories as $category)
            {
                $categoryEntity = new stdClass();
                $categoryEntity->category_id  = $category->getId();
                $categoryEntity->product_count  = $category->getProductCount();
                $categoryEntity->url  = $category->getUrl();
                $categoryEntity->name  = $category->getName();
                $categoryEntity->level  = $category->getLevel();
                $categoryEntity->image  = $category->getImageUrl();
                $categoryEntity->isActive  = $category->getIsActive();
                $categoryEntity->product_collection  = $category->getProductCollection()->addAttributeToSelect(array('product_id', 'sku', 'name', 'set', 'type','category_ids','website_ids'));
                $categoryEntity->parent_id  = $category->getParentId();
                $categoryEntity->all_children  = $category->getAllChildren(true);
                $categoryEntity->children  = $category->getChildren();
                $categoryEntity->children_count  = $category->getChildrenCount();
                $categoryEntity->available_sort_by  = $category->getAvailableSortBy();
                $categoryEntity->default_sort_by  = $category->getDefaultSortBy();
                $categoryEntity->position  = $category->getPosition();
                $categoryEntity->created_at 	= $category->getCreatedAt();
                $categoryEntity->updated_at 	= $category->getUpdatedAt();
                $data[] = $categoryEntity;
            }
        } catch (Mage_Core_Exception $e) {
        $this->_fault('category '. $e->getMessage());
        }

        return json_encode($data);
    }
    /**
     *
     * Get list store and website info
     * @return string
     *
     */
    public function info() {
      return json_encode($this->tree());
    }
    /**
     *
     * Get list gateway active
     * @return string
     *
     */
    public function getListGatewayActive(){
//        Sniip_Sniipsync_Model_Sniipgateway::setCheckout();
        $payments = Mage::getSingleton('payment/config')->getActiveMethods();
        $payMethods = array();
        foreach ($payments as $paymentCode=>$paymentModel)
        {
            $paymentTitle = Mage::getStoreConfig('payment/'.$paymentCode.'/title');
            $payMethods[$paymentCode] = $paymentTitle;
        }
        return json_encode($payMethods);
    }
    /**
     * Retrieve Store Info
     *
     * @param string|int $store
     * @return array
     */
    protected function getStoreInfo($store) {
        return array(
            'id'                  => intval($store->getId()),          // Integer
            'code'                => $store->getCode(),                // String
            'website_id'          => intval($store->getWebsiteId()),   // Integer
            'group_id'            => intval($store->getGroupId()),     // Integer
            'name'                => $store->getName(),                // String
            'sort_order'          => intval($store->getSortOrder()),   // Integer
            'is_active'           => ($store->getIsActive()  == true), // Boolean
            // How to get store configs: http://alanstorm.com/magento_loading_config_variables
            'address'         => Mage::getStoreConfig('general/store_information/address', $store->getId()),
            'locale_code'         => Mage::getStoreConfig('general/locale/code', $store->getId()),
            'copyright'           => Mage::getStoreConfig('design/footer/copyright', $store->getId()),
            'version'             => 'SNIIP_1.0.9.7'
            // note: some more settings available
        );
    }
    /**
     * Retrieve website tree
     *
     * @return array
     */
    protected function tree()
    {

        $websites = Mage::app()->getWebsites();
        // Make result array
        $result = array();
        $website_results = array();
        $group_results = array();
        $store_results = array();
        foreach ($websites as $website) {
            foreach ($website->getGroups() as $group) {
                $stores = $group->getStores();
                foreach ($stores as $store) {
                    $store_results[] = $this->getStoreInfo($store);
                }
                $group_results[] = array(
                    'id'                  => intval($group->getId()),
                    'name'                => $group->getName(),
                    'default_store_id'    => intval($group->getDefaultStoreId()),
                    'root_cagetory_id'    => intval($group->getRootCategoryId()),
                    'stores'              => $store_results
                );
                unset($store_results);
            }
            $website_results[] = array(
                'id'                  => intval($website->getId()),
                'name'                => $website->getName(),
                'code'                => $website->getCode(),
                'address'             => Mage::getStoreConfig('general/store_information/address'),
                'sort_order'          => intval($website->getSortOrder()),
                'default_group_id'    => intval($website->getDefaultGroup()->getId()),
                'default_group_name'  => $website->getDefaultGroup()->getName(),
                'groups'              => $group_results
            );
            unset($group_results);
        }
        return $website_results;
    }
    /**
     * Retrieve Product info
     * @param string|int $productId
     * @return Mage_Catalog_Model_Product
     */
    private function _getProduct($productId)
    {
        $product = Mage::getModel('catalog/product')->load($productId);
        if ($product) {
            return $product;
        }
        return NULL;
    }
    /**
     * Retrieve  Attribute of product by page
     *
     * @param int $productId
     * @return string
     */
    public function getAttrProduct($productId)
    {
        return json_encode($this->_getAttrGroup($productId));
    }
    /**
     * Retrieve All Attribute of product by page
     *
     * @param int $pageSize
     * @param int $curPage
     * @return string
     */
    public function getAttrAllProduct($pageSize = 100 ,$curPage = 1)
    {
        $collection = Mage::getModel('catalog/product')->getCollection()
            ->setPageSize($pageSize) // limit number of results returned
            ->setCurPage($curPage); // set the offset (useful for pagination)
        $arrAttr = array();
        foreach ($collection as $product) {
            $arrAttr[$product->getId()] = $this->_getAttrGroup($product->getId());
        }

        return json_encode($arrAttr);
    }
    /**
     * Retrieve Attribute Set Group Tree as JSON format
     * @param  $productId
     * @return array
     */
    private function _getAttrGroup($productId)
    {
        $productE = $this->_getProduct($productId);
        if ($productE === NULL) {
            return NULL;
        }
        $setId = $productE->getAttributeSetId(); // Attribute set Id
        $groups = Mage::getModel('eav/entity_attribute_group')
            ->getResourceCollection()
            ->setAttributeSetFilter($setId)
            ->setSortOrder()
            ->load();

        $patern = '/General|Prices|Meta Information|Images|Recurring Profile|Design|Gift Options/i';
        $data = array();
        foreach ($groups as $group) {
            $groupName          = $group->getAttributeGroupName();
            $groupId            = $group->getAttributeGroupId();
            if (!empty($groupName) && !preg_match($patern, $groupName)) {
                if (!array_key_exists($groupId, $data)) {
                    $data[$groupId] = array(
                        'group_id' => $groupId,
                        'group_name' => $groupName,
                        'product_id' => $productId
                    );
                }
                $attributes = Mage::getResourceModel('catalog/product_attribute_collection')
                    ->setAttributeGroupFilter($groupId)
                    ->addVisibleFilter()
                    ->checkConfigurableProducts()
                    ->load();
                if ($attributes->getSize() > 0) {
                    foreach ($attributes->getItems() as $attribute) {
                        /* @var $child Mage_Eav_Model_Entity_Attribute */

                        $value = $attribute->getFrontend()->getValue($productE);
                        if ($productE->hasData($attribute->getAttributeCode())) {

                            $data[$groupId]['items'][$attribute->getAttributeCode()] = array(
                                'attribute_label' => $attribute->getFrontend()->getLabel(),
                                'value' => $value,
                                'valuebase64' => base64_encode($value),
                                'attribute_code'  => $attribute->getAttributeCode(),
                                'is_configurable'  => $attribute->getIsConfigurable(),
                                'is_visible_on_front'  => $attribute->getIsVisibleOnFront(),
                                'attribute_id' => $attribute->getId(),
                            );
                        }
                    }
                }
            }
        }

        return $data;
    }
    /**
     * @param  $quoteId
     * @param  $paymentData
     * @param  $store
     * @return bool
     */
    public function setPaymentMethod($quoteId, $paymentData, $store = null)
    {

        $quote = $this->_getQuote($store, $quoteId);
        $store = $quote->getStoreId();
        $paymentData = $this->_preparePaymentData($paymentData);

        if (empty($paymentData)) {
            $this->_fault("payment_method_empty");
        }

        if ($quote->isVirtual()) {
            // check if billing address is set

            if (is_null($quote->getBillingAddress()->getId())) {
                $this->_fault('billing_address_is_not_set');
            }
            $quote->getBillingAddress()->setPaymentMethod(
                isset($paymentData['method']) ? $paymentData['method'] : null
            );
        } else {
            // check if shipping address is set
            if (is_null($quote->getShippingAddress()->getId())) {
//                $this->_fault('shipping_address_is_not_set');
            }
            $quote->getShippingAddress()->setPaymentMethod(
                isset($paymentData['method']) ? $paymentData['method'] : null
            );
        }
        if (!$quote->isVirtual() && $quote->getShippingAddress()) {

//            $quote->getShippingAddress()->setCollectShippingRates(true);
        }
        $total = $quote->getBaseSubtotal();
        $methods = Mage::helper('payment')->getStoreMethods($store, $quote);
        foreach ($methods as $method) {
            if ($method->getCode() == $paymentData['method']) {
                /** @var $method Mage_Payment_Model_Method_Abstract */
                if (!($this->_canUsePaymentMethod($method, $quote)
                    && ($total != 0
                        || $method->getCode() == 'free'
                        || ($quote->hasRecurringItems() && $method->canManageRecurringProfiles())))
                ) {
                    $this->_fault("method_not_allowed");
                }
            }
        }

        try {
            $payment = $quote->getPayment();
            $payment->importData($paymentData);


            $quote->setTotalsCollectedFlag(false)
                ->collectTotals()
                ->save();
        } catch (Mage_Core_Exception $e) {
            $this->_fault('payment_method_is_not_set'. $e->getMessage());
        }
        return true;
    }
    /**
     * @param  $method
     * @param  $quote
     * @return bool
     */
    protected function _canUsePaymentMethod($method, $quote)
    {
        if (!($method->isGateway() || $method->canUseInternal())) {
            return false;
        }

        if (!$method->canUseForCountry($quote->getBillingAddress()->getCountry())) {
            return false;
        }

        if (!$method->canUseForCurrency(Mage::app()->getStore($quote->getStoreId())->getBaseCurrencyCode())) {
            return false;
        }

        /**
         * Checking for min/max order total for assigned payment method
         */
        $total = $quote->getBaseGrandTotal();
        $minTotal = $method->getConfigData('min_order_total');
        $maxTotal = $method->getConfigData('max_order_total');

        if ((!empty($minTotal) && ($total < $minTotal)) || (!empty($maxTotal) && ($total > $maxTotal))) {
            return false;
        }

        return true;
    }

//
//    /**
//     * Get downloadable product samples
//     *
//     * @param Mage_Catalog_Model_Product $product
//     * @return Mage_Downloadable_Model_Mysql4_Sample_Collection
//     */
//    protected function getSamples($product = null)
//    {
//        if($product === null)
//        {
//            return null;
//        }
//        /* @var Mage_Catalog_Model_Product $product */
//        if (is_null($product->getDownloadableSamples())) {
//            $_sampleCollection = Mage::getModel('downloadable/sample')->getCollection()
//                ->addProductToFilter($product->getId())
//                ->addTitleToResult($product->getStoreId());
//            $product->setDownloadableSamples($_sampleCollection);
//        }
//
//        return $product->getDownloadableSamples();
//    }


}
