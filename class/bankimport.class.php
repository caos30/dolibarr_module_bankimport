<?php

dol_include_once('/compta/bank/class/account.class.php');
dol_include_once('/compta/paiement/cheque/class/remisecheque.class.php');
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/adherents/class/adherent.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/sociales/class/chargesociales.class.php';

class BankImport
{
	/** @var string Negative direction token */
	private $neg_dir;

	protected $db;

	/** @var Account */
	public $account;
	public $file;
	
	public $dateStart;
	public $dateEnd;
	public $numReleve;
	public $hasHeader;
	public $lineHeader; // Si on historise, on concerve le header d'origine pour avoir le bon intitulé dans nos future tableaux
	public $TOriginLine=array(); // Contient les lignes d'origin du fichier, pour l'historisation 
	
	public $TBank = array(); // Will contain all account lines of the period
	public $TCheckReceipt = array(); // Will contain check receipt made for account lines of the period
	public $TFile = array(); // Will contain all file lines
	
	public $nbCreated = 0;
	public $nbReconciled = 0;
	
	function __construct($db) {
		$this->db = &$db;
		$this->dateStart = strtotime('first day of last month');
		$this->dateEnd = strtotime('last day of last month');
	}
	
	/**
	 * Set vars we will work with
	 */
	function analyse($accountId, $filename, $dateStart, $dateEnd, $numReleve, $hasHeader) {
		global $conf, $langs;
		
		// Bank account selected
		if($accountId <= 0) {
			setEventMessage($langs->trans('ErrorAccountIdNotSelected'), 'errors');
			return false;
		} else {
			$this->account = new Account($this->db);
			$this->account->fetch($accountId);
		}
		
		// Start and end date regarding bank statement
		$this->dateStart = $dateStart;
		$this->dateEnd = $dateEnd;
		
		// Statement number
		$this->numReleve = $numReleve;
		$this->hasHeader = $hasHeader;
		
		// Bank statement file (csv or filename if csv already uploaded)
		if(is_file($filename)) {
			$this->file = $filename;
		} else if(!empty($_FILES[$filename])) {
			
			if($_FILES[$filename]['error'] != 0) {
				setEventMessage($langs->trans('ErrorFile' . $_FILES[$filename]['error']), 'errors');
				return false;
			}/* else if($_FILES[$filename]['type'] != 'text/csv' && $_FILES[$filename]['type'] != 'text/plain' &&  && $_FILES[$filename]['type'] != 'application/octet-stream') {
				setEventMessage($langs->trans('ErrorFileIsNotCSV') . ' ' . $_FILES[$filename]['type'], 'errors');
				return false;
			}*/ 
			else {
				
				dol_include_once('/core/lib/files.lib.php');
				dol_include_once('/core/lib/images.lib.php');
				$upload_dir = $conf->bankimport->dir_output . '/' . dol_sanitizeFileName($this->account->ref);
				
				dol_add_file_process($upload_dir,1,1,$filename);
				$this->file = $upload_dir . '/' . $_FILES[$filename]['name'];
				
				if(!is_file($this->file)) {
					return false;
				}
			}
		}
		
		return true;
	}
	
	function load_transactions($delimiter='', $dateFormat='', $mapping_string='', $enclosure='"') {
		$this->load_bank_transactions();
		$this->load_check_receipt();
		$this->load_file_transactions($delimiter, $dateFormat, $mapping_string, $enclosure);
	}
	
	// Load bank lines
	function load_bank_transactions() {
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bank WHERE fk_account = " . $this->account->id . " ";
		$sql.= "AND dateo BETWEEN '" . date('Y-m-d', $this->dateStart) . "' AND '" . date('Y-m-d', $this->dateEnd) . "' ";
		$sql.= "ORDER BY datev DESC";
		
		$resql = $this->db->query($sql);
		$TBankLineId = array();
		while($obj = $this->db->fetch_object($resql)) {
			$TBankLineId[] = $obj->rowid;
		}
		
		foreach($TBankLineId as $bankid) {
			$bankLine = new AccountLine($this->db);
			$bankLine->fetch($bankid);
			$this->TBank[$bankid] = $bankLine;
		}
	}
	
	// Load check receipt regarding bank lines
	function load_check_receipt() {
		foreach($this->TBank as $bankLine) {
			if($bankLine->fk_bordereau > 0 && empty($this->TCheckReceipt[$bankLine->fk_bordereau])) {
				$bord = new RemiseCheque($this->db);
				$bord->fetch($bankLine->fk_bordereau);
				
				$this->TCheckReceipt[$bankLine->fk_bordereau] = $bord;
			}
		}
	}

	// Load file lines
	function load_file_transactions($delimiter='', $dateFormat='', $mapping_string='', $enclosure='"') {
		global $conf, $langs;

		if(empty($delimiter)) $delimiter = $conf->global->BANKIMPORT_SEPARATOR;
		if(empty($dateFormat)) $dateFormat = strtr($conf->global->BANKIMPORT_DATE_FORMAT, array('%'=>''));
		if(empty($mapping_string)) $mapping_string = $conf->global->BANKIMPORT_MAPPING;
		$mapping_string = preg_replace_callback('|=(.*)' . $delimiter . '|', 'BankImport::extractNegDir', $mapping_string);
		
		if($delimiter == '\t')$delimiter="\t";
		
		if(strpos($mapping_string,$delimiter) === false) $mapping = explode(";", $mapping_string); // pour le \t
		else $mapping = explode($delimiter, $mapping_string); // pour le \t

		$f1 = fopen($this->file, 'r');
		if($this->hasHeader) $this->lineHeader = fgets($f1, 4096);
		
		while(!feof($f1)) {

			if(!empty($conf->global->BANKIMPORT_MAC_COMPATIBILITY)) {
				$ligne = fgets($f1, 4096);
//				print '<hr>'.$ligne.'<br />';
				$dataline = str_getcsv(trim($ligne), $delimiter, $enclosure);

			}
			else {
				$dataline = fgetcsv($f1, 4096, $delimiter, $enclosure);
			}
//		  var_dump($dataline, $delimiter, $enclosure);

			if(count($dataline) == count($mapping)) {
				$this->TOriginLine[] = $dataline;
				$data = array_combine($mapping, $dataline);

				// Gestion du montant débit / crédit
				if (empty($data['debit']) && empty($data['credit'])) {
					$amount = price2num($data['amount']);

					// Direction support
					if (!empty($data['direction'])) {
						if ($data['direction'] == $this->neg_dir) {
							$amount *= -1;
						}
					}

					if ($amount >= 0) {
						$data['credit'] = $amount;
					} elseif ($amount < 0) {
						$data['debit'] = $amount;
					}
				} else {
					$data['debit'] = price2num($data['debit']);
					if ($data['debit'] > 0) {
						$data['debit'] *= -1;
					}
					$data['credit'] = price2num($data['credit']);
				}
				
				$data['amount'] = (!empty($data['debit']) ? $data['debit'] : $data['credit']);
				
				//$time = date_parse_from_format($dateFormat, $data['date']);
				//$data['datev'] = mktime(0, 0, 0, $time['month'], $time['day'], $time['year']+2000);

				// TODO : Apparemment createFromFormat ne fonctionne pas si PHP < 5.3 ....
				$datetime = DateTime::createFromFormat($dateFormat, $data['date']);
				
				$data['datev'] = ($datetime === false) ? 0 : $datetime->getTimestamp();
				
				$data['error'] = '';
			} else {
				$data = array();
				$data['error'] = $langs->trans('LineDoesNotMatchWithMapping');
			}
			
			$this->TFile[] = $data;
		}
		
		fclose($f1);
	}
	
	function compare_transactions() {
		
		// For each file transaction, we search in Dolibarr bank transaction if there is a match by amount
		foreach($this->TFile as &$fileLine) {
			$amount = price2num($fileLine['amount']); // Transform to numeric string
			if(is_numeric($amount)) {
				$transac = $this->search_dolibarr_transaction_by_amount($amount);
				if($transac === false) $transac = $this->search_dolibarr_transaction_by_receipt($amount);
				$fileLine['bankline'] = $transac;
			}
		}
	}
	
	private function search_dolibarr_transaction_by_amount($amount) {
		global $langs;
		$langs->load("banks");
		
		$amount = floatval($amount); // Transform to float
		foreach($this->TBank as $i => $bankLine) {
			if($amount == $bankLine->amount) {
				unset($this->TBank[$i]);
				
				return array($this->get_bankline_data($bankLine));
			}
		}
		
		return false;
	}

	private function search_dolibarr_transaction_by_receipt($amount) {
		global $langs;
		$langs->load("banks");
		
		$amount = floatval($amount); // Transform to float
		foreach($this->TCheckReceipt as $bordereau) {
			if($amount == $bordereau->amount) {
				$TBankLine = array();
				foreach($this->TBank as $i => $bankLine) {
					if($bankLine->fk_bordereau == $bordereau->id) {
						unset($this->TBank[$i]);
						
						$TBankLine[] = $this->get_bankline_data($bankLine);
					}
				}
				
				return $TBankLine;
			}
		}
		
		return false;
	}

	private function get_bankline_data($bankLine) {
		global $langs, $db;
		
		if(!empty($bankLine->num_releve)) {
			$link = '<a href="' . dol_buildpath(
				'/compta/bank/releve.php'
					. '?num=' . $bankLine->num_releve
					. '&account=' . $bankLine->fk_account, 2
				) . '">'
				. $bankLine->num_releve
				. '</a>';
			$result = $langs->trans('AlreadyReconciledWithStatement', $link);
			$autoaction = false;
		} else {
			$result = $langs->trans('WillBeReconciledWithStatement', $this->numReleve);
			$autoaction = true;
		}
		
		$societestatic = new Societe($db);
		$userstatic = new User($db);
		$chargestatic = new ChargeSociales($db);
		$memberstatic = new Adherent($db);
		
		$links = $this->account->get_url($bankLine->id);
		$relatedItem = '';
		foreach($links as $key=>$val) {
			if ($links[$key]['type'] == 'company') {
				$societestatic->id = $links[$key]['url_id'];
				$societestatic->name = $links[$key]['label'];
				$relatedItem = $societestatic->getNomUrl(1,'',16);
			} else if ($links[$key]['type'] == 'user') {
				$userstatic->id = $links[$key]['url_id'];
				$userstatic->lastname = $links[$key]['label'];
				$relatedItem = $userstatic->getNomUrl(1,'');
			} else if ($links[$key]['type'] == 'sc') {
				// sc=old value
				$chargestatic->id = $links[$key]['url_id'];
				if (preg_match('/^\((.*)\)$/i',$links[$key]['label'],$reg)) {
					if ($reg[1] == 'socialcontribution') $reg[1] = 'SocialContribution';
					$chargestatic->lib = $langs->trans($reg[1]);
				} else {
					$chargestatic->lib = $links[$key]['label'];
				}
				$chargestatic->ref = $chargestatic->lib;
				$relatedItem = $chargestatic->getNomUrl(1,16);
			} else if ($links[$key]['type'] == 'member') {
				$memberstatic->id = $links[$key]['url_id'];
				$memberstatic->ref = $links[$key]['label'];
				$relatedItem = $memberstatic->getNomUrl(1,16,'card');
			}
		}
		
		return array(
			'id' => $bankLine->id
			,'url' => $bankLine->getNomUrl(1)
			,'date' => dol_print_date($bankLine->datev,"day")
			,'label' => (preg_match('/^\((.*)\)$/i',$bankLine->label,$reg) ? $langs->trans($reg[1]) : dol_trunc($bankLine->label,60))
			,'amount' => price($bankLine->amount)
			,'result' => $result
			,'autoaction' => $autoaction
			,'relateditem' => $relatedItem
			,'time' => $bankLine->datev
		);
	}
	
	/**
	 * Actions made after file check by user
	 */
	public function import_data($TLine) 
	{
		global $conf;
		
		if (!empty($TLine['piece'])) 
		{
			$PDOdb = new TPDOdb;

			dol_include_once('/compta/paiement/class/paiement.class.php');
			dol_include_once('/fourn/class/paiementfourn.class.php');
			dol_include_once('/fourn/class/fournisseur.facture.class.php');
			dol_include_once('/compta/sociales/class/paymentsocialcontribution.class.php');
			
			/*
			 * Reglemenent créé manuellement
			 */
			  	
			$db = &$this->db;
			foreach($TLine['piece'] as $iFileLine=>$TObject) 
			{
				if(!empty($TLine['fk_soc'][$iFileLine])) {
					$l_societe = new Societe($db);
					$l_societe->fetch($TLine['fk_soc'][$iFileLine]);
				}
				
				$fk_payment = $TLine['fk_payment'][$iFileLine];
				$date_paye = $this->TFile[$iFileLine]['datev'];
				$TFkBank = array();
				
				foreach($TObject as $typeObject=>$TAmounts) 
				{
					if(!empty($TAmounts)) 
					{
						switch ($typeObject) 
						{
							case 'facture':
								$fk_bank = $this->doPaymentForFacture($TLine, $TAmounts, $l_societe, $iFileLine, $fk_payment, $date_paye);
								break;
							case 'fournfacture':
								$fk_bank = $this->doPaymentForFactureFourn($TLine, $TAmounts, $l_societe, $iFileLine, $fk_payment, $date_paye);
								break;
							case 'charge':
								$fk_bank = $this->doPaymentForCharge();
								break;
							default:
								continue;
								break;
						}
						
						// TODO créer la conf en admin et supprimer le test "true"
						if (true || !empty($conf->global->BANKIMPORT_HISTORY_IMPORT) && $fk_bank > 0)
						{
							$this->insertHistoryLine($PDOdb, $iFileLine, $fk_bank);
						}
						
					}
				}
				
			}
			
			unset($TLine['piece']);
		}
		
		unset($TLine['fk_payment'], $TLine['fk_soc'], $TLine['type']);
		
	//	exit;
	
		if (isset($TLine['new'])) 
		{
			if(!empty($TLine['new'])) {
				foreach($TLine['new'] as $iFileLine) {
					$bankLineId = $this->create_bank_transaction($this->TFile[$iFileLine]);
					if($bankLineId > 0) {
						$bankLine = new AccountLine($this->db);
						$bankLine->fetch($bankLineId);
						$this->reconcile_bank_transaction($bankLine, $this->TFile[$iFileLine]);
					}
				}
			}
			unset($TLine['new']);
		}
		
		foreach($TLine as $bankLineId => $iFileLine) 
		{
			$this->reconcile_bank_transaction($this->TBank[$bankLineId], $this->TFile[$iFileLine]);
		}
	}

	private function doPaymentForFacture(&$TLine, &$TAmounts, &$l_societe, $iFileLine, $fk_payment, $date_paye)
	{
		return $this->doPayment($TLine, $TAmounts, $l_societe, $iFileLine, $fk_payment, $date_paye, 'payment');
	}

	private function doPaymentForFactureFourn(&$TLine, &$TAmounts, &$l_societe, $iFileLine, $fk_payment, $date_paye)
	{
		return $this->doPayment($TLine, $TAmounts, $l_societe, $iFileLine, $fk_payment, $date_paye, 'payment_supplier');
	}
	
	private function doPaymentForCharge(&$TLine, &$TAmounts, &$l_societe, $iFileLine, $fk_payment, $date_paye)
	{
		return $this->doPayment($TLine, $TAmounts, $l_societe, $iFileLine, $fk_payment, $date_paye, 'payment_sc');
	}

	private function doPayment(&$TLine, &$TAmounts, &$l_societe, $iFileLine, $fk_payment, $date_paye, $type='payment')
	{
		global $langs,$user;
		
		$note = $langs->trans('TitleBankImport') .' - '.$this->numReleve;
		
		if ($type == 'payment') $paiement = new Paiement($this->db);
		elseif ($type == 'payment_supplier') $paiement = new PaiementFourn($this->db);
		elseif ($type == 'payment_supplier') $paiement = new PaymentSocialContribution($this->db);
		else exit($langs->trans('BankImport_FatalError_PaymentType_NotPossible', $type));
		
	    $paiement->datepaye     = $date_paye;
	    $paiement->amounts      = $TAmounts;   // Array with all payments dispatching
	    $paiement->paiementid   = $fk_payment;
	    $paiement->num_paiement = '';
	    $paiement->note         = $note;
		
		$paiement_id = $paiement->create($user, 1);

		if ($paiement_id > 0) 
		{
			$bankLineId = $paiement->addPaymentToBank($user, $type, $note, $this->account->id, $l_societe->name, '');
			$TLine[$bankLineId] = $iFileLine;
			
			$bankLine = new AccountLine($this->db);
			$bankLine->fetch($bankLineId);
			$this->TBank[$bankLineId] = $bankLine;
			
			// On supprime le new saisi
			foreach($TLine['new'] as $k=>$iFileLineNew) 
			{
				if($iFileLineNew == $iFileLine) unset($TLine['new'][$k]);
			}
			
			return $bankLineId;
		}
		
		return 0; // Payment fail, can't return bankLineId
	}

