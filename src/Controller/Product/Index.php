<?php

namespace MageGuide\CatalogListPopup\Controller\Product;

class Index extends \Magento\Framework\App\Action\Action
{
    /**
     * Constructor
     *
     * @param \Magento\Framework\App\Action\Context  $context
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Catalog\Model\ResourceModel\Eav\Attribute $eavModel,
        \Magento\CatalogInventory\Api\StockStateInterface $stockItem,
        \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency,
        \Magento\Catalog\Helper\Image $imageHelper,
        \Magento\Catalog\Block\Product\Gallery $gallery
    ) {
        $this->_resultJsonFactory = $resultJsonFactory;
        $this->request = $request;
        $this->productRepository = $productRepository;
        $this->_storeManager = $storeManager;
        $this->eavModel = $eavModel;
        $this->stockItem = $stockItem;
        $this->priceCurrency = $priceCurrency;
        $this->helper = $imageHelper;
        $this->gallery = $gallery;
        parent::__construct($context);
    }

    /**
     * Execute view action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {       

        if($this->getRequest()->isAjax() ){
            $result = $this->_resultJsonFactory->create();
            $toReturn = Array();
            $productId = null;
            $configProductId = $this->request->getParam('product_id');
            $selectedAttributeData = $this->request->getParam('super_attribute');
            $storeId = $this->_storeManager->getStore()->getId();
            $configProduct = $this->productRepository->getById($configProductId);
            $productTypeInstance = $configProduct->getTypeInstance();
            $productTypeInstance->setStoreFilter($storeId, $configProduct);
            $usedProducts = $productTypeInstance->getUsedProducts($configProduct);
            foreach ($usedProducts as $child) {
                $found = true;
                foreach ($selectedAttributeData as $selectedAttributeId => $selectedOptionId) {
                    $attr = $this->eavModel->load($selectedAttributeId);
                    $attributeCode = $this->eavModel->getAttributeCode();
                    $childsOptionId = $child->getData($attributeCode);
                    //$selectedAttr = $child->getResource()->getAttribute($attributeCode);
                    //$value = $child->getAttributeText($attributeCode);
                    //$childsOptionId = $selectedAttr->getSource()->getOptionId($value);
                    if($childsOptionId !== $selectedOptionId){
                        $found = false;
                    }
                }
                if($found){
                    $productId = $child->getId();
                    break;
                }
            }
            if($productId){
                $product = $this->productRepository->getById($productId);
                $next_stock_msg   = '';
                $cvg_status       = $product->getResource()->getAttribute('cvg_status')->getFrontend()->getValue($product);
                $mg_b2b_qty       = $product->getResource()->getAttribute('mg_b2b_qty')->getFrontend()->getValue($product);
                $mg_b2b_reorder   = $product->getResource()->getAttribute('free_expected')->getFrontend()->getValue($product);
                $current_quantity = $this->stockItem->getStockQty($product->getId(), $product->getStore()->getWebsiteId());
                $availability_msg = $this->getStockStatus($mg_b2b_qty, $mg_b2b_reorder, $cvg_status);
                if($mg_b2b_qty == 0){
                    if(!empty($mg_b2b_reorder)){
                        $reorder_data_raw = explode(':' , $mg_b2b_reorder);
                        $reorder_data_raw = array_filter($reorder_data_raw);
                        foreach ($reorder_data_raw as $reorder_data) {
                            $reorder_data = rtrim($reorder_data, ';');
                            $temp_data_raw = explode(';' , $reorder_data);
                            foreach ($temp_data_raw as $temp_data) {
                                $dates_quantity_raw[] = $temp_data;
                            }
                        }
                        foreach ($dates_quantity_raw as $key => $value) {
                            if ($key % 2 == 0) {
                                $stock_dates[] = $value;
                            } else {
                                $quantities[] = (int)$value;
                            }
                        }
                        $availability_msg = '<span class="availability blue">'.__('?????????????? ??????????????????????').'</span>';
                        $next_stock_msg = '<span class="date">'.$this->getStockDate($stock_dates[0]).'</span>';
                    }else{
                        $availability_msg = '<span class="availability blue">'.__('?????????????? ??????????????????????').'</span>';
                    }
                }
                $storeUrl = $this->_storeManager->getStore()->getBaseUrl();
                $toReturn['availability'] = $availability_msg;
                $toReturn['next_stock'] = $next_stock_msg;
                $toReturn['id'] = $product->getId();
                $toReturn['sku'] = $product->getSku();
                //$toReturn['image'] = "/media/catalog/product".$product->getImage();
                //$toReturn['image'] = $storeUrl.'ontheflyresizer.php?zc=1&w=780&h=380&src='.$storeUrl.'pub/media/catalog/product'.$child->getImage();
                $toReturn['image'] = $this->helper->init($product, 'product_page_large_landscape')->keepAspectRatio(true)->constrainOnly(true)->keepFrame(true)->getUrl();

                $toReturn['price'] = strip_tags($this->priceCurrency->convertAndFormat($product->getFinalPrice()));

                //Return JSON
                $result->setData($toReturn);
            }
            return $result;
        }
    }

    public function getStockStatus($currentB2BQuantity, $reorderB2B, $stockStatus)
    {
        $toReturn = '';
        if(($currentB2BQuantity > 0)){
            if(mb_strpos($stockStatus, '????????????') !== false){
                $toReturn = '<span class="availability orange">'.__('???????????? ??????????????????????????').'</span>';
            }else{
               $toReturn = '<span class="availability green">'.__('?????????? ??????????????????').'</span>';
            }
        }else if (($currentB2BQuantity == 0) && (!empty($reorderB2B))){
            $toReturn = '<span class="availability blue">'.__('?????????????? ??????????????????????').'</span>';
        }else if (($currentB2BQuantity == 0) && (empty($reorderB2B))){
            // $availability_msg = '<span class="availability red">??????????????????????</span>';
            $toReturn = '<span class="availability blue">'.__('?????????????? ??????????????????????').'</span>';
        }

        return $toReturn;
    }

    public function getStockDate($date_raw)
    {
        $toReturn = mb_substr($date_raw, 5);

        if (mb_strpos($toReturn, '??????')):
            $toReturn = str_replace('??????', '01', $toReturn);
        elseif (mb_strpos($toReturn, '??????')):
            $toReturn = str_replace('??????', '02', $toReturn);
        elseif (mb_strpos($toReturn, '??????')):
            $toReturn = str_replace('??????', '03', $toReturn);
        elseif (mb_strpos($toReturn, '??????')):
            $toReturn = str_replace('??????', '04', $toReturn);
        elseif (mb_strpos($toReturn, '??????')):
            $toReturn = str_replace('??????', '05', $toReturn);
        elseif (mb_strpos($toReturn, '????????')):
            $toReturn = str_replace('????????', '06', $toReturn);
        elseif (mb_strpos($toReturn, '????????')):
            $toReturn = str_replace('????????', '07', $toReturn);
        elseif (mb_strpos($toReturn, '??????')):
            $toReturn = str_replace('??????', '08', $toReturn);
        elseif (mb_strpos($toReturn, '??????')):
            $toReturn = str_replace('??????', '09', $toReturn);
        elseif (mb_strpos($toReturn, '??????')):
            $toReturn = str_replace('??????', '10', $toReturn);
        elseif (mb_strpos($toReturn, '??????')):
            $toReturn = str_replace('??????', '11', $toReturn);
        elseif (mb_strpos($toReturn, '??????')):
            $toReturn = str_replace('??????', '12', $toReturn);
        endif;

        return $toReturn;
    }
}