<?php

/*
 * @author     M2E Pro Developers Team
 * @copyright  2011-2015 ESS-UA [M2E Pro]
 * @license    Commercial use is forbidden
 */

class Ess_M2ePro_Block_Adminhtml_Listing_AutoAction_Mode_Global extends Mage_Adminhtml_Block_Widget_Form
{
    protected $listing;

    //########################################

    public function __construct()
    {
        parent::__construct();

        // Initialization block
        // ---------------------------------------
        $this->setId('listingAutoActionModeGlobal');
        // ---------------------------------------

        $this->setTemplate('M2ePro/listing/auto_action/mode/global.phtml');
    }

    //########################################

    protected function _prepareForm()
    {
        $form = new Varien_Data_Form(array(
            'id'      => 'edit_form',
            'action'  => $this->getUrl('*/*/save'),
            'method'  => 'post',
            'enctype' => 'multipart/form-data'
        ));

        $form->setUseContainer(true);
        $this->setForm($form);

        return parent::_prepareForm();
    }

    //########################################

    public function hasFormData()
    {
        return $this->getListing()->getData('auto_mode') == Ess_M2ePro_Model_Listing::AUTO_MODE_GLOBAL;
    }

    public function getFormData()
    {
        return $this->getListing()->getData();
    }

    public function getDefault()
    {
        return array(
            'auto_global_adding_mode' => Ess_M2ePro_Model_Listing::ADDING_MODE_NONE,
            'auto_global_adding_add_not_visible' => Ess_M2ePro_Model_Listing::AUTO_ADDING_ADD_NOT_VISIBLE_YES,
            'auto_global_deleting_mode' => Ess_M2ePro_Model_Listing::DELETING_MODE_STOP_REMOVE,
        );
    }

    //########################################

    /**
     * @return Ess_M2ePro_Model_Listing
     * @throws Exception
     */
    public function getListing()
    {
        if (is_null($this->listing)) {
            $listingId = $this->getRequest()->getParam('listing_id');
            $this->listing = Mage::helper('M2ePro/Component')->getCachedUnknownObject('Listing', $listingId);
        }

        return $this->listing;
    }

    //########################################

    protected function _beforeToHtml()
    {
        parent::_beforeToHtml();

        // ---------------------------------------
        $data = array(
            'id'      => 'confirm_button',
            'class'   => 'confirm_button',
            'label'   => Mage::helper('M2ePro')->__('Save'),
            'onclick' => 'ListingAutoActionHandlerObj.confirm();',
        );
        $buttonBlock = $this->getLayout()->createBlock('adminhtml/widget_button')->setData($data);
        $this->setChild('confirm_button', $buttonBlock);
        // ---------------------------------------

        // ---------------------------------------
        $data = array(
            'id'      => 'continue_button',
            'class'   => 'continue_button next',
            'label'   => Mage::helper('M2ePro')->__('Continue'),
            'style'   => 'display: none;',
            'onclick' => '',
        );
        $buttonBlock = $this->getLayout()->createBlock('adminhtml/widget_button')->setData($data);
        $this->setChild('continue_button', $buttonBlock);
        // ---------------------------------------
    }

    //########################################
}
