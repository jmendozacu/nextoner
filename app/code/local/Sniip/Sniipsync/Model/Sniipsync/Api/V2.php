<?php
class Sniip_Sniipsync_Model_Sniipsync_Api_V2 extends Sniip_Sniipsync_Model_Sniipsync_Api
{
	protected function _prepareData($data)
	{
		if (null !== ($_data = get_object_vars($data))) {
			return parent::_prepareData($_data);
		}
		return array();
	}
}
