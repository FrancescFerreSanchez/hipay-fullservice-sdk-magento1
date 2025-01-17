<?php
/**
 * HiPay Fullservice SDK Magento 1
 *
 * 2020 HiPay
 *
 * NOTICE OF LICENSE
 *
 * @author    HiPay <support.tpp@hipay.com>
 * @copyright 2020 HiPay
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
class Allopass_Hipay_Model_System_Config_Source_MultibancoDelay
{
    protected $_options;

    public function toOptionArray($isMultiselect)
    {
        if (!$this->_options) {
            $this->_options = array(
                3 => Mage::helper('hipay')->__('3 days'),
                30 => Mage::helper('hipay')->__('30 days'),
                90 => Mage::helper('hipay')->__('90 days')
            );
        }
        return $this->_options;
    }
}
