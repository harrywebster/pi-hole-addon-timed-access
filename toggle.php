<?php

// Change this to wherever you have pihole installed (`locate scripts/pi-hole/php/auth.php` will help you find that)
$path = '/var/www/html/admin/scripts/pi-hole/php/';

// The group and domainlist to match (these are the defaults described in the `README.md`)
$group_match='allow-between-';
$domainlist_match='block-everything';

// default params (will be overwritten)
$group_id=null;
$domainlist_id=null;
$time_from = null;
$time_until = null;

// Establish the database connection
require_once $path.'database.php';

// Pi-hole database handler
$GRAVITYDB = getGravityDBFilename();
$db = SQLite3_connect($GRAVITYDB, SQLITE3_OPEN_READWRITE);

// Get the group (allow 08:00 to 20:00)
$query = $db->query('SELECT * FROM "group";');
while (($res = $query->fetchArray(SQLITE3_ASSOC)) !== false) {
    if (preg_match('#^'.$group_match.'#', $res['name'])) {
        $group_id=$res['id'];
        if (preg_match('#'.$group_match.'([0-9]{4})-([0-9]{4})#', $res['name'], $hours)) {
            $time_from=intval($hours[1]);
            $time_until=intval($hours[2]);
        }
    }
}

// Get the domain-block (called `block-everything`)
$query = $db->query('SELECT * FROM "domainlist";');
while (($res = $query->fetchArray(SQLITE3_ASSOC)) !== false) {
    if (preg_match('#'.$domainlist_match.'#', $res['comment'])) {
        $domainlist_id=$res['id'];
    }
}

// do we have everything we need?
if (!isset($time_from) or empty($time_from)) die('no from time found.');
if (!isset($time_until) or empty($time_until)) die('no from time found.');
if (!isset($group_id) or empty($group_id)) die('no group found.');
if (!isset($domainlist_id) or empty($domainlist_id)) die('no domainlist found.');

// Should we be allowing this DNS at this time?
$allow_dns = false;

// what is the time now (HHMM i.e. 2359)?
$now = intval(date('Hi'));

// should we be allowing or blocking for this time?
if ($now >= $time_from and $now < $time_until) $allow_dns = true;

// do we currently have the block in place?
$block_dns = false;

$block_query = $db->query('SELECT * FROM domainlist_by_group WHERE domainlist_id = '.$domainlist_id.' AND group_id='.$group_id.';');

if (!$block_query) {
    throw new Exception('Error while querying gravity\'s client_by_group table: '.$db->lastErrorMsg());
}

$block_dns = $block_query->fetchArray() !== false ? true : false;

// remove block if it exists and it shouldn't.
if ($allow_dns == true and $block_dns == true) {
    log_message('Removing block for domainlist id '.$domainlist_id.' from group id '.$group_id);
    restart_dnsresolver();
    $db->query('DELETE FROM domainlist_by_group WHERE domainlist_id = '.$domainlist_id.' AND group_id='.$group_id.';');
}
elseif ($allow_dns == false and $block_dns == false) {
    log_message('Applying block for domainlist id '.$domainlist_id.' to group id '.$group_id);
    restart_dnsresolver();
    $db->query('INSERT INTO "domainlist_by_group" (domainlist_id,group_id) VALUES ('.$domainlist_id.', '.$group_id.');');
}
else{
    $message = 'Nothing to do - '.$now."/".$time_from."/".$time_until;

    if ($allow_dns == true) {
        $message .= " should be ALLOWING";
    }
    else{
        $message .= " should be BLOCKING";
    }
    
    if ($block_dns == true) {
        $message .= " are be BLOCKING";
    }
    else{
        $message .= " are be ALLOWING";
    }

    log_message($message);
}

/**
 * Log a message to the log file
 * 
 * @param string $message
 * @return void
 */
function log_message($message) {
    // default location of the log file
    $log_file = '/tmp/pihole-timed-access.log';

    // create the log file if it doesn't exist
    if (!file_exists($log_file)) touch($log_file);

    // add the message to the log file with a timestamp prefix
    $timestamp = '['.date('Y-m-d H:i:s').'] ';
    file_put_contents($log_file, $timestamp.$message."\n", FILE_APPEND);
}

/** 
 * Restart the DNS resolver
 * 
 * @param void
 * @return void
 */
function restart_dnsresolver()
{
    shell_exec('pihole arpflush');
    shell_exec('pihole restartdns');
}