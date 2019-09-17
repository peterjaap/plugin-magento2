<?php

namespace Trustpilot\Reviews\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use \Magento\Store\Model\ResourceModel\Website\CollectionFactory as WebsiteCollectionFactory;
use Magento\Store\Model\ScopeInterface as StoreScopeInterface;
use \Magento\Store\Model\StoreManagerInterface;
use \Magento\Framework\Api\SearchCriteriaBuilder;
use \Magento\Eav\Api\AttributeRepositoryInterface;
use \Magento\Store\Model\StoreRepository;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use \Magento\Framework\UrlInterface;

class Data extends AbstractHelper
{
    const TRUSTPILOT_SETTINGS = 'trustpilot/trustpilot_general_group/';

    protected $_request;
    protected $_storeManager;
    protected $_categoryCollectionFactory;
    protected $_productCollectionFactory;
    protected $_websiteCollectionFactory;
    protected $_configWriter;
    protected $_searchCriteriaBuilder;
    protected $_attributeRepository;
    protected $_httpClient;
    protected $_storeRepository;
    protected $_integrationAppUrl;
    protected $_reinitableConfig;
    protected $_trustpilotLog;

    public function __construct(
        Context $context,
        StoreManagerInterface $storeManager,
        CategoryCollectionFactory $categoryCollectionFactory,
        ProductCollectionFactory $productCollectionFactory,
        WebsiteCollectionFactory $websiteCollectionFactory,
        WriterInterface $configWriter,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        AttributeRepositoryInterface $attributeRepository,
        TrustpilotHttpClient $httpClient,
        StoreRepository $storeRepository,
        ReinitableConfigInterface $reinitableConfig,
        TrustpilotLog $trustpilotLog
    ) {
        $this->_storeManager = $storeManager;
        $this->_categoryCollectionFactory   = $categoryCollectionFactory;
        $this->_productCollectionFactory    = $productCollectionFactory;
        $this->_websiteCollectionFactory    = $websiteCollectionFactory;
        $this->_searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->_attributeRepository = $attributeRepository;
        $this->_configWriter = $configWriter;
        parent::__construct($context);
        $this->_request = $context->getRequest();
        $this->_httpClient = $httpClient;
        $this->_storeRepository = $storeRepository;
        $this->_integrationAppUrl = \Trustpilot\Reviews\Model\Config::TRUSTPILOT_INTEGRATION_APP_URL;
        $this->_reinitableConfig = $reinitableConfig;
        $this->_trustpilotLog = $trustpilotLog;
    }

