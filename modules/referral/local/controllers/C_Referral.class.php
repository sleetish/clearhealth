<?php

$loader->requireOnce('includes/EnumManager.class.php');
$loader->requireOnce('includes/refEligibilitySchemaMapper.class.php');
$loader->requireOnce('includes/altPostOffice.class.php');
$loader->requireOnce('includes/refVisitList.class.php');
$loader->requireOnce('datasources/Person_ParticipationProgram_DS.class.php');

$loader->requireOnce('controllers/C_ReferralAttachment.class.php');
$loader->requireOnce('includes/clni/clniAudit.class.php');
$loader->requireOnce('ordo/PersonParticipationProgram.class.php');
$loader->requireOnce('datasources/refRequestList_DS.class.php');
$loader->requireOnce('datasources/refProgramList_DS.class.php');

class C_Referral extends Controller
{
	var $_request = null;
	
	function C_Referral() {
		parent::Controller();
		$uploadAction = Celini::link('add', 'DocSmartStorable', false) . 'folder_id=1&tree_id=1';
		$this->assign('UPLOAD_ACTION', $uploadAction);
	}
	
	function list_action() {
		// Ideally, the Controller needs to know how to get at the various
		// file loaders.  Maybe Controller::getLoader('type')?
		$dsLoader =& new DatasourceFileLoader();
		$dsLoader->load('refRequestList_DS');
		$requestList =& new refRequestList_DS();
		
		$requestListGrid =& new cGrid($requestList);
		$requestListGrid->name = "formDataGrid";
		$requestListGrid->indexCol = false;

		// grab info need for the add referal stuff
		$dsLoader->load('refProgramList_DS');
		$programList =& new refProgramList_DS();
		$programList->clearFilters();
		$this->view->assign('programArray',$programList->toArray('refprogram_id','name'));

		
		$this->view->assign_by_ref('requestListGrid', $requestListGrid);
		return $this->view->fetch(Celini::getTemplatePath('/referral/' . $this->template_mod . '_list.html'));
	}
	
	function actionAdd() {
		$patient_id = $this->get('patient_id', 'c_patient');
		if ($patient_id <= 0) {
                        $this->messages->addMessage(
                                'No Patient Selected',
                                'Please select a patient before attempting to add an encounter.');
                        Celini::redirect('PatientFinder', 'List');
                }
		$patient = ORDataObject::factory('Person',$patient_id);
		//$this->_initPatientData($patient->get('id'));
		$requestList =& new refRequestList_DS();
		
		$requestListGrid =& new cGrid($requestList);
		$requestListGrid->name = "requestGrid";
		$requestListGrid->indexCol = false;
		$requestListGrid->prepare();
		
		// grab info need for the add referal stuff
		$me =& Me::getInstance();
		
		//get list of referral programs connected to patient
                $ppp = ORDataObject::factory('PersonParticipationProgram',$patient->get('person_id'));
		$conProgDS = new refProgramList_DS();
		$conProgDS->setQuery('cols',"pprog.participation_program_id, pprog.name as prog_name");
		$conProgDS->clearAll();
		$progList = $conProgDS->toArray("participation_program_id","prog_name");
                $this->view->assign('progNamesList',$progList);

		$this->view->assign('programArray', $progList);
		
		$this->view->assign('patient_id', $patient->get('id'));
		$this->view->assign('initiator_id', $me->get_person_id());
		
		// setup visit list
		$visitList =& new refVisitList($patient);
		$this->view->assign_by_ref('visitList', $visitList);

		$this->view->assign('FORM_ACTION', Celini::link('edit/0'));
		
		$me =& Me::getInstance();
		$person =& Celini::newORDO('Person', $me->get_person_id());
		//TODO fix permission, referal initator only, used in template
		$this->view->assign('canAdd', true);
		
		if ($this->GET->exists('embedded')) {
			return $this->_minimalAdd();
		}
		else {
			$this->view->assign_by_ref('requestListGrid', $requestListGrid);
			
			return $this->_fullAdd();
		}
	}
	
	function _fullAdd() {
		return $this->view->render('add.html');
	}
	
	function _minimalAdd() {
		$this->view->assign('FORM_ACTION', Celini::link('edit/0', 'referral', 'main'));
		return $this->view->render('addMinimal.html');
	}
	
