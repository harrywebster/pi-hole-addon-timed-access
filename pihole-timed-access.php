<?php

class TimedAccess {

	// keep these variables private, they're either sensitive or we don't want them to be printed on a var_dump as they're not important.
	private $config;
	private $dbh;
	private $block_id;
	private $debug;
	private $verbose;

	/**
	 * construct the object for our toggle class
	 * 
	 * @param void
	 * @return void
	 */
	function __construct()
	{
		// load JSON config
		$this->load_config();

		// establish the database connection
		require_once $this->config['pihole_path'].'database.php';

		// establish the database connection
		$GRAVITYDB = getGravityDBFilename();
		$this->dbh = SQLite3_connect($GRAVITYDB, SQLITE3_OPEN_READWRITE);

		// default supress debug
		$this->debug = false;
		$this->verbose = false;
	}

	/**
	 * log to the TTY or file if debug mode is enabled
	 * 
	 * @param string $debugging_message
	 * @return void
	 */
	private function log($message)
	{
		// don't log if debug mode is disabled
		if ($this->debug !== true) return true;

		// add datetime to the message
		$log_message = '['.date('Y-m-d H:i:s').'] '.$message."\n";

		// print to TTY
		echo $log_message;

		// print to log file (path defined in config.json)
		if (isset($this->config['log']) and !empty($this->config['log'])) {
			file_put_contents($this->config['log'], $message, FILE_APPEND | LOCK_EX);
		}
	}

	/**
	 * Enable or disable debug mode, printing helpful information to the console
	 * 
	 * @param bool $switch
	 * @return void
	 */
	public function debug($sw)
	{
		$this->debug = $sw;
	}

	/**
	 * Enable or disable verbose debug mode, printing helpful information to the console
	 * 
	 * @param bool $switch
	 * @return void
	 */
	public function verbose($sw)
	{
		$this->debug(true);
		$this->verbose = $sw;
	}

	/**
	 * Load the JSON config file and read into the object
	 * 
	 * @param void
	 * @return void
	 */
	private function load_config()
	{
		// load JSON config
		$this->config = json_decode(file_get_contents(__DIR__.'/config.json'), true);

		// check if the JSON is valid
		if (!isset($this->config['pihole_path'])) {
			echo 'No pihole_path found in config.json or JSON is invalid.';
			exit(1);
		}		
	}

	/**
	 * Watch the time and day, apply or remove the block depending on whats specified in the JSON config.
	 * 
	 * @param void
	 * @return void
	 */
	public function watch()
	{
		// what is the time now (HHMM i.e. 2359)?
		$now_time = intval(date('Hi'));
		$now_day = strtolower(date('D'));

		// loop through all groups and check if we need to block or allow depending on the time and day
		foreach ($this->list_groups() as $group) {

			// breakout the group into a set of rules
			$rule = $this->group_name_breakdown($group['name']);

			// Should we be allowing this DNS at this time?
			$allow_dns = false;

			// should we be allowing or blocking for this day?
			if (in_array($now_day, $rule['days'])) {	
				// should we be allowing or blocking for this time?
				if ($now_time >= intval($rule['from']) and $now_time < intval($rule['until'])) {
					$allow_dns = true;
				}
			}
			// if we're not in the days
			elseif (isset($group['default_apply_block']) and $group['default_apply_block'] === false) {
				$allow_dns = false;
			}
			// if no rule specified in JSON config, then allow DNS
			else $allow_dns = true;

			// check if the block is already in place for group (by id)
			$block_dns = $this->check_block($group['id']);

			// remove block if it exists and it shouldn't.
			if ($allow_dns == true and $block_dns == true) {

				// debugging
				$this->log('removing block for domainlist id '.$this->block_id.' from group id '.$group['id']);
				
				// remove the block for this group
				$this->toggle_block($group['id'], false);

				// restart the DNS resolver (this will help speed up our block on the device(s))
				$this->restart_dnsresolver();
			}
			// apply block if it doesn't exist and it should
			elseif ($allow_dns == false and $block_dns == false) {

				// debugging
				$this->log('applying block for domainlist id '.$this->block_id.' to group id '.$group['id']);

				// create the block for this group
				$this->toggle_block($group['id'], true);

				// restart the DNS resolver (this will help speed up our block on the device(s))
				$this->restart_dnsresolver();
			}
			// skip, nothing to do... print debugging if in verbose mode
			elseif ($this->verbose === true) {

				// build some (possibly) helpful debug information for verbose only debugging
				$message = 'skip '.$group['name'].' - now '.$now_time." allow traffic between ".intval($rule['from'])." and ".intval($rule['until']);
				$message .= " ";
				$message .= $allow_dns == true ? "SHOULD-ALLOW" : "SHOULD-BLOCK";
				$message .= ":";
				$message .= $block_dns == true ? "AM-BLOCKING" : "AM-ALLOWING";

				// debugging
				$this->log($message);
			}
		}
	}
	
