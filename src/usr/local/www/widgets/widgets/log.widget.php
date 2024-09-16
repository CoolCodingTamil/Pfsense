<?php
/*
 * log.widget.php
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2024 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2007 Scott Dale
 * Copyright (c) 2022-2024 Louis van Breda
 * All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.

 * >> In the code extensive debug options in order to analyse the impact of code changes on the performance of the widget. <<
 * - ^$DebugOn^ some lines lower is used to activate the debug
 * - lots of lines starting with //DEBUG: which can be enabled to have a more detailed view on performance
 * - a function ^date_mdiff^b have been added to calculate the delay between code steps in microseconds
 * - the debug output is logged in /var/log/mylog.log
 * - the debug log can be monitored or viewed via ^tail -F /var/log/mylog.log^

 * $date = new DateTime();
 * $date = $date->format("y:m:d h:i:s");

 * /usr/local/www/status_logs_common.inc
 * /etc/inc/syslog.inc

 * function find_rule_by_number($rulenum, $trackernum, $type="block") {
 * surches in output generated by "/sbin/pfctl"
 * if type = rdr =>  exec("/sbin/pfctl -vvPsn -a \"miniupnpd\" | /usr/bin/egrep " . escapeshellarg("^@{$rulenum}"), $buffer);
 * if type = 'else'=> exec("/sbin/pfctl -vvPsr | /usr/bin/egrep " . escapeshellarg($lookup_pattern), $buffer);
 */

require_once("guiconfig.inc");
require_once("pfsense-utils.inc");
require_once("functions.inc");

/* In an effort to reduce duplicate code, many shared functions have been moved here. */
require_once("syslog.inc");

/* Enable or disable debugging (detail level depending on removed ^//DEBUG^statements */
$DebugOn = false;
/* Debugging options */
$logFileName    = '/var/log/mylog.log';
$logContent     = "Analysing firewalllog widget".PHP_EOL;
$dateFormat     = "Ymd_H:i:s";
$dateFormat_us  = "Ymd_H:i:s_u";
$dateFormatDiff = "%s_%u";

if ($_REQUEST['widgetkey'] && !$_REQUEST['ajax']) {
	set_customwidgettitle($user_settings);

	if (is_numeric($_POST['filterlogentries'])) {
		$user_settings['widgets'][$_POST['widgetkey']]['filterlogentries'] = $_POST['filterlogentries'];
	} else {
		unset($user_settings['widgets'][$_POST['widgetkey']]['filterlogentries']);
	}

	$acts = array();
	if ($_POST['actpass']) {
		$acts[] = "Pass";
	}
	if ($_POST['actblock']) {
		$acts[] = "Block";
	}
	if ($_POST['actreject']) {
		$acts[] = "Reject";
	}

	if (!empty($acts)) {
		$user_settings['widgets'][$_POST['widgetkey']]['filterlogentriesacts'] = implode(" ", $acts);
	} else {
		unset($user_settings['widgets'][$_POST['widgetkey']]['filterlogentriesacts']);
	}
	unset($acts);

	if (($_POST['filterlogentriesinterfaces']) and ($_POST['filterlogentriesinterfaces'] != "All")) {
		$user_settings['widgets'][$_POST['widgetkey']]['filterlogentriesinterfaces'] = trim($_POST['filterlogentriesinterfaces']);
	} else {
		unset($user_settings['widgets'][$_POST['widgetkey']]['filterlogentriesinterfaces']);
	}

	if (is_numeric($_POST['filterlogentriesinterval'])) {
		$user_settings['widgets'][$_POST['widgetkey']]['filterlogentriesinterval'] = $_POST['filterlogentriesinterval'];
	} else {
		unset($user_settings['widgets'][$_POST['widgetkey']]['filterlogentriesinterval']);
	}

	save_widget_settings($_SESSION['Username'], $user_settings["widgets"], gettext("Saved Filter Log Entries via Dashboard."));
	Header("Location: /");
	exit(0);
}

// Register start moment of widget refresh
$date0 = new DateTime($date);

if ($DebugOn) { $logContent .= date($dateFormat)."_^START^".PHP_EOL; }

// When this widget is included in the dashboard, $widgetkey is already defined before the widget is included.
// When the ajax call is made to refresh the firewall log table, 'widgetkey' comes in $_REQUEST.
if ($_REQUEST['widgetkey']) {
	$widgetkey = $_REQUEST['widgetkey'];
}
//DEBUG: $logContent .= date($dateFormat)."_After request widgetkey".PHP_EOL;

$iface_descr_arr = get_configured_interface_with_descr();
//DEBUG: $logContent .= date($dateFormat)."_After nentries".PHP_EOL;

$nentries = isset($user_settings['widgets'][$widgetkey]['filterlogentries']) ? $user_settings['widgets'][$widgetkey]['filterlogentries'] : 5;
//DEBUG: $logContent .= date($dateFormat)."_After fetch iface_descr_arr".PHP_EOL;

//set variables for log
$nentriesacts = isset($user_settings['widgets'][$widgetkey]['filterlogentriesacts']) ? $user_settings['widgets'][$widgetkey]['filterlogentriesacts'] : 'All';
$nentriesinterfaces = isset($user_settings['widgets'][$widgetkey]['filterlogentriesinterfaces']) ? $user_settings['widgets'][$widgetkey]['filterlogentriesinterfaces'] : 'All';
//DEBUG: $logContent .= date($dateFormat)."_After filter array definition".PHP_EOL;

$filterfieldsarray = array(
	"act" => $nentriesacts,
	"interface" => isset($iface_descr_arr[$nentriesinterfaces]) ? $iface_descr_arr[$nentriesinterfaces] : $nentriesinterfaces
);
//DEBUG: $logContent .= date($dateFormat)."_After filling_filter array".PHP_EOL;

$nentriesinterval = isset($user_settings['widgets'][$widgetkey]['filterlogentriesinterval']) ? $user_settings['widgets'][$widgetkey]['filterlogentriesinterval'] : 60;
//DEBUG: $logContent .= date($dateFormat)."_After entries_interval".PHP_EOL;

$filter_logfile = "{$g['varlog_path']}/filter.log";

// >>> The maximum no of lines to anayse needs to be raised from 50 to 250. 50 is to small considering a potentially/probably present widget selection filter. <<<
$filterlog = conv_log_filter($filter_logfile, $nentries, 250, $filterfieldsarray);
//DEBUG: $logContent .= date($dateFormat)."_After ^conv_log_filter^".PHP_EOL;

$widgetkey_nodash = str_replace("-", "", $widgetkey);
//DEBUG: $logContent .= date($dateFormat)."_After widgetkey_nodash".PHP_EOL;

if (!$_REQUEST['ajax']) {
?>
<script type="text/javascript">
//<![CDATA[
	var logWidgetLastRefresh<?=htmlspecialchars($widgetkey_nodash)?> = <?=time()?>;
//]]>
</script>

<?php } ?>

<table class="table table-striped table-hover">
	<thead>
		<tr>
			<th><?=gettext("Act");?></th>
			<th><?=gettext("Time");?></th>
			<th><?=gettext("Interface");?></th>
			<th><?=gettext("Source");?></th>
			<th><?=gettext("Destination");?></th>
		</tr>
	</thead>
	<tbody>
<?php

	// Fetch defined rules from 'pfctl'

	$date1 = new DateTime($date);

	$cmdtoexecute="/sbin/pfctl -vvPsr | grep ^@" ;
	$thefile = shell_exec($cmdtoexecute);

	if ($DebugOn) {
		$date2 = new DateTime($date);
		$timediff = date_mdiff($date1, $date2);
		$logContent .= date($dateFormat)."_Fetching pfctl output did take: ".$timediff.PHP_EOL;
	}

	// Place fetched rules in the '$rule_lines' array

//DEBUG:	$date1 = new DateTime($date);
	$rule_lines = explode("\n", $thefile, -1);

//DEBUG:	$date2 = new DateTime($date);
//DEBUG:	$timediff = date_mdiff($date1, $date2);
//DEBUG:	$logContent .= date($dateFormat)."_Placing the pfctl output in array did take: ".$timediff.PHP_EOL;

/*
	EXAMPLE pfctl output. The GUI-rule as indentified with 'ridentifier / tracker' is translated
	to multiple firewall rules (rulenumbers) e.g. one per specified port.
	Used defined rules also have the label 'label "USER_RULE"'

	[ Last Active Time: N/A ]
	@2059 block return in log quick on igb2 inet6 all label "USER_RULE: What did I block !!??" label "id:1652387236" ridentifier 1652387236
	[ Evaluations: 0         Packets: 0         Bytes: 0           States: 0     ]
	[ Inserted: uid 0 pid 98755 State Creations: 0     ]
	[ Last Active Time: N/A ]
	@2060 anchor "tftp-proxy/*" all
*/

	// Create 'rule key array' in favor of rule lookups
	$date1 = new DateTime($date);

	// array index start with 0
	$idx = 0;
	$norules = 0;
	foreach ($rule_lines as $someline):

		// position 0 is the first position
		$firstcha = substr($someline, 0, 1);
		// we are only intrested in lines starting "@"
		if ($firstcha == "@") {
			$rulekeys[] = array(
				'rulenum' => substr($someline, 1, strpos($someline, ' ')),
				'rawidx'  => $idx,
			);
		$norules++;
		}
		 $idx++;
	endforeach;

//DEBUG:	$date2 = new DateTime($date);
//DEBUG:	$timediff = date_mdiff($date1, $date2);
//DEBUG:	$logContent .= date($dateFormat)."_Fill index array lookup did take: ".$timediff.PHP_EOL;

	if ($DebugOn) {	$logContent .= date($dateFormat)."_Index array created. No lines:".$idx." No rules:".$norules.PHP_EOL; }

	foreach ($filterlog as $filterent):
		if ($filterent['version'] == '6') {
			$srcIP = "[" . htmlspecialchars($filterent['srcip']) . "]";
			$dstIP = "[" . htmlspecialchars($filterent['dstip']) . "]";
		} else {
			$srcIP = htmlspecialchars($filterent['srcip']);
			$dstIP = htmlspecialchars($filterent['dstip']);
		}

		if ($filterent['act'] == "block") {
			$iconfn = "fa-solid fa-times text-danger";
		} else if ($filterent['act'] == "reject") {
			$iconfn = "fa-regular fa-hand text-warning";
		} else if ($filterent['act'] == "match") {
			$iconfn = "fa-solid fa-filter";
		} else {
			$iconfn = "fa-solid fa-check text-success";
		}

//DEBUG:		$date1 = new DateTime($date);

//OLD METHOD		>>> Fetching rule data this way for each selected rule, simply takes far too long !! <<<
//OLD METHOD		$rule = find_rule_by_number($filterent['rulenum'], $filterent['tracker'], $filterent['act']);

//DEBUG:		$date2 = new DateTime($date);
//DEBUG:		$timediff = date_mdiff($date1, $date2);
//DEBUG:		$logContent .= date($dateFormat)."_Rule lookup did take: ".$timediff.PHP_EOL;

		// Putting <wbr> tags after each ':' allows the string to word-wrap at that point
		$srcIP = str_replace(':', ':<wbr>', $srcIP);
		$dstIP = str_replace(':', ':<wbr>', $dstIP);

		// Log what is entered in the 'filterend array' (for debug purposes)
//DEBUG:	$logContent .= date($dateFormat)."_INTO_ResultArray Act:".$filterent['act']." Time:".$filterent['time']." Interface:".$filterent['interface'].PHP_EOL;

		// Fetch rule content / Search the rule in the 'key array'

//DEBUG:		$date1 = new DateTime($date);

		$rule = "no rule info available";
		foreach ($rulekeys as $actrule):
			if ($actrule['rulenum'] == $filterent['rulenum']) {
				$rawidx = $actrule['rawidx'];
				$rule = $rule_lines[$rawidx];
			break;
			}
		endforeach;

//DEBUG:	$date2 = new DateTime($date);
//DEBUG:	$timediff = date_mdiff($date1, $date2);
//DEBUG:	$logContent .= date($dateFormat)."_Rule lookup did take: ".$timediff.PHP_EOL;

/*
	Only use the relevant part of the timestamp
	Time:2022-11-21 22:00:56.309678+01:00
	     0123456789012345678901 = 21 cha
*/

//	Separating php-code and html output (to make it cleaner and to hand over all lines >> in one go <<; do not know if this improves performance)

	$resultarray[] = array(
		'rulenum' => $filterent['rulenum'],
		'tracker' => $filterent['tracker'],
		'act' => $filterent['act'],
		'iconfn' => $iconfn,
		'time'=> substr($filterent['time'],0,22),
		'interface' => $filterent['interface'],
		'srcip' => $filterent['srcip'],
		'dstip' => $filterent['dstip'],
		'dstport'=> $filterent['dstport'],
		'srcIP' => $srcIP,
		'dstIP' => $dstIP,
		'rule' => $rule,
	);

