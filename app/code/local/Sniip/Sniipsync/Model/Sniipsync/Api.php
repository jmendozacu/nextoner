<?php
class Sniip_Sniipsync_Model_Sniipsync_Api extends Sniip_Sniipsync_Model_Api_Resource
{
    /**
     * @param  array of array|object $productsData
     * @param  array of object $customerData
     * @param  array of array|object $customerAddressData
     * @param  int $quoteId
     * @param  array of array|object $customerAddressData
     * @param  int|string $store
     * @return string
     */
    public function getCartInfo($productsData,$customerData,$customerAddressData, $quoteId= null, $store = null)
    {
        $quote = $this->_createOrGetCartInfo($quoteId,$store);
        $quoteId = $quote->getId();
        $this->_insert_updateProductToCart($quote,$productsData,$store);
        $this->_setCustomerToCart($quoteId,$quote,$customerData,$customerAddressData,$store);
        $ratesResult = $this->_getShippingMethod($quoteId,$store);
        $result = $this->_getCartInfo($quoteId,$store,$ratesResult);
        return json_encode($result);
    }
    private function _getShippingMethod($quoteId,$store){
        $quote = $this->_getQuote($quoteId, $store);
        $quoteShippingAddress = $quote->getShippingAddress();
        if (is_null($quoteShippingAddress->getId())) {
            $this->_fault("shipping_address_is_not_set");
        }
        try {
            $quoteShippingAddress->collectShippingRates()->save();
            $groupedRates = $quoteShippingAddress->getGroupedAllShippingRates();

            $ratesResult = array();
            foreach ($groupedRates as $carrierCode => $rates ) {
                $carrierName = $carrierCode;
                if (!is_null(Mage::getStoreConfig('carriers/'.$carrierCode.'/title'))) {
                    $carrierName = Mage::getStoreConfig('carriers/'.$carrierCode.'/title');
                }

                foreach ($rates as $rate) {
                    $rateItem = $this->_getAttributes($rate, "quote_shipping_rate");
                    $rateItem['carrierName'] = $carrierName;
                    $ratesResult[] = array(
                        'code'=>$rateItem['code'],
                        'carrier'=>$rateItem['carrier'],
                        'carrier_title'=>$rateItem['carrier_title'],
                        'method'=>$rateItem['method'],
                        'method_title'=>$rateItem['method_title'],
                        'price'=>(float) $rateItem['price']
                    );
                    unset($rateItem);
                }
            }
        } catch (Mage_Core_Exception $e) {
            $this->_setErrorLog('develop.sniip.com','shipping_method', $e->getMessage());
            $this->_fault('shipping_methods_list_could_not_be_retrived', $e->getMessage());
        }
        return $ratesResult;
    }
    private function _setCustomerToCart($quoteId, $quote,$customerData,$customerAddressData,$store){
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
                    $this->_fault('customer_data_invalid', implode(PHP_EOL, $isCustomerValid));
                }
                break;
        }
        try {
            $quote
                ->setCustomer($customer)
                ->setCheckoutMethod($customer->getMode())
                ->setPasswordHash($customer->encryptPassword($customer->getPassword()))
                ->save();
        } catch (Mage_Core_Exception $e) {
            $this->_fault('customer_not_set', $e->getMessage());
        }