	/**
	 * toggle_block() - apply or remove the block
	 * 
	 * @param int $group_id
	 * @param bool enable
	 * @return void
	 */
	private function toggle_block($group_id, $enable)
	{
		// always delete the block if it exists, just incase we have a duplicate
		$this->dbh->query('DELETE FROM domainlist_by_group WHERE domainlist_id = '.$this->block_id.' AND group_id='.$group_id.';');

		// add the block if we need to
		if ($enable == true) {
			$this->dbh->query('INSERT INTO "domainlist_by_group" (domainlist_id,group_id) VALUES ('.$this->block_id.', '.$group_id.');');
		}
	}

	/**
	 * group_name_breakdown() - parse the serialized group name into an array of parts 
	 * 
	 * @param char $group_name
	 * @return char $name
	 * @return int $allow_from_hour
	 * @return int $allow_until_hour
	 * @return array $days_to_allow_traffic_on
	 */
	private function group_name_breakdown($group_name)
	{
		$group = explode('=', $group_name);

		return [
			'name' => $group[0],
			'from' => $group[1],
			'until' => $group[2],
			'days' => explode('_', $group[3])
		];
	}

	/**
	 * build_group_name() - serialized group name for use in the database
	 * 
	 * @param char $name
	 * @param int $allow_from_hour
	 * @param int $allow_until_hour
	 * @param array $days_to_allow_traffic_on
	 * @return char $group_name (group_name=0000=2359=mon_tue_wed_thu_fri_sat_sun)
	 */
	private function build_group_name($name, $from, $until, $days)
	{
		return implode('=', [
			$name,
			$from,
			$until,
			implode('_', $days),
			$this->config['unique_prefix']
		]);
	}

	/**
	 * check_block() - check if the block is in place for the group
	 * 
	 * @param int $group_id
	 * @return bool $active_block
	 */
	private function check_block($group_id)
	{
		// if we don't have the domain block id, get it
		if (!$this->block_id) $this->get_block_id();

		// check if the block is in place for $group_id
		$query = $this->dbh->query('SELECT * FROM "domainlist_by_group" WHERE domainlist_id = '.$this->block_id.' AND group_id='.$group_id);
		$res = $query->fetchArray(SQLITE3_ASSOC) ?? false;

		// return true if we have a block in place, otherwise return false
		return $res === false ? false : true;
	}

	/**
	 * get_block_id() - get the domainlist block id
	 * 
	 * @param void
	 * @return bool $if_no_block_id
	 * @return int $block_id
	 */
	private function get_block_id()
	{
		// check we have the domain block in pihole
		$query = $this->dbh->query('SELECT * FROM "domainlist" WHERE comment = "block-everything-'.$this->config['unique_prefix'].'"');
		$res = $query->fetchArray(SQLITE3_ASSOC);

		// return false if we don't have the block id
		if ($res === false) return false;

		// set the block id and return it
		$this->block_id = $res['id'];
		return $this->block_id;
	}