//DEBUG: $logContent .= date($dateFormat)."_After adding enty to resultarray".PHP_EOL;

	endforeach;

//DEBUG: $logContent .= date($dateFormat)."_Before HTML".PHP_EOL;

	// Hand over result to "HTML"

	foreach ($resultarray as $resultent):

		// Log what is entered in the result array (for debug purposes)
//DEBUG:		$logContent .= date($dateFormat)."_FROM_ResultArray Rulenum:".$resultent['rulenum']." Tracker:".$resultent['tracker'].PHP_EOL;
//DEBUG:		$logContent .= date($dateFormat)."_FROM_ResultArray Act:".$resultent['act']." Time:".$resultent['time']." Interface:".$resultent['interface'].PHP_EOL;

?>

		<tr>
			<td><i class="<?=$resultent['iconfn']?>" style="cursor: pointer;" onclick="javascript:getURL('status_logs_filter.php?getrulenum=<?php echo "{$resultent['rulenum']},{$resultent['tracker']},{$resultent['act']}"; ?>', outputrule);"
			title="<?=gettext("Rule that triggered this action: ") . htmlspecialchars($resultent['rule'])?>">
			</a></td>
			<td title="<?=htmlspecialchars($resultent['time'])?>"><?=substr(htmlspecialchars($resultent['time']),0,-3)?></td>
			<td><?=htmlspecialchars($resultent['interface']);?></td>
			<td><a href="diag_dns.php?host=<?=$resultent['srcip']?>"
				title="<?=gettext("Reverse Resolve with DNS");?>"><?=$resultent['srcIP']?></a>
			</td>
			<td><a href="diag_dns.php?host=<?=$resultent['dstip']?>"
				title="<?=gettext("Reverse Resolve with DNS");?>"><?=$resultent['dstIP']?></a><?php
				if ($resultent['dstport']) {
					print ':' . htmlspecialchars($resultent['dstport']);
				}
				?>
			</td>
		</tr>

	<?php
	endforeach;

//DEBUG: $logContent .= date($dateFormat)."_After HTML foreeach".PHP_EOL;

	if ($DebugOn) {
		$date99 = new DateTime($date);
		$timediff = date_mdiff($date0, $date99);
		$logContent .= date($dateFormat)."_widget update took: ".$timediff.PHP_EOL;
	}

	if ($DebugOn) {
		if ($handle = fopen($logFileName, 'a'))
		{
		fwrite($handle, $logContent);
		}
		fclose($handle);
	}

	if (count($filterlog) == 0) {
		print '<tr class="text-nowrap"><td colspan=5 class="text-center">';
		print gettext('No logs to display');
		print '</td></tr>';
	}
?>

	</tbody>
</table>

<?php

/* for AJAX response, we only need the panel-body */
if ($_REQUEST['ajax']) {
	exit;
}
?>

<script type="text/javascript">
//<![CDATA[

events.push(function(){
	// --------------------- Centralized widget refresh system ------------------------------

	// Callback function called by refresh system when data is retrieved
	function logs_callback(s) {
		$(<?=json_encode('#widget-' . $widgetkey . '_panel-body')?>).html(s);
	}

	// POST data to send via AJAX
	var postdata = {
		ajax: "ajax",
		widgetkey : <?=json_encode($widgetkey)?>,
		lastsawtime: logWidgetLastRefresh<?=htmlspecialchars($widgetkey_nodash)?>
	 };

	// Create an object defining the widget refresh AJAX call
	var logsObject = new Object();
	logsObject.name = "NewFirewall Logs";
	logsObject.url = "/widgets/widgets/log.widget.php";
	logsObject.callback = logs_callback;
	logsObject.parms = postdata;
	logsObject.freq = <?=$nentriesinterval?>/5;

	// Register the AJAX object
	register_ajax(logsObject);

	// ---------------------------------------------------------------------------------------------------
});
//]]>
</script>

<!-- close the body we're wrapped in and add a configuration-panel -->
</div>
<div id="<?=$widget_panel_footer_id?>" class="panel-footer collapse">

<?php
$pconfig['nentries'] = isset($user_settings['widgets'][$widgetkey]['filterlogentries']) ? $user_settings['widgets'][$widgetkey]['filterlogentries'] : '';
$pconfig['nentriesinterval'] = isset($user_settings['widgets'][$widgetkey]['filterlogentriesinterval']) ? $user_settings['widgets'][$widgetkey]['filterlogentriesinterval'] : '';
?>
	<form action="/widgets/widgets/log.widget.php" method="post"
		class="form-horizontal">
		<input type="hidden" name="widgetkey" value="<?=htmlspecialchars($widgetkey); ?>">
		<?=gen_customwidgettitle_div($widgetconfig['title']); ?>

		<div class="form-group">
			<label for="filterlogentries" class="col-sm-4 control-label"><?=gettext('Number of entries')?></label>
			<div class="col-sm-6">
				<input type="number" name="filterlogentries" id="filterlogentries" value="<?=$pconfig['nentries']?>" placeholder="5"
					min="1" max="50" class="form-control" />
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-4 control-label"><?=gettext('Filter actions')?></label>
			<div class="col-sm-6 checkbox">
			<?php $include_acts = explode(" ", strtolower($nentriesacts)); ?>
			<label><input name="actpass" type="checkbox" value="Pass"
				<?=(in_array('pass', $include_acts) ? 'checked':'')?> />
				<?=gettext('Pass')?>
			</label>
			<label><input name="actblock" type="checkbox" value="Block"
				<?=(in_array('block', $include_acts) ? 'checked':'')?> />
				<?=gettext('Block')?>
			</label>
			<label><input name="actreject" type="checkbox" value="Reject"
				<?=(in_array('reject', $include_acts) ? 'checked':'')?> />
				<?=gettext('Reject')?>
			</label>
			</div>
		</div>

		<div class="form-group">
			<label for="filterlogentriesinterfaces" class="col-sm-4 control-label">
				<?=gettext('Filter interface')?>
			</label>
			<div class="col-sm-6 checkbox">
				<select name="filterlogentriesinterfaces" id="filterlogentriesinterfaces" class="form-control">
			<?php foreach (array("All" => "ALL") + $iface_descr_arr as $iface => $ifacename):?>
				<option value="<?=$iface?>"
						<?=($nentriesinterfaces==$iface?'selected':'')?>><?=htmlspecialchars($ifacename)?></option>
			<?php endforeach;?>
				</select>
			</div>
		</div>

		<div class="form-group">
			<label for="filterlogentriesinterval" class="col-sm-4 control-label"><?=gettext('Update interval')?></label>
			<div class="col-sm-4">
				<input type="number" name="filterlogentriesinterval" id="filterlogentriesinterval" value="<?=$pconfig['nentriesinterval']?>" placeholder="60"
					min="5" class="form-control" />
			</div>
			<?=gettext('Seconds');?>
		</div>

		<div class="form-group">
			<div class="col-sm-offset-4 col-sm-6">
<!-- In the past:		<button type="submit" class="btn btn-primary"><i class="fa fa-save icon-embed-btn"></i><?=gettext('Save')?></button> -->
				<button type="submit" class="btn btn-primary"><i class="fa-solid fa-save icon-embed-btn"></i><?=gettext('Save')?></button>
			</div>
		</div>
	</form>

<script type="text/javascript">
//<![CDATA[
if (typeof getURL == 'undefined') {
	getURL = function(url, callback) {
		if (!url)
			throw 'No URL for getURL';
		try {
			if (typeof callback.operationComplete == 'function')
				callback = callback.operationComplete;
		} catch (e) {}
			if (typeof callback != 'function')
				throw 'No callback function for getURL';
		var http_request = null;
		if (typeof XMLHttpRequest != 'undefined') {
			http_request = new XMLHttpRequest();
		}
		else if (typeof ActiveXObject != 'undefined') {
			try {
				http_request = new ActiveXObject('Msxml2.XMLHTTP');
			} catch (e) {
				try {
					http_request = new ActiveXObject('Microsoft.XMLHTTP');
				} catch (e) {}
			}
		}
		if (!http_request)
			throw 'Both getURL and XMLHttpRequest are undefined';
		http_request.onreadystatechange = function() {
			if (http_request.readyState == 4) {
				callback( { success : true,
					content : http_request.responseText,
					contentType : http_request.getResponseHeader("Content-Type") } );
			}
		};
		http_request.open('GET', url, true);
		http_request.send(null);
	};
}

function outputrule(req) {
	alert(req.content);
}
//]]>
</script>
