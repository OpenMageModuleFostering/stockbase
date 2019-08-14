<?php

class Divide_Stockbase_Model_Observer
{
    /**
     * Event catch after sale has successfully been paid for. We check your
     * own stock levels against the ordered amount and if your stock has shortage
     * we order the products for you at stockbase. Please login to  your Stockbase 
     * account to see these orders. 
     * 
     * @param Varien_Event_Observer $observerObserver 
     */
    public function afterPayment(Varien_Event_Observer $observer)
    {
        /**
         * @var $order Mage_Sales_Model_Order
         * @var $_product Mage_Sales_Model_Order_Item
         * @var $_stock Mage_CatalogInventory_Model_Stock_Item
         * @var $oStock Mage_CatalogInventory_Model_Stock_Item
         * @var $orderedItem Mage_Sales_Model_Order_Item
         * @var $shipment Mage_Sales_Model_Order_Shipment
         * @var $observer Varien_Event_Observer
         *
         * After invoicing and payment this method will be used to
         * check if your own stock will be enough to fulfill the requested amount.
         * If not, we will take the product and send a order to Stockbase for the
         * amount needed, and the order will be placed @Stockbase. This method
         * is using the sales_order_payment_pay event and will only be activated
         * after payment completes and the order is payed for by the customer.
         */
        $http = Mage::getSingleton('Divide_Stockbase_Helper_HTTP');
        $invoice = $observer->getInvoice();
        $order = $invoice->getOrder();
        $orderedProducts = $order->getAllItems();
        $moduleEnabled = Mage::getStoreConfig('stockbase_options/login/stockbase_active');

        if ($order && $moduleEnabled) {
            // Always rely on own stock first, so stockbase does not always get triggerd.
            $sendItToStockbase = false;
            foreach ($orderedProducts as $orderedProduct) {
                $baseQty = 0;
                $productId = $orderedProduct->getId();
                $baseQty = $orderedProduct->getQtyOrdered()
                    - $orderedProduct->getQtyShipped()
                    - $orderedProduct->getQtyRefunded()
                    - $orderedProduct->getQtyCanceled();
                $finalQty[$productId] = $baseQty;
                $ownStockQty = $orderedProduct->getProduct()->getStockItem()->getQty();

                if ($ownStockQty <= 0 || $ownStockQty <= $baseQty) {
                    $sendItToStockbase = true;
                }
            }
            
            // Only send if own stock is too low
            if ($sendItToStockbase == true) {
                $send = $http->sendMageOrder($order);
                
                // Add status history to order to identify Stockbase orders 
                if ($send) {
                    $order->addStatusHistoryComment('This order was send to stockbase.');
                    $order->save();
                }
            }
        }
    }
}