	function actionView($refRequest_id) {
		$this->view->assign('chlcare_base', isset($GLOBALS['config']['chlcare_base']) ? $GLOBALS['config']['chlcare_base'] : '');
		$ajax =& Celini::ajaxInstance();
		$ajax->jsLibraries[] = array('clniConfirmLink', 'clniPopup');
		
		$request =& Celini::newORDO('refRequest', $refRequest_id);
		$this->_request =& $request;
		$this->view->assign_by_ref('request', $request);
		$requester =& Celini::newORDO('refUser',$request->get('initiator_id'),'ByExternalUserId');
		$this->view->assign_by_ref('requester',$requester);
		
		// log this opening 
		$me =& Me::getInstance();
		$person =& Celini::newORDO('Person', $me->get_person_id());
		$a = new clniAudit();
		$a->logOrdo($request, 'process', 'Opened by ' . $person->get('username'));
		
		
		// Must make $request think it's in persist mode due to some old, pre-value() code.
		$request->_inPersist = true;
		$mostRecentRequest =& Celini::newORDO(
			'refRequest', 
			array($request->get('patient_id'), $request->get('refSpecialty')),
			'MostRecentByPatientAndSpecialty');
		$request->_inPersist = false;
		$this->view->assign_by_ref('mostRecentRequest', $mostRecentRequest);
		if ($mostRecentRequest->isPopulated()) {
			$this->view->assign_by_ref('mostRecentRequestAppointment', $mostRecentRequest->getChild('refAppointment'));
		}
		
		$this->_addOccurence($request);
		$this->_setupEnums();
		
		$program =& Celini::newORDO('refProgram', $request->get('refprogram_id'));
		$this->view->assign_by_ref('refProgram', $program);

		$ppp = PersonParticipationProgram::getByProgramPatient($program->get('refprogram_id'),$request->get('patient_id'));
                $parProg = ORDataObject::factory('ParticipationProgram',$ppp->get('participation_program_id'));
                $optionsClassName = 'ParticipationProgram'. ucwords($parProg->get('class'));
                $GLOBALS['loader']->requireOnce('includes/ParticipationPrograms/'.$optionsClassName.".class.php");
                $options = ORDataObject::factory($optionsClassName, $ppp->get('person_program_id'));
                $this->view->assign('options', $options);
		/*if ($program->get('schema') == 0) {
			$this->view->assign('eligibilitySchema', 'Not Applicable');
		}
		else {
			$schemaMapper =& new refEligibilitySchemaMapper($program->get('schema'));
			$this->view->assign('eligibilitySchema', $schemaMapper);
		}*/
		
		$this->view->assign('FORM_ACTION', Celini::link('view/' . $request->get('id'), 'referral'));
		
		// url to attach files
		$this->view->assign('PRINT_URL', Celini::link('view/' . $request->get('id'), 'referral', 'minimal'));

		$this->_initDocument($request);
		$this->assign_by_ref('initiator', $request->get('initiator'));
		
		//$this->_initPatientData($request->get('patient_id'));
		
		$me =& Me::getInstance();
		$person =& Celini::newORDO('Person', $me->get_person_id());
		
		//TODO fix permission, referral manager only
		//$this->view->assign('editReferralEligibility', $person->isType('Referral Manager', $request->get('refprogram_id')));
		$this->view->assign('editReferralEligibility', true);
		$this->view->assign('editTextFields', true);
		
		// setup eligibility data for patient
		$patientEligibility =& Celini::newORDO('refPatientEligibility', 
			array($request->get('refprogram_id'), $request->get('patient_id')),
			'ByProgramAndPatient'
		);
		$this->view->assign_by_ref('patientEligibility', $patientEligibility);
		
		$em =& Celini::enumManagerInstance();
		$session =& Celini::sessionInstance();
		$session->set('referral:currentProgramId', $request->get('refprogram_id'));
		$fplEnumList =& $em->enumList('federal_poverty_level');
		if ($patientEligibility->get('federal_poverty_level') > 0) {
			while ($fplEnumList->valid()) {
				$fplData =& $fplEnumList->current();
				if ($fplData->key == $patientEligibility->get('federal_poverty_level')) {
					break;
				}
				$fplEnumList->next();
			}
			
			$this->view->assign_by_ref('fplData', $fplData);
		}
		
		$fplList = $em->enumArray('federal_poverty_level');
		$this->view->assign('fplList', $fplList);
		
		
		switch($request->get('refStatus')) {
			//case 3 : // Appointment Pending
				//return $this->_appointmentPendingView($request);
				//break;
			
			case 5 : // Appointment Kept
				return $this->_appointmentKeptView($request);
				break;
			
			default :
				return $this->_defaultView($request);
				break;
		}
	}
	
	function processView($refRequest_id) {
		$cleanRefRequest = $this->POST->getTyped('refRequest', 'htmlsafe');
		$request =& Celini::newORDO('refRequest', $refRequest_id);
		$this->_request =& $request;
		
		if (isset($cleanRefRequest['notes'])) {
			$request->set('notes', $cleanRefRequest['notes']);
		}
		
		if (isset($cleanRefRequest['reason'])) {
			$request->set('reason', $cleanRefRequest['reason']);
		}
		if (isset($cleanRefRequest['history'])) {
			$request->set('history', $cleanRefRequest['history']);
		}
		$request->persist();

		if (isset($_POST['refPatientEligibility'])) {
			$this->_updatePatientEligibility($request);
		}
	}
	
	function _appointmentPendingView(&$request) {
		// reset the refStatusList to only include appointment statuses
		$em =& Celini::enumManagerInstance();
		$refStatusList =& $em->enumList('refStatus');
		$refStatuses = $refStatusList->toArray();
		$refStatusLinks = array();
		foreach ($refStatuses as $key => $value) {
			if (!preg_match('/Appointment|Return/', $value)) {
				unset($refStatuses[$key]);
			}
			else {
				// create URL
				switch ($value) {
					case 'Appointment Kept' : 
						$refStatusLinks[$key] = Celini::link('view', 'refvisit') . 'refRequest_id=' . $request->get('id'). "&process=true";
						break;
					default :
						$refStatusLinks[$key] = Celini::link('changestatus', 'referral') . 'refRequest_id=' . $request->get('id') . '&process=true&refStatus=' . $key;
						break;
				}
			}
		}
		//var_dump($refStatusesLinks);
		
		$this->view->assign('refStatuses', $refStatuses);
		$this->view->assign('refStatusLinks', $refStatusLinks);
		
		$appointment =& Celini::newORDO('refAppointment', $request->get('refappointment_id'));
		$this->view->assign_by_ref('appointment', $appointment);
		return $this->view->render('viewAppointment.html');
	}
	