	/**
	 * check() - verify the software is configured correctly and ready for use
	 * 
	 * @param void
	 * @return bool $ready
	 */
	public function check()
	{
		$group = [];
		$group_missing = [];
		$group_installed = [];

		// check we have the domain block in pihole
		$query = $this->dbh->query('SELECT * FROM "domainlist" WHERE comment = "block-everything-'.$this->config['unique_prefix'].'"');
		if ($query->fetchArray(SQLITE3_ASSOC) === false) {
			$this->domain_build();
		}
		
		foreach ($this->config['rules'] as $rule) {
			$group[] = $this->build_group_name($rule['name'], $rule['time_from'], $rule['time_until'], $rule['days']);
		}
		
		// Query all groups to see if we're configured correctly
		$query = $this->dbh->query('SELECT * FROM "group"');
		while (($res = $query->fetchArray(SQLITE3_ASSOC)) !== false) {

			// skip those groups that are not connected to this plugin
			if (!preg_match('#='.$this->config['unique_prefix'].'$#', $res['name'])) continue;

			// add to the installed groups list, these are configured correctly			
			if (in_array($res['name'], $group)) {
				$group_installed[] = $res;
			}
			// add to the missing groups list
			else $group_missing[] = $res;
		}

		// verify we have all the groups installed
		if (count($group) != count($group_installed)) {
			$this->group_clear();
			$this->group_build($group);
		}
		// if we have missing groups, rebuild the dataset
		elseif (count($group_missing) > 0) {
			$this->group_clear();
			$this->group_build($group);
		}
		// FIXME add elseif to verify days
		// FIXME add elseif to verify time from and time until
		// else everything is ok, print debugging only if verbose is enabled
		elseif ($this->verbose === true) {
			$this->log("We have ".count($group_installed)." groups installed and we should have ".count($group)." groups installed.");
		}

		// check log file is ok if one is defined in the config
		if (isset($this->config['log']) and !empty($this->config['log'])) {
			// create log file if it doesn't exist
			if (!file_exists($this->config['log'])) {
				$logfile = fopen($this->config['log'], "w") or die("Unable to open file!");
				fwrite($logfile, "[".date('Y-m-d H:i:s')."] started new log file.\n");
				fclose($logfile);
			}
		}

		// return true if everything is ok and we're ready to run
		return true;
	}

	/**
	 * domain_build() - create the missing block for domainlist, this stops the DNS resolving ANY domain.
	 * 
	 * @param void
	 * @return void
	 */
	private function domain_build()
	{
		// create the group
		$this->dbh->exec('INSERT INTO "domainlist" (type, domain, enabled, date_added, date_modified, comment) VALUES (3, "(\.|^)", 1, '.time().', '.time().', "block-everything-'.$this->config['unique_prefix'].'")');
	}

	/**
	 * group_clear() - delete all groups so we can restart the configuration
	 * 
	 * @param void
	 * @return void
	 */
	private function group_clear()
	{
		// clear all groups
		$this->dbh->exec('DELETE FROM "group" WHERE name LIKE "%='.$this->config['unique_prefix'].'"');
	}

	/**
	 * group_build() - build groups for all rules defined in JSON config
	 * 
	 * @param array $group
	 * @return void
	 */
	private function group_build($group)
	{
		// loop through all the rules defined in JSON config
		foreach ($this->config['rules'] as $rule) {
			// build the group name
			$group_name = $this->build_group_name($rule['name'], $rule['time_from'], $rule['time_until'], $rule['days']);
			
			// generate a description for the group
			$group_description = 'Block all traffic to these clients between '.$rule['time_from'].' and '.$rule['time_until'].' on these days '.implode(', ', $rule['days']).'.';
			
			// create the group
			$this->dbh->exec('INSERT INTO "group" (enabled, name, date_added, date_modified, description) VALUES (1, "'.$group_name.'", '.time().', '.time().', "'.$group_description.'")');
		}
	}

	/**
	 * list_groups() - list all configured groups
	 * 
	 * @param void
	 * @return array $groups
	 */
	private function list_groups()
	{
		$response = [];

		$query = $this->dbh->query('SELECT * FROM "group" WHERE name LIKE "%='.$this->config['unique_prefix'].'"');
		while (($res = $query->fetchArray(SQLITE3_ASSOC)) !== false) {
			$response[] = $res;
		}

		return $response;
	}

	/** 
	 * Restart the DNS resolver
	 * 
	 * @param void
	 * @return void
	 */
	private function restart_dnsresolver()
	{
		shell_exec('pihole arpflush');
		shell_exec('pihole restartdns');
	}
}

// create a new instance of the toggle class
$timed_access = new TimedAccess();

// enable debug mode (to be more verbose with debugging `$toggle->verbose(true);`)
$timed_access->debug(true);

// check the plugin is configured correctly and if not, configure it... report fatal error here if we have any.
if ($timed_access->check() !== true) {
	echo "Failed to initialise plugin.\n";
	exit(1);
}

// apply or remove the blocks depending on the time and day
$timed_access->watch();