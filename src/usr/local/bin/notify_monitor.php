#!/usr/local/bin/php-cgi -q
<?php
/*
 * notify_monitor.php
 *
 * part of pfSense (https://www.pfsense.org)
 * Copyright (c) 2017-2024 Rubicon Communications, LLC (Netgate)
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
 */

try {
	include_once('util.inc');
	include_once('notices.inc');

	$ret = try_lock("notifyqueue_running", 0);
	if ($ret === NULL) {
		//only 1 monitor needs to be running.
		exit;
	}

	notices_sendqueue();
} catch (Exception $e) {
	log_error(gettext("Unable to send notices queue") . ": " . $e->getMessage());
}
