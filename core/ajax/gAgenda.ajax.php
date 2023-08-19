<?php
try {
	require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
	include_file('core', 'authentification', 'php');

	if (!isConnect('admin')) {
		throw new Exception(__('401 - Accès non autorisé', __FILE__));
	}


	ajax::init();

	if (init('action') == 'linkToUser') {
		$eqLogic = eqLogic::byId(init('id'));
		if (!is_object($eqLogic)) {
			throw new Exception(__('EqLogic non trouvé : ', __FILE__) . init('id'));
		}
		ajax::success(array('redirect' => $eqLogic->linkToUser()));
	}

	if (init('action') == 'listCalendar') {
		$eqLogic = eqLogic::byId(init('id'));
		if (!is_object($eqLogic)) {
			throw new Exception(__('EqLogic non trouvé : ', __FILE__) . init('id'));
		}
		ajax::success($eqLogic->listCalendar());
	}

	throw new Exception(__('Aucune méthode correspondante à', __FILE__) . ' : ' . init('action'));
	/*     * *********Catch exeption*************** */
} catch (Exception $e) {
	ajax::error(displayException($e), $e->getCode());
}