    public function getIntegrationAppUrl()
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
            || isset($_SERVER['HTTP_USESSL'])
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https')
            || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on')
            ? "https:" : "http:";
        $domainName = $protocol . $this->_integrationAppUrl;
        return $domainName;
    }

    public function getKey($scope, $storeId)
    {
        return trim(json_decode(self::getConfig('master_settings_field', $storeId, $scope))->general->key);
    }

    private function getDefaultConfigValues($key)
    {
        $config = array();
        $config['master_settings_field'] = json_encode(
            array(
                'general' => array(
                    'key' => '',
                    'invitationTrigger' => 'orderConfirmed',
                    'mappedInvitationTrigger' => array(),
                ),
                'trustbox' => array(
                    'trustboxes' => array(),
                ),
                'skuSelector' => 'none',
                'mpnSelector' => 'none',
                'gtinSelector' => 'none',
                'pastOrderStatuses' => array('processing', 'complete'),
            )
        );
        $config['sync_in_progress'] = 'false';
        $config['show_past_orders_initial'] = 'true';
        $config['past_orders'] = '0';
        $config['failed_orders'] = '{}';
        $config['custom_trustboxes'] = '{}';

        if (isset($config[$key])) {
            return $config[$key];
        }
        return false;
    }

    public function getWebsiteOrStoreId()
    {
        if (strlen($this->_request->getParam('store'))) {
            return (int) $this->_request->getParam('store', 0);
        }
        if (strlen($this->_request->getParam('website'))) {
            return (int) $this->_request->getParam('website', 0);
        }
        if ($this->isAdminPage() && $this->_storeManager->getStore()->getWebsiteId()) {
            return (int) $this->_storeManager->getStore()->getWebsiteId();
        }
        if ($this->_storeManager->getStore()->getStoreId()) {
            return (int) $this->_storeManager->getStore()->getStoreId();
        }
        return 0;
    }

    public function getScope()
    {
        // user is on the admin store level
        if (strlen($this->_request->getParam('store'))) {
            return StoreScopeInterface::SCOPE_STORES;
        }
        // user is on the admin website level
        if (strlen($this->_request->getParam('website'))) {
            return StoreScopeInterface::SCOPE_WEBSITES;
        }
        // is user is on admin page, try to automatically detect his website scope
        if ($this->isAdminPage() && $this->_storeManager->getStore()->getWebsiteId()) {
            return StoreScopeInterface::SCOPE_WEBSITES;
        }
        // user is on the storefront
        if ($this->_storeManager->getStore()->getStoreId()) {
            return StoreScopeInterface::SCOPE_STORES;
        }
        // user at admin default level
        return 'default';
    }

    public function getConfig($config, $storeId, $scope = null)
    {
        $path = self::TRUSTPILOT_SETTINGS . $config;

        if ($scope === null) {
            $scope = $this->getScope();
        } elseif ($scope === 'store') {
            $scope = 'stores';
        } elseif ($scope === 'website') {
            $scope = 'websites';
        }

        $setting = $this->scopeConfig->getValue($path, $scope, $storeId);
        
        return $setting ? $setting : $this->getDefaultConfigValues($config);
    }

    public function setConfig($config, $value, $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeId = 0)
    {
        if ($scope === 'store') {
            $scope = 'stores';
        } elseif ($scope === 'website') {
            $scope = 'websites';
        }
        $this->_configWriter->save(self::TRUSTPILOT_SETTINGS . $config,  $value, $scope, $scopeId);

        $this->_reinitableConfig->reinit();
    }

    public function getVersion() {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $productMetadata = $objectManager->get('Magento\Framework\App\ProductMetadataInterface');
        if (method_exists($productMetadata, 'getVersion')) {
            return $productMetadata->getVersion();
        } else {
            return \Magento\Framework\AppInterface::VERSION;
        }
    }

    public function getPageUrls($scope, $storeId)
    {
        $pageUrls = new \stdClass();
        $pageUrls->landing = $this->getPageUrl('trustpilot_trustbox_homepage', $storeId);
        $pageUrls->category = $this->getPageUrl('trustpilot_trustbox_category', $storeId);
        $pageUrls->product = $this->getPageUrl('trustpilot_trustbox_product', $storeId);
        $customPageUrls = json_decode($this->getConfig('page_urls', $storeId, $scope));
        $urls = (object) array_merge((array) $customPageUrls, (array) $pageUrls);
        return $urls;
    }

    public function getDefaultStoreIdByWebsiteId($websiteId) {
        foreach ($this->_storeManager->getWebsites() as $website) {
            if ($website->getId() === $websiteId) {
                $storeIds = $website->getStoreIds();
                return isset($storeIds[0]) ? $storeIds[0] : 0;
            }
        }
    }

    public function getFirstProduct($scope, $storeId)
    {
        if ($scope === 'website' || $scope === 'websites') {
            $storeId = $this->getDefaultStoreIdByWebsiteId($storeId);
        }
        $collection = $this->_productCollectionFactory->create();
        $collection->addAttributeToSelect('*');
        $collection->setStore($storeId);
        $collection->addStoreFilter($storeId);
        $collection->addAttributeToFilter('status', 1);
        $collection->addAttributeToFilter('visibility', array(2, 3, 4));
        $collection->setPageSize(1);
        return $collection->getFirstItem();
    }

    public function getPageUrl($page, $storeId)
    {
        try {
            $storeCode = $this->_storeManager->getStore($storeId)->getCode();
            switch ($page) {
                case 'trustpilot_trustbox_homepage':
                    return $this->_storeManager->getStore($storeId)->getBaseUrl().'?___store='.$storeCode;
                case 'trustpilot_trustbox_category':
                    $collection = $this->_categoryCollectionFactory->create();
                    $collection->addAttributeToSelect('*');
                    $collection->setStore($storeId);
                    $collection->addAttributeToFilter('is_active', 1);
                    $collection->addAttributeToFilter('children_count', 0);
                    $collection->setPageSize(1);
                    $category = $collection->getFirstItem();
                    $productUrl = strtok($category->getUrl(),'?').'?___store='.$storeCode;
                    return $productUrl;
                case 'trustpilot_trustbox_product':
                    $product = $this->getFirstProduct();
                    $productUrl = strtok($product->setStoreId($storeId)->getUrlInStore(),'?').'?___store='.$storeCode;
                    return $productUrl;
            }
        } catch (\Throwable $e) {
            $description = 'Unable to find URL for a page ' . $page;
            $this->_trustpilotLog->error($e, $description, array(
                'page' => $page,
                'storeId' => $storeId
            ));
            return $this->_storeManager->getStore()->getBaseUrl();
        } catch (\Exception $e) {
            $description = 'Unable to find URL for a page ' . $page;
            $this->_trustpilotLog->error($e, $description, array(
                'page' => $page,
                'storeId' => $storeId
            ));
            return $this->_storeManager->getStore()->getBaseUrl();
        }
    }

    public function getProductIdentificationOptions()
    {
        $fields = array('none', 'sku', 'id');
        $optionalFields = array('upc', 'isbn', 'brand', 'manufacturer');
        $dynamicFields = array('mpn', 'gtin');
        $attrs = array_map(function ($t) { return $t; }, $this->getAttributes());

        foreach ($attrs as $attr) {
            foreach ($optionalFields as $field) {
                if ($attr == $field) {
                    array_push($fields, $field);
                }
            }
            foreach ($dynamicFields as $field) {
                if (stripos($attr, $field) !== false) {
                    array_push($fields, $attr);
                }
            }
        }

        return json_encode($fields);
    }

    private function getAttributes()
    {
        $attr = array();

        $searchCriteria = $this->_searchCriteriaBuilder->create();
        $attributeRepository = $this->_attributeRepository->getList(
            'catalog_product',
            $searchCriteria
        );
        foreach ($attributeRepository->getItems() as $items) {
            array_push($attr, $items->getAttributeCode());
        }
        return $attr;
    }

    public function loadSelector($product, $selector, $childProducts = null)
    {
        $values = array();
        if (!empty($childProducts)) {
            foreach ($childProducts as $childProduct) {
                $value = $this->loadAttributeValue($childProduct, $selector);
                if (!empty($value)) {
                    array_push($values, $value);
                }
            }
        }
        if (!empty($values)) {
            return implode(',', $values);
        } else {
            return $this->loadAttributeValue($product, $selector);
        }
    }

    private function loadAttributeValue($product, $selector)
    {
        try {
            if ($selector == 'id') {
                return (string) $product->getId();
            }
            if ($attribute = $product->getResource()->getAttribute($selector)) {
                $data = $product->getData($selector);
                $label = $attribute->getSource()->getOptionText($data);
                if (is_array($label)) {
                    $label = implode(', ', $label);
                }
                return $label ? $label : (string) $data;
            } else {
                return $label = '';
            }
        } catch(\Throwable $e) {
            $description = 'Unable get attribute value for selector ' . $selector;
            $this->_trustpilotLog->error($e, $description, array(
                'product' => $product,
                'selector' => $selector
            ));
            return '';
        } catch(\Exception $e) {
            $description = 'Unable get attribute value for selector ' . $selector;
            $this->_trustpilotLog->error($e, $description, array(
                'product' => $product,
                'selector' => $selector
            ));
            return '';
        }
    }

    public function getStoreInformation() {
        $stores = $this->_storeRepository->getList();
        $result = array();
        //Each store view is unique
        foreach ($stores as $store) {
            if ($store->isActive() && $store->getId() != 0) {
                $names = array(
                    'site'      => $store->getWebsite()->getName(),
                    'store'     => $store->getGroup()->getName(),
                    'view'      => $store->getName(),                    
                );
                $item = array(
                    'ids'       => array((string) $store->getWebsite()->getId(), (string) $store->getGroupId(), (string) $store->getStoreId()),
                    'names'     => $names,
                    'domain'    => parse_url($store->getBaseUrl(UrlInterface::URL_TYPE_WEB), PHP_URL_HOST),
                );
                array_push($result, $item);
            }
        }
        return  base64_encode(json_encode($result));
    }

    public function isAdminPage() {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $state =  $objectManager->get('Magento\Framework\App\State');
        return 'adminhtml' === $state->getAreaCode();
    }

    public function getBusinessInformation($scope, $scopeId) {
        $config = $this->scopeConfig;
        $useSecure = $config->getValue('web/secure/use_in_frontend', $scope, $scopeId);
        return array(
            'website' => $config->getValue('web/'. ($useSecure ? 'secure' : 'unsecure') .'/base_url', $scope, $scopeId),
            'company' => $config->getValue('general/store_information/name', $scope, $scopeId),
            'name' => $config->getValue('trans_email/ident_general/name', $scope, $scopeId),
            'email' => $config->getValue('trans_email/ident_general/email', $scope, $scopeId),
            'country' => $config->getValue('general/store_information/country_id', $scope, $scopeId),
            'phone' => $config->getValue('general/store_information/phone', $scope, $scopeId)
        );
    }
}