//--------------------------------------set Address ----------------------------------------------------
        $quote = $this->_getQuote($quoteId, $store);
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
            switch($addressMode) {
                case self::ADDRESS_BILLING:
//                    $address->setEmail($addressItem['email']);
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
//                    return $quoteId . json_encode($address->getData());
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
    private function _createOrGetCartInfo($quoteId , $store){
        if($quoteId === null){
            $saleModel = Mage::getModel('sales/quote');
            $saleModel->setStoreId($store)
                ->setIsActive(false)
                ->setIsMultiShipping(false)
                ->save();
        }
        $quoteId = ($quoteId === null) ? $saleModel->getId():$quoteId;
        $quote = $this->_getQuote($quoteId, $store);
        if ($quote->getGiftMessageId() > 0) {
            $quote->setGiftMessage(
                Mage::getSingleton('giftmessage/message')->load($quote->getGiftMessageId())->getMessage()
            );
        }
        return $quote;
    }
    private function _insert_updateProductToCart($quote, $productsData, $store = null){
        if (empty($store)) {
            $store = $quote->getStoreId();
        }
        $productsData = $this->_prepareProductsData($productsData);
        if (empty($productsData)) {
            $this->_fault('invalid_product_data');
        }
        $quote->removeAllItems();
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
            $this->_fault("update_product_fault", implode(PHP_EOL, $errors));
        }
        try {
            $quote->collectTotals()->save();
        } catch (Exception $e) {
            $this->_fault("update_product_quote_save_fault", $e->getMessage());
        }

        return true;
    }
    private function _getCartInfo($quoteId,$store,$ratesResult){
        $quote = $this->_getQuote($quoteId, $store);
        if ($quote->getGiftMessageId() > 0) {
            $quote->setGiftMessage(
                Mage::getSingleton('giftmessage/message')->load($quote->getGiftMessageId())->getMessage()
            );
        }
        $result = $this->_getAttributes($quote, 'quote');

        $result['quote_id'] = $quoteId;
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


    public function items($pageSize = 100 ,$curPage = 1)
    {
        $collection = Mage::getModel('catalog/product')->getCollection()
            ->addAttributeToSelect('*') // select all attributes
            ->setPageSize($pageSize) // limit number of results returned
            ->setCurPage($curPage); // set the offset (useful for pagination)
        $products = array();

        if($collection){
            // we iterate through the list of products to get attribute values
            foreach ($collection as $product) {
                $children = array();

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
                        $color = $child->getData('color');
                        $size = $child->getData('size');
                        $type = $child->getData('type_id');
                        $parent_id = $child->getData('parent_id');
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


                $productsEntity = new stdClass();
                $productsEntity->product_id 	= $product->getId();
                $productsEntity->product_name 	= $product->getName();
                $productsEntity->sku 	= $product->getSku();
                $productsEntity->short_description	= base64_encode($product->getShortDescription());
                $productsEntity->description	= base64_encode($product->getDescription());
                $productsEntity->type_id	= $product->getTypeId();
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
                //gets the image url of the product
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
                $productsEntity->children  = $children;
                $productsEntity->current_category = $product->getCategoryId();
                $productsEntity->categories = $product->getCategoryIds();
                $productsEntity->websites = $product->getWebsiteIds();
                $products[]     = $productsEntity;
            }
        }

        return json_encode($products);
    }
    public function pageInfo($pageSize,$mode){
        $productsEntity = new stdClass();
        // mode = 1 : product
        if($mode === 1){
            $collection = Mage::getModel('catalog/product')->getCollection();
        }
        // mode = 2 : category
        else if($mode === 2){
            $collection = Mage::getModel('catalog/category')->getCollection();
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
    public function categoryList($pageSize = 100 ,$curPage = 1)
    {
        try{
            $categories = Mage::getModel('catalog/category')->getCollection()
                ->addAttributeToSelect('*')
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
        $this->_fault('category ', $e->getMessage());
        }

        return json_encode($data);
    }
    public function info() {
        return json_encode($this->tree());
    }
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
            'version'             => '1.0.7'
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

    private function _getProduct($productId)
    {
        $product = Mage::getModel('catalog/product')->load($productId);
        if ($product) {
            return $product;
        }
        return NULL;
    }

    public function getAttrProduct($productId)
    {
        return json_encode($this->_getAttrGroup($productId));
    }

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
                                'attribute_code'  => $attribute->getAttributeCode(),
                                'attribute_id' => $attribute->getId(),
                            );
                        }
                    }
                }
            }
        }

        return $data;
    }
}
