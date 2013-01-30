<?php
/*****************************************************************************
*       OrderIterator.php
*
*       Author:  ClearHealth Inc. (www.clear-health.com)        2009
*       
*       ClearHealth(TM), HealthCloud(TM), WebVista(TM) and their 
*       respective logos, icons, and terms are registered trademarks 
*       of ClearHealth Inc.
*
*       Though this software is open source you MAY NOT use our 
*       trademarks, graphics, logos and icons without explicit permission. 
*       Derivitive works MUST NOT be primarily identified using our 
*       trademarks, though statements such as "Based on ClearHealth(TM) 
*       Technology" or "incoporating ClearHealth(TM) source code" 
*       are permissible.
*
*       This file is licensed under the GPL V3, you can find
*       a copy of that license by visiting:
*       http://www.fsf.org/licensing/licenses/gpl.html
*       
*****************************************************************************/


class OrderIterator extends WebVista_Model_ORMIterator implements Iterator {

	public function __construct($dbSelect = null,$autoLoad = true) {
		$this->_ormClass = 'Order';
		// autoLoad gives an option to query the entire rows which takes time if more data in the table
		if ($autoLoad) {
			parent::__construct($this->_ormClass,$dbSelect);
		}
	}

	public function setFilter($filters) {
		$this->setFilters($filters);
	}

	public function setFilters($filters) {
		$db = Zend_Registry::get('dbAdapter');
		$dbSelect = $db->select()->from('orders');

		$currentDate = date('Y-m-d');
		foreach ($filters as $key=>$value) {
			switch ($key) {
				case 'current':
					$dbSelect->where("status = 'Active' OR status = 'Pending'");
					break;
				case 'expiring':
					// currently set to 1 week for expiring orders
					$nextDate = date('Y-m-d',strtotime('+1week',strtotime($currentDate)));
					$dbSelect->where("dateStop BETWEEN '{$currentDate}' AND '{$nextDate}'");
					break;
				case 'unsigned':
					$dbSelect->where('eSignatureId = ?',0);
					break;
				case 'recently_expired':
					$dbSelect->where("dateStop LIKE '{$currentDate}%'");
					break;
				case 'patientId':
					$dbSelect->where('patientId = ?',(int)$value);
					break;
				case 'service':
					$dbSelect->where('service = ?',(string)$value);
					break;
				default:
					$dbSelect->where("status = 'Active' OR status = 'Pending' OR dateStart LIKE '{$currentDate}%'");
					break;
			}
		}

		$dbSelect->order('dateTime DESC');
		trigger_error($dbSelect->__toString(),E_USER_NOTICE);
		$this->_dbSelect = $dbSelect;
		$this->_dbStmt = $db->query($this->_dbSelect);
	}

}