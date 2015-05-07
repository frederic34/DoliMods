<?php
/* Copyright (C) 2011 Regis Houssin	            <regis@dolibarr.fr>
 * Copyright (C) 2008-2012 Laurent Destailleur  <eldy@users.sourceforge.net>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 * or see http://www.gnu.org/
 */

/**
 *      \file       /google/core/triggers/interface_51_modGoogle_GoogleContactSynchro.class.php
 *      \ingroup    google
 *      \brief      File to manage triggers for Google contact sync
 */

include_once(DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php');
dol_include_once('/google/lib/google_contact.lib.php');


/**
 *	Class of triggers for module Google
 */
class InterfaceGoogleContactSynchro
{
	var $db;
	var $error;

	var $date;
	var $duree;
	var $texte;
	var $desc;

	/**
	 *   Constructor.
	 *
	 *   @param		DoliDB		$db      Database handler
	 */
	function __construct($db)
	{
		$this->db = $db;

		$this->name = preg_replace('/^Interface/i','',get_class($this));
		$this->family = "google";
		$this->description = "Triggers of this module allows to add a record inside Google contact for each Dolibarr business event.";
		$this->picto = 'google@google';
	}

	/**
	 *   Renvoi nom du lot de triggers
	 *
	 *   @return     string      Nom du lot de triggers
	 */
	function getName()
	{
		return $this->name;
	}

	/**
	 *   Renvoi descriptif du lot de triggers
	 *
	 *   @return     string      Descriptif du lot de triggers
	 */
	function getDesc()
	{
		return $this->description;
	}

	/**
	 *   Renvoi version du lot de triggers
	 *
	 *   @return     string      Version du lot de triggers
	 */
	function getVersion()
	{
		global $langs;
		$langs->load("admin");

		if ($this->version == 'experimental') return $langs->trans("Experimental");
		elseif ($this->version == 'dolibarr') return DOL_VERSION;
		elseif ($this->version) return $this->version;
		else return $langs->trans("Unknown");
	}

	/**
	 *      Fonction appelee lors du declenchement d'un evenement Dolibarr.
	 *      D'autres fonctions run_trigger peuvent etre presentes dans includes/triggers
	 *
	 *      @param	string		$action     Code of event
	 *      @param 	Action		$object     Objet concerne
	 *      @param  User		$user       Objet user
	 *      @param  Translate	$lang       Objet lang
	 *      @param  Conf		$conf       Objet conf
	 *      @return int         			<0 if KO, 0 if nothing is done, >0 if OK
	 */
	function run_trigger($action, $object, $user, $langs, $conf)
	{
		global $dolibarr_main_url_root;

		// Création / Mise à jour / Suppression d'un évènement dans Google contact

		if (!$conf->google->enabled) return 0; // Module non actif

		$fuser = new User($this->db);

		//var_dump($object); exit;
		$user = empty($conf->global->GOOGLE_CONTACT_LOGIN)?'':$conf->global->GOOGLE_CONTACT_LOGIN;
		$pwd  = empty($conf->global->GOOGLE_CONTACT_PASSWORD)?'':$conf->global->GOOGLE_CONTACT_PASSWORD;
		//print $action.' - '.$user.' - '.$pwd.' - '.$conf->global->GOOGLE_DUPLICATE_INTO_THIRDPARTIES.' - '.$conf->global->GOOGLE_DUPLICATE_INTO_CONTACTS; exit;


		// Actions
		if ($action == 'COMPANY_CREATE' || $action == 'COMPANY_MODIFY' || $action == 'COMPANY_DELETE'
			|| $action == 'CONTACT_CREATE' || $action == 'CONTACT_MODIFY' || $action == 'CONTACT_DELETE'
			|| $action == 'MEMBER_CREATE' || $action == 'MEMBER_MODIFY' || $action == 'MEMBER_DELETE')
		{
			if (preg_match('/^COMPANY_/',$action) && empty($conf->global->GOOGLE_DUPLICATE_INTO_THIRDPARTIES)) return 0;
			if (preg_match('/^CONTACT_/',$action) && empty($conf->global->GOOGLE_DUPLICATE_INTO_CONTACTS)) return 0;
			if (preg_match('/^MEMBER_/',$action) && empty($conf->global->GOOGLE_DUPLICATE_INTO_MEMBERS)) return 0;


			dol_syslog("Trigger '" . $this->name . "' for action '$action' launched by " . __FILE__ . ". id=" . $object->id." element=".$object->element);

			$langs->load("other");

			if (empty($user) || empty($pwd))
			{
				dol_syslog("Setup to synchronize events into a Google contact is on but can't find complete setup for login/password.", LOG_WARNING);
				return 0;
			}

			// Create client/token object
			$key_file_location = $conf->google->multidir_output[$conf->entity]."/".$conf->global->GOOGLE_API_SERVICEACCOUNT_P12KEY;
			$force_do_not_use_session=(in_array(GETPOST('action'), array('testall','testcreate'))?true:false);	// false by default
			$servicearray=getTokenFromServiceAccount($conf->global->GOOGLE_API_SERVICEACCOUNT_CLIENT_ID, $conf->global->GOOGLE_API_SERVICEACCOUNT_EMAIL, $key_file_location, $force_do_not_use_session);

			if (! is_array($servicearray))
			{
				$this->errors[]=$servicearray;
				return -1;
			}

			if ($servicearray == null)
			{
				$this->error="Failed to login to Google with credentials provided into setup page ".$conf->global->GOOGLE_API_SERVICEACCOUNT_CLIENT_ID.", ".$conf->global->GOOGLE_API_SERVICEACCOUNT_EMAIL.", ".$key_file_location;
				dol_syslog($this->error, LOG_ERR);
				$this->errors[]=$this->error;
				return -1;
			}
			else
			{
				if ($action == 'COMPANY_CREATE' || $action == 'CONTACT_CREATE' || $action == 'MEMBER_CREATE')
				{
					$ret = createContact($servicearray, $object, $userlogin);
					if (! preg_match('/ERROR/',$ret))
					{
						if (! preg_match('/google\.com/',$ret)) $ret='google:'.$ret;
						$object->update_ref_ext($ret);	// This is to store ref_ext to allow updates
						return 1;
					}
					else
					{
						$this->errors[]=$ret;
						return -1;
					}
					/*
					$ret = googleCreateContact($client, $object, 'default');

					$object->update_ref_ext($ret);
					// This is to store ref_ext to allow updates

					return 1;*/
				}
				if ($action == 'COMPANY_MODIFY' || $action == 'CONTACT_MODIFY' || $action == 'MEMBER_MODIFY')
				{
					$gid = preg_replace('/http:\/\//','https://',$object->ref_ext);
					if ($gid && preg_match('/google/i', $object->ref_ext)) // This record is linked with Google Contact
					{
						/*
						$ret = googleUpdateContact($client, $gid, $object, 'default');

						if ($ret == '0')// Fails to update because not found, we try to create
						{
							$ret = googleCreateContact($client, $object, 'default');
							//var_dump($ret); exit;

							$object->update_ref_ext($ret);
							// This is to store ref_ext to allow updates
						}
						else if (is_numeric($ret) && $ret < 0)
						{
							$this->error='Failed to update google record. Enable module "Log" and check your dolibarr log file.';
							$this->errors[]=$this->error;
							return $ret;
						}

						return 1;*/
						
						$ret = updateContact($servicearray, $gid, $object, $userlogin);
						//var_dump($ret); exit;

						if (! is_numeric($ret) || $ret < 0)// Fails to update, we try to create
						{
							$ret = createContact($servicearray, $object, $userlogin);
							//var_dump($ret); exit;

							if (! preg_match('/ERROR/',$ret))
							{
								if (! preg_match('/google\.com/',$ret)) $ret='google:'.$ret;
								$object->update_ref_ext($ret);	// This is to store ref_ext to allow updates
								return 1;
							}
							else
							{
								$this->errors[]=$ret;
								return -1;
							}
						}
						return 1;						
					}
					else if ($gid == '')
					{ 	/*
						// No google id
						$ret = googleCreateContact($client, $object, 'default');
						//var_dump($ret); exit;

						$object->update_ref_ext($ret);
						// This is to store ref_ext to allow updates
						 */
						
						$ret = createContact($servicearray, $object, $userlogin);
						//var_dump($ret); exit;

						if (! preg_match('/ERROR/',$ret))
						{
							if (! preg_match('/google\.com/',$ret)) $ret='google:'.$ret;
							$object->update_ref_ext($ret);	// This is to store ref_ext to allow updates
							return 1;
						}
						else
						{
							$this->errors[]=$ret;
							return -1;
						}						
					}

					return 1;
				}
				if ($action == 'COMPANY_DELETE' || $action == 'CONTACT_DELETE' || $action == 'MEMBER_DELETE')
				{
					/*
					$gid = preg_replace('/http:\/\//','https://',$object->ref_ext);
					if ($gid && preg_match('/google/i', $object->ref_ext)) // This record is linked with Google Calendar
					{
						$ret = googleDeleteContactByRef($client, $gid, 'default');
						if ($ret)
						{
							$this->error=$ret;
							$this->errors[]=$this->error;
							return 0;	// We do not stop delete if error
						}
					}
					return 1;*/
					$gid = basename($object->ref_ext);
					if ($gid && preg_match('/google/i', $object->ref_ext)) // This record is linked with Google Calendar
					{
						$ret = deleteContactById($servicearray, $gid, $userlogin);
						//var_dump($ret); exit;

						return 1;
					}
					
				}
			}
		}

		return 0;
	}

}
?>
