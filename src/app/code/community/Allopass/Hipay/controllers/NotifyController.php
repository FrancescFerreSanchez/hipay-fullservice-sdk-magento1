<?php

/**
 * HiPay Fullservice SDK Magento 1
 *
 * 2018 HiPay
 *
 * NOTICE OF LICENSE
 *
 * @author    HiPay <support.tpp@hipay.com>
 * @copyright 2018 HiPay
 * @license   https://github.com/hipay/hipay-fullservice-sdk-magento1/blob/master/LICENSE.md
 */

/**
 *
 *
 * @author      HiPay <support.tpp@hipay.com>
 * @copyright   Copyright (c) 2018 - HiPay
 * @license     https://github.com/hipay/hipay-fullservice-sdk-magento1/blob/master/LICENSE.md
 * @link    https://github.com/hipay/hipay-fullservice-sdk-magento1
 */
class Allopass_Hipay_NotifyController extends Mage_Core_Controller_Front_Action
{
    /**
     *
     * @var Mage_Sales_Model_Order $order
     */
    protected $_order = null;

    /**
     * Validate signature
     *
     * @param $order
     * @param $isMoto
     * @return bool
     */
    protected function _validateSignature($order, $isMoto)
    {
        /* @var $_helper Allopass_Hipay_Helper_Data */
        $_helper = Mage::helper('hipay');
        $signature = $this->getRequest()->getServer('HTTP_X_ALLOPASS_SIGNATURE');
        return $_helper->checkSignature($signature, true, $order, $isMoto);
    }


    public function indexAction()
    {
        /* @var $response Allopass_Hipay_Model_Api_Response_Notification */
        $response = Mage::getSingleton('hipay/api_response_notification', $this->getRequest()->getParams());
        $orderArr = $response->getOrder();
        $order = Mage::getModel('sales/order')->loadByIncrementId($orderArr['id']);

        if (!$order->getId()
            && (strpos($orderArr['id'], 'recurring') === false
                && strpos($orderArr['id'], 'split') === false)
        ) {
            $this->getResponse()->setBody("Order not found in notification");
            $this->getResponse()->setHttpResponseCode(403);
            return;
        }

        $isSplitPayment = false;
        if (strpos($orderArr['id'], 'recurring') !== false) {
            list($action, $type, $profileId) = explode("-", $orderArr['id']);

            if ($profileId) {
                /* @var $profile Mage_Sales_Model_Recurring_Profile */
                $profile = Mage::getModel('sales/recurring_profile')->load($profileId);
                if (!$profile->getId()) {
                    Mage::app()->getResponse()->setBody(
                        Mage::helper('hipay')->__("Profile for ID: %d doesn't exists (Recurring).", $profileId)
                    );
                }
            } else {
                Mage::app()->getResponse()->setBody(Mage::helper('hipay')->__("Order Id not present (Recurring)."));
            }
        } elseif (strpos($orderArr['id'], 'split') !== false) {
            list($id, $type, $splitPaymentId) = explode("-", $orderArr['id']);
            /* @var $order Mage_Sales_Model_Order */
            $order = Mage::getModel('sales/order')->loadByIncrementId($id);
            $isSplitPayment = true;
        }

        // Get store ID and validate Signature
        $storeId = $order->getStore()->getId();
        $isMoto = $response->getEci() == 1 ? true : false;
        $order->getPayment()->setAdditionalInformation('isMoto', $isMoto);
        Mage::app()->init($storeId, 'store');

        if (!$this->_validateSignature($order, $isMoto)) {
            $this->getResponse()->setBody("NOK. Wrong Signature! Please check passphrase or hashing configuration.");
            $this->getResponse()->setHttpResponseCode(403);
            return;
        }

        $payment = $order->getPayment();
        /* @var $methodInstance Allopass_Hipay_Model_Method_Abstract */
        $methodInstance = $payment->getMethodInstance();
        $methodInstance->debugData($response->debug());
        $amount = 0;
        if ((int)$response->getRefundedAmount() == 0 && (int)$response->getCapturedAmount() == 0) {
            $amount = $response->getAuthorizedAmount();
        } elseif ((int)$response->getRefundedAmount() == 0 && (int)$response->getCapturedAmount() > 0) {
            $amount = $response->getCapturedAmount();
        } else {
            $amount = $response->getRefundedAmount();
        }

        // Move Notification before processing
        $message = Mage::helper('hipay')->__("Notification from Hipay:")
            . " "
            . Mage::helper('hipay')->__("status")
            . ": code-" . $response->getStatus() . " Message: " . $response->getMessage()
            . " " . Mage::helper('hipay')->__('amount: %s', (string)$amount);

        $order->addStatusToHistory($order->getStatus(), $message);
        $order->save();

        if (!$isSplitPayment) { //If is a part of payment, we do not process reponse
            // THEN processResponse
            $methodInstance->processResponse($response, $payment, $amount);
        }

        return $this;

    }