	function _appointmentKeptView(&$request) {
		// reset the refStatusList to only include appointment statuses
		$em =& Celini::enumManagerInstance();
		
		$appointment =& Celini::newORDO('refAppointment', $request->get('refappointment_id'));
		$this->view->assign_by_ref('appointment', $appointment);
		
		// CHLCare specific code
		$diagnoses =& Celini::newORDO('chlVisitDiagnosis', $request->get('refappointment_id'), 'ByVisit');
		$this->assign_by_ref('diagnoses', $diagnoses);
		
		return $this->view->render('appointmentKept.html');
	}

	
	function _defaultView(&$request) {
		$em =& Celini::enumManagerInstance();
		$this->view->assign('refRejectionReasons', $em->enumArray('refRejectionReason'));
		// URL to change status - has the id appended to it in the template
		$this->assign('CHANGE_STATUS_URL', Celini::link('changestatus', 'referral') . 'refRequest_id=' . $request->get('id') . '&process=true&refStatus=');
		
		$me =& Me::getInstance();
		$person =& Celini::newORDO('Person', $me->get_person_id());
		
		//TODO fix permission
		//$isManager = $person->isType('referral manager', $request->get('refprogram_id'));
		$isManager = true;
		//$isInitiator = $person->isType('referral initiator', $request->get('refprogram_id'));
		$isInitiator = true; 
		if ($isManager || $isInitiator) {
			$refStatusList =& $em->enumList('refStatus');
			$refStatuses = $refStatusList->toArray();
			$refStatusLinks = array();
			foreach ($refStatuses as $key => $value) {
				if ((($request->get('refStatus') == 2 || $request->get('refStatus') == 3 || $request->get('refStatus') == 4 || $request->get('refStatus') == 6) && preg_match('/Appointment/', $value)) || 
					preg_match('/Return/', $value)) {
					// Managers can't fiddle with appointment statuses
					//todo fix permissions
					
					// create URL
					switch ($value) {
						case 'Appointment Kept' :
							$refStatusLinks[$key] = Celini::link('visit', 'Referral') . 'refRequest_id=' . $request->get('id'). "&process=true";
							break;
						
						case 'Appointment Pending' :
							// no need to set it to pending again
							break;
						
						case 'Returned':
							if (!$isManager || preg_match('/Appointment/', $request->value('refStatus'))) {
								break;
							}
						
						case 'Appointment Confirmed' :
							if ($request->value('refStatus') == 'Appointment Confirmed') {
								break;
							}
							
						default :
							$refStatusLinks[$key] = Celini::link('changestatus', 'referral') . 'refRequest_id=' . $request->get('id') . '&process=true&refStatus=' . $key;
							break;
					}
				}
				elseif ($request->get('refStatus') == 7) {
					$refStatusLinks[$key] = Celini::link('changestatus', 'referral') . 'refRequest_id=' . $request->get('id') . '&process=true&refStatus=' . $key;
				}
			}
			$this->view->assign('refStatusLinks', $refStatusLinks);
		}
		
		$appointment =& Celini::newORDO('refAppointment', $request->get('refappointment_id'));
		$this->view->assign_by_ref('appointment', $appointment);
		// URL to add appointment
		//TODO fix permission
		//if ($person->isType('referral manager', $request->get('refprogram_id'))) {
		  if (true) {
			if (!$appointment->isPopulated() || $request->get('refStatus') == 7 || $request->value('refStatus') == 'Requested') {
				$this->assign('APPOINTMENT_BUTTON_URL', Celini::link('add', 'refappointment') . 'refrequest_id=' . $request->get('id'));
			}
			else {
				$this->view->assign('appointmentScheduled', true);
				$requestedStatus = $em->lookupKey('refStatus', 'Requested');
				$this->assign('APPOINTMENT_BUTTON_URL', Celini::link('changestatus', 'referral') . 'refRequest_id=' . $request->get('id') . '&process=true&refStatus=' . $requestedStatus);
				//$this->assign('APPOINTMENT_FORM_ACTION', Celini::link('edit', 'refappointment') . 'refappointment_id=' . (int)$appointment->get('id') . '&embedded');
				
				// setup dates/years
				$dateArray = array();
				for ($i = 1; $i <= 31; $i++) {
					$dateArray[$i] = $i;
				}
				$this->view->assign('dateArray', $dateArray);
				
				$yearArray = array(date('Y', time()) => date('Y', time()));
				for ($i = 1; $i < 2; $i++) {
					$year = date('Y', strtotime('+' . $i . ' year'));
					$yearArray[$year] = $year;;
				}
				$this->view->assign('yearArray', $yearArray);
			}
		}
		
		$this->assign_by_ref('person', $person);
		return $this->view->render('view.html');
	}
	
	function actionEdit($refRequest_id = 0) {
		$me =& Me::getInstance();
		$person =& Celini::newORDO('Person', $me->get_person_id());
		$em =& EnumManager::getInstance();
		$this->assign('em',$em);
		
		$ajax =& Celini::ajaxInstance();
		$ajax->jsLibraries[] = array('clniConfirmBox', 'clniPopup');
		
		$request =& Celini::newORDO('refRequest', $refRequest_id);
		$this->_request =& $request;
		
		$request->set('visit_id', $this->GET->getTyped('visit_id', 'int'));
		$request->set('refprogram_id', $this->GET->getTyped('program_id', 'int'));
		$request->set('patient_id', $this->GET->getTyped('patient_id', 'int'));
		
		$this->view->assign_by_ref('request', $request);
		$program =& Celini::newORDO('refProgram', $this->GET->get('program_id'));
		$ppp = PersonParticipationProgram::getByProgramPatient($program->get('participation_program_id'),$request->get('patient_id'));
		//if patient doesn't already belong to program add them
			
		if (!$ppp->get('person_program_id') >0 ) {
			$ppp->set('start',date('Y-m-d'));
			$ppp->set('end',date('Y-m-d',strtotime ('today +1 year')));
			$ppp->set('expires',0);
			$ppp->set('active',1);
			$ppp->set('person_id',$request->get('patient_id'));
			$ppp->set('participation_program_id',$program->get('refprogram_id'));
			$ppp->persist();
		}
                $parProg = ORDataObject::factory('ParticipationProgram',$ppp->get('participation_program_id')); 
                $optionsClassName = 'ParticipationProgram'. ucwords($parProg->get('class'));
                $GLOBALS['loader']->requireOnce('includes/ParticipationPrograms/'.$optionsClassName.".class.php");
                $options = ORDataObject::factory($optionsClassName, $ppp->get('person_program_id'));
                $this->view->assign('eligibility', $options);
		$this->view->assign_by_ref('refProgram', $program);
		if ($parProg->get('adhoc') == 1) {
			$this->assign('ADHOC_ACTION',Celini::link("form",'Referral') . "form_id=" . $parProg->get('form_id'));
		}
		$this->view->assign_by_ref('pprog', $parProg);
		$this->view->assign_by_ref('personParProgram', $ppp);
				
		$this->_addOccurence($request);
		$this->_setupEnums();
		
		//$this->_initPatientData($this->GET->getTyped('patient_id', 'int'));
		
		$this->view->assign('FORM_ACTION', Celini::link("update/{$refRequest_id}", 'Referral'));
		return $this->view->render('edit.html');
	}
	
	/**
	 * Process changing status on a request.
	 *
	 * All values should be handed in via _GET
	 *
	 * @access protected
	 */
	function processChangeStatus_edit() {
		$request =& Celini::newORDO('refRequest', $this->GET->getTyped('refRequest_id', 'int'));
		$this->_request =& $request;
		$request->set('refStatus', $this->GET->get('refStatus'));
		$request->persist();
		
		// if rejected
		if ($this->GET->exists('reason') && $this->GET->get('refStatus') == 7) {
			$em =& Celini::enumManagerInstance();
			altPostOffice::sendORDONoticeToUser($request, 
				$request->get('initiator_id'), 
				array(
					'due_date' => date('Y-m-d'), 
					'note' => sprintf('<strong>Request %s rejected</strong><br /> %s',
						'<a target="_top" href="' . Celini::link('view/' . $request->get('id')) . '">' . $request->get('id') . '</a>',
						$em->lookup('refRejectionReason', $this->GET->getTyped('reason', 'int'))
					)
				)
			);
			$request->set('reason', $this->GET->getTyped('reason', 'int'));
			$request->persist();
		}
		
		$this->_state =false;
		return $this->actionView($this->GET->getTyped('refRequest_id', 'int'));
	}
	function processVisit() {
		$requestId = (int)$_GET['refRequest_id'];
		$request = ORDataObject::factory("refRequest",$requestId);
		$this->_request = $request;
		$refvisit = ORDataObject::factory("refVisit");
		$refvisit->set('refreferral_visit_id',$request->get('refRequest_id'));
		$refvisit->set('refappointment_id',$request->get('refappointment_id'));
		$refvisit->persist();
	}
	function processForm() {
		$request = Celini::newORDO('refRequest');
                $request->populateArray($_POST['refRequest']);
                $request->set('refStatus', 1);
                $request->persist();
                $this->_request = $request;
	}
	function actionVisit($requestId = '', $formId = '') {
		$request = '';
		if (is_object($this->_request)) {
			$request = $this->_request;
		}
		else {
			$request = ORDataObject::factory("refRequest",$requestId);
		}
		if (empty($formId)) {
			$parProg = ORDataObject::factory("ParticipationProgram",$request->get('refprogram_id'));
			$formId = $parProg->get('form_id');
		}
		return $this->actionForm($formId);

	}
	function actionForm($formId) {
		$GLOBALS['loader']->requireOnce("controllers/C_Form.class.php");
                $form_controller = new C_Form();
                return $form_controller->actionFillout_edit($formId, $this->_request->get('refRequest_id'));
	}
	
	function update_action($refRequest_id = 0) {
		//printf('<pre>%s</pre>', var_export($_POST['refRequest'] , true));
		$request =& Celini::newORDO('refRequest', $refRequest_id);
		$this->_request =& $request;
		$request->populate_array($_POST['refRequest']);
		$request->set('refStatus', 1);
		$request->persist();
		$this->_updatePatientEligibility($request);
		//$request->persist();
		
		header('Location: ' . Celini::link('view/' . $request->get('id')) );
		exit;
	}

	function _addOccurence(&$request) {
		if ($request->get('visit_id') > 0) {
			global $loader;
			$loader->requireOnce('controllers/C_Refvisit.class.php');
			$visitController =& new C_Refvisit();
			$this->view->assign('visitInfo', $visitController->actionCHLVisit($request->get('visit_id'),$request));
			
			$visit =& Celini::newORDO('refVisit', $request->get('visit_id'));
		}
		else {
			$this->view->assign('visitInfo', false);
		}
	}
	
	function _setupEnums() {
		$em =& EnumManager::getInstance();
		
		$GLOBALS['loader']->requireOnce('includes/SpecialtyEnumByProgram.class.php');
		$enumGenerator =& new SpecialtyEnumByProgram($this->_request->get('refprogram_id'));
		$this->view->assign('refSpecialty', $enumGenerator->toArray());
		
		$this->view->assign('refEligibility', $em->enumArray('refEligibility'));
		
		$this->view->assign('refRequested_day', $em->enumArray('days'));
		$this->view->assign('refRequested_time', $em->enumArray('refRequested_time'));
		$this->view->assign('yesNoArray', $em->enumArray('yesNo'));
		
		$refStatusList =& $em->enumList('refStatus');
		$this->view->assign('refStatuses', $refStatusList->toArray());
	}

	function actionSummary() {
		$dsLoader =& new DatasourceFileLoader();
		$dsLoader->load('refRequestList_DS');
		$requestList =& new refRequestList_DS();
		
		$requestListGrid =& new cGrid($requestList);
		$requestListGrid->name = "formDataGrid";
		$requestListGrid->indexCol = false;
		$this->view->assign_by_ref('requestListGrid', $requestListGrid);
		return $this->view->fetch(Celini::getTemplatePath('/referral/' . $this->template_mod . '_summary.html'));
	}
	
	/**
	 * Sets up the environment and loads C_Document
	 *
	 * @access private
	 */
	function _initDocument(&$request) {
		// See if this info is available from CHLCare's session.  If it isn't, fake it.
		if (!isset($_SESSION['patientRow']['patient_id']) || empty($_SESSION['patientRow']['patient_id'])) {
			$_SESSION['patientRow']['patient_id'] = $request->get('patient_id');
		}
		elseif (!isset($_SESSION['patientRow'])) {
			$_SESSION['patientRow'] = array('patient_id', $request->get('patient_id'));
		}
		
		$controller =& new C_ReferralAttachment();
		$this->view->assign('documentList', $controller->actionList($request->get('id')));
		
		if ($request->get('id') > 0) {
			$session =& Celini::sessionInstance();
			$session->set('DocSmart:storableForm', 
				array(
					'controller' => 'ReferralAttachment',
					'extra' => 'refRequest_id=' . EnforceType::int($request->get('id'))
				)
			);
			
			$redirectUrl = Celini::link('list', 'ReferralAttachment', false, $request->get('id')); 
			$this->set('redirectUrl', $redirectUrl, 'C_DocSmartStorable');
		}
	}
	
	
/*	function _initPatientData($patient_id) {
		if ($patient_id <= 0) {
			return;
		}
		
		global $loader;
		if ($loader->requireOnce('includes/chlUtility.class.php')) {
			chlUtility::loadPatientInfo($patient_id);
		}
	}*/
	
	
	/**
	 * @access private
	 * @todo Consider refractoring into it's own object?  The code in the while() loop should be
	 *    a Visitor once collections are capable of being visited.
	 */
	function _updatePatientEligibility(&$request) {
		$patientEligibility =& Celini::newORDO('refPatientEligibility',
			array($request->get('refprogram_id'), $request->get('patient_id')),
			'ByProgramAndPatient');
		if (isset($_POST['refPatientEligibility'])) {
			$patientEligibility->populateArray($_POST['refPatientEligibility']);
			$patientEligibility->persist();
		}
		
		$GLOBALS['loader']->requireOnce('includes/refEligibilityForRequestChanger.class.php');
		$changer =& new refEligibilityForRequestChanger($patientEligibility);
		$changer->doChange();
		
		$newRequest =& Celini::newORDO('refRequest', $request->get('id'));
		$request =& $newRequest;
		
	}
}