	private function insertHistoryLine(&$PDOdb, $iFileLine, $fk_bank)
	{
		if (!empty($this->hasHeader) && !empty($this->TOriginLine[$iFileLine]))
		{
			$header = $this->parseHeader($this->lineHeader);
			$line = $this->parseLine($this->TOriginLine[$iFileLine]);
			
			$historyLine = new TBankImportHistory;
			
			$historyLine->num_releve = $this->numReleve;
			$historyLine->fk_bank = $fk_bank;
			$historyLine->line_imported_title = $header;
			$historyLine->line_imported_value = $line;
			
			$historyLine->save($PDOdb);
		}
	}
	
	public function parseHeader($headerToParse)
	{
		global $conf;
		
		$header = explode($conf->global->BANKIMPORT_SEPARATOR, $headerToParse);
		$header = array_map(array('BankImport', 'cleanString'), $header);
		
		return $header;
	}
	
	public static function cleanString($strToClean)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
		$strToClean = trim($strToClean);
		$strToClean = preg_replace('/\s{2,}/', '', $strToClean);
		$strToClean = dol_strtolower(dol_string_unaccent($strToClean));
		
		return ucfirst($strToClean);
	}
	
	public function parseLine($lineArrayToParse)
	{
		$line = array_map(array('BankImport', 'cleanStringForLine'), $lineArrayToParse);
		
		return $line;
	}
	
	public static function cleanStringForLine($strToClean)
	{
		$strToClean = trim($strToClean);
		$strToClean = preg_replace('/\s{2,}/', '', $strToClean);
		
		return $strToClean;
	}
	
	private function create_bank_transaction($fileLine) {
		global $user;
		
		$bankLineId = $this->account->addline($fileLine['datev'], 'PRE', $fileLine['label'], $fileLine['amount'], '', '', $user);
		$this->nbCreated++;
		
		return $bankLineId;
	}
	
	private function reconcile_bank_transaction($bankLine, $fileLine) {
		global $user,$conf;
		
		// Set conciliation
		$bankLine->num_releve = $this->numReleve;
		$bankLine->update_conciliation($user, 0);
		
		// Update value date
		$dateDiff = ($fileLine['datev'] - strtotime($bankLine->datev)) / 24 / 3600;
		$bankLine->datev_change($bankLine->id, $dateDiff);
		
		$this->nbReconciled++;
	}

	/**
	 * Extract negative direction token from direction key
	 *
	 * @param array $matches Regex matches
	 * @return string Last separator (Effectively removing the extracted negative direction)
	 */
	private function extractNegDir(array $matches) {
		$this->neg_dir = $matches[1];
		return substr($matches[0], -1);
	}
}


class TBankImportHistory extends TObjetStd
{
	function __construct() 
	{
		$this->set_table( MAIN_DB_PREFIX.'bankimport_history' );
    	 
		$this->add_champs('num_releve',array('type'=>'varchar','length'=>50,'index'=>true));
		$this->add_champs('fk_bank',array('type'=>'integer','index'=>true));
        $this->add_champs('line_imported_title,line_imported_value', array('type'=>'array'));
        
        $this->_init_vars();
        
	    $this->start();
	}
	
	/*
	 * Retourne un array contenant le detail de l'import de l'écriture bancaire groupé par title
	 */
	public function getAllHistoryByNumReleve(&$PDOdb, $num_releve)
	{
		$sql = 'SELECT rowid, line_imported_title FROM '.$this->get_table().' WHERE num_releve = '.$PDOdb->quote($num_releve);
		$PDOdb->Execute($sql);
		
		$TEcriture = array();
		$PDOdb2 = new TPDOdb;
		while ($line = $PDOdb->Get_line())
		{
			$o = new TBankImportHistory;
			$o->load($PDOdb2, $line->rowid);
			$TEcriture[$line->line_imported_title][$o->fk_bank] = $o;
		}
		
		return $TEcriture;
	}
}