    /**
     *
     * @param Mage_Sales_Model_Recurring_Profile $profile
     * @param Allopass_Hipay_Model_Api_Response_Notification $response
     * @return Mage_Sales_Model_Order
     */
    protected function createProfileOrder(
        Mage_Sales_Model_Recurring_Profile $profile,
        Allopass_Hipay_Model_Api_Response_Notification $response
    ) {

        $amount = $this->getAmountFromProfile($profile);

        $productItemInfo = new Varien_Object;
        $type = "Regular";
        if ($type == 'Trial') {
            $productItemInfo->setPaymentType(Mage_Sales_Model_Recurring_Profile::PAYMENT_TYPE_TRIAL);
        } elseif ($type == 'Regular') {
            $productItemInfo->setPaymentType(Mage_Sales_Model_Recurring_Profile::PAYMENT_TYPE_REGULAR);
        }

        // because is not auditioned in profile obj
        if ($this->isInitialProfileOrder($profile)) {
            $productItemInfo->setPrice($profile->getBillingAmount() + $profile->getInitAmount());
        }

        /* @var $order Mage_Sales_Model_Order */
        $order = $profile->createOrder($productItemInfo);

        $additionalInfo = $profile->getAdditionalInfo();

        $order->getPayment()->setCcType($additionalInfo['ccType']);
        $order->getPayment()->setCcExpMonth($additionalInfo['ccExpMonth']);
        $order->getPayment()->setCcExpYear($additionalInfo['ccExpYear']);
        $order->getPayment()->setAdditionalInformation('token', $additionalInfo['token']);
        $order->getPayment()->setAdditionalInformation('create_oneclick', $additionalInfo['create_oneclick']);
        $order->getPayment()->setAdditionalInformation('use_oneclick', $additionalInfo['use_oneclick']);

        $order->setState(
            Mage_Sales_Model_Order::STATE_NEW,
            'pending',
            Mage::helper('hipay')->__("New Order Recurring!")
        );

        $order->save();

        $profile->addOrderRelation($order->getId());
        $profile->save();

        return $order;
    }

    /**
     * Add method to calculate amount from recurring profile
     * @param Mage_Sales_Model_Recurring_Profile $profile
     * @return int $amount
     **/
    public function getAmountFromProfile(Mage_Sales_Model_Recurring_Profile $profile)
    {
        $amount = $profile->getBillingAmount() + $profile->getTaxAmount() + $profile->getShippingAmount();

        if ($this->isInitialProfileOrder($profile)) {
            $amount += $profile->getInitAmount();
        }

        return $amount;
    }

    protected function isInitialProfileOrder(Mage_Sales_Model_Recurring_Profile $profile)
    {
        if (!empty($profile->getChildOrderIds()) && current($profile->getChildOrderIds()) == "-1") {
            return true;
        }

        return false;
    }

}
