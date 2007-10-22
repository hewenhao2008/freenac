#!/usr/bin/php -f 
<?
/**
 * /opt/nac/bin/ping_switch.php
 *
 * Long description for file:
 *
 * This script pings a switch port to know if it is up or down.
 *
 * TESTED:
 *      Catalyst 2940 (IOS), 3560 (IOS), 2948 (CatOS), 2960G (IOS)
 *
 * USAGE :
 *      /opt/nac/bin/ping_switch.php
 *
 * PHP version 5
 *
 * LICENSE: This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as published
 * by the Free Software Foundation.
 *
 * @package                     FreeNAC
 * @author                      Hector Ortiz (FreeNAC Core Team)
 * @copyright                   2007 FreeNAC
 * @license                     http://www.gnu.org/copyleft/gpl.html   GNU Public License Version 2
 * @version                     SVN: $Id$
 * @link                        http://www.freenac.net
 *
 */


chdir(dirname(__FILE__));
set_include_path("../:./");
require_once "bin/funcs.inc.php";               # Load settings & common functions
require_once "bin/snmp_defs.inc.php";

$logger->setDebugLevel(0);
$logger->setLogToStdOut(false);

//
//---------------------------------------  Main stuff ----------------------------------------------------------
//
db_connect();

#Look up the switches in the database
$query = "SELECT id, ip FROM switch WHERE scan='1'";
$logger->debug($query,2);
$res = mysql_query($query);
if (!$res)
{
   $logger->logit(mysql_error(),LOG_ERROR);
   exit(1);
}

while ($row = mysql_fetch_array($res,MYSQL_ASSOC))
{
   $switch_ip = $row['ip'];
   $switch_id = $row['id'];
   $logger->debug("Querying switch $switch_ip for ports' status");
   
   #Query switch for list of ports and their status
   $status_of_ports = @snmprealwalk($switch_ip, $snmp_rw, $snmp_port['ad_status']);
   $ports_on_switch = @snmprealwalk($switch_ip, $snmp_rw, $snmp_if['name']);
   if ( !$ports_on_switch || !$status_of_ports )
   {
      $logger->logit("Couldn't communicate with switch $switch_ip");
      continue;
   }
   #Clean values
   $ports_on_switch = array_map("remove_type", $ports_on_switch);
   $status_of_ports = array_map("remove_type", $status_of_ports);

   #Query list from ports from the database
   $query = "SELECT id, name FROM port WHERE switch='$switch_id'";
   $logger->debug($query,2);
   $result = mysql_query($query);
   if (! $result)
   {
      $logger->logit(mysql_error(),LOG_ERROR);
      continue;
   }
   
   while ($port_row = mysql_fetch_array($result, MYSQL_ASSOC))
   {
      $port_id = $port_row['id'];
      $port_name = $port_row['name'];
      # Get only the SNMP port index
      # Look for the port name in $ports_on_switch and return its key, and strip the '.' from the beggining of the line
      $port_index = ltrim( str_get_last( array_isearch($port_name,$ports_on_switch), '.', 1), '.');
      if (empty($port_index))
         continue;
      
      # Get port status
      $port_status = array_find_key($port_index, $status_of_ports, '.', 1);
      if (strpos($port_status,'1'))
         $status=1;
      else if (strpos($port_status,'2'))
         $status=2;
      else if (strpos($port_status,'3'))
         $status=3;

      # Update port's info in the DB
      $query = "UPDATE port SET get_status='$status', last_monitor=NOW() WHERE id='$port_id';";
      $logger->debug($query,2);
      mysql_query($query);
      
   }
   # Update switch's last_monitor
   $query = "UPDATE switch set last_monitor=NOW() where id='$switch_id';";
   $logger->debug($query,2);
   mysql_query($query);
}
exit(0);
?>