<?php
class MyPDO extends PDO {
	public function querySingle($query) {
		$result = $this->query($query);
		return $result? $result->fetchColumn() : $result;
	}
	public function queryJson($query) {
		$result = $this->query($query);
		return $result? json($result->fetchColumn()) : $result;
	}
}/*
function mp() {
	global $mp;
	include_once 'madeline/madeline.php';
	$settings = [
		'app_info' => ['api_id' => 163474, 'api_hash' => 'ce8d0741f0cb0c8558e98334109126b4'],
		'logger'   => ['logger' => 2, 'logger_param' => 'madeline/silentc.log']
	]; 
	$mp = new danog\MadelineProto\API('madeline/silentc.sss', $settings);
}*/
function mp($token, $basedir = '.') {
	global $mp;
	if (!$mp) {
		include_once ($basedir.'/madeline/madeline.php');
		$settings = [
			'app_info' => ['api_id' => 163474, 'api_hash' => 'ce8d0741f0cb0c8558e98334109126b4'],
			'logger'   => ['logger' => 2, 'logger_param' => $basedir.'/madeline/silentc.log']
		]; 
		$is_logged = file_exists($basedir.'/madeline/silentc.sss');
		$mp = new danog\MadelineProto\API($basedir.'/madeline/silentc.sss', $settings);
		if (!$is_logged) {
			$mp->bot_login($token);
		}
	}
	return $mp;
}
class MPSend {
	public $mp;
	
	public function __construct($token = null) {
		$this->mp = mp($token);
	}
	
	public function doc($path, $params = []) {
		$caption = '';
		$chat_id = null;
		extract($params);
		global $bot;
		$chat_id = $chat_id ?? $bot->ChatID() ?? $bot->UserID();
		
		$name = basename($path);
		$filesize = filesize($path);
		$size = format_size($filesize);
		
		$msg_text = "Uploading <i>{$name}</i> ({$size})...";
		if (isset($postname)) {
			$realname = $name;
			$name = $postname;
			$msg_text = "Uploading <i>{$realname}</i> ({$size}) as <i>{$name}</i>...";
		}
		$msg = $bot->send($msg_text);
		$start_time = microtime(1);
		$progress = function ($progress) use ($name, $msg_text, $msg, $size, $start_time, $filesize) {
			static $last_time = 0;
			if ((microtime(true) - $last_time) < 1) return;
			$uploaded = ($filesize/100)*$progress;
			$speed = $uploaded/(microtime(1) - $start_time); # bytes per second
			$speed = format_size($speed).'/s';
			$round = round($progress, 2);
			$msg->edit("{$msg_text} {$round}%\n\nSpeed: {$speed}");
			$last_time = microtime(true);
		};
		try {
			$this->mp->messages->setTyping([
				'peer' => $chat_id,
				'action' => ['_' => 'sendMessageUploadDocumentAction', 'progress' => 0],
			]);
			
			$start = microtime(1);
			$sentMessage = $this->mp->messages->sendMedia([
				'peer' => $chat_id,
				'media' => [
					'_' => 'inputMediaUploadedDocument',
					'file' => new danog\MadelineProto\FileCallback($path, $progress),
					'attributes' => [
						['_' => 'documentAttributeFilename', 'file_name' => $name],
					]
				],
				'message' => $caption,
				'parse_mode' => 'HTML'
			]);
			$end = microtime(1);
		} catch (Throwable $t) {
			$msg->edit("Failed: $t", ['parse_mode' => null]);
			$bot->log($t);
			#$bot->log("$t");
			return ['ok' => false, 'err' => $t];
		}
		$msg->delete();
		return ['ok' => true, 'duration' => ($end-$start)];
	}
}
define('DS', '/');	
function delTree($folder, $del_root=true) {
	$folder = trim($folder, DS) . DS;
	$files = glob($folder.'*', GLOB_MARK);
	if (count($files) > 0) {
		foreach($files as $element) {
			if (is_dir($element)) {
				delTree($element);
			} else {
				unlink($element);
			}
		}
	}
	if ($del_root) rmdir($folder);

	return !file_exists($folder);
}

function glob_recursive($pattern, $flags = 0, $startdir = '') {
	$files = glob($startdir.$pattern, $flags);
	foreach (glob($startdir.'*', GLOB_ONLYDIR|GLOB_NOSORT|GLOB_MARK) as $dir) {
		$files = array_merge($files, glob_recursive($pattern, $flags, $dir));
	}
	sort($files);
	return $files;
}
function folderToZip($folder, &$zipFile, $exclusiveLength, array $except = []) {
	$files = glob_recursive($folder.'/*');
	$except = array_merge($except, ['..', '.']);
	foreach ($files as $filePath) {
		if (in_array(basename($filePath), $except)) continue;
		// Remove prefix from file path before add to zip. 
		$localPath = substr($filePath, $exclusiveLength); 
		if (is_file($filePath)) {
			$zipFile->addFile($filePath, $localPath);
		} else if (is_dir($filePath)) {
			// Add sub-directory. 
			$zipFile->addEmptyDir($localPath); 
			folderToZip($filePath, $zipFile, $exclusiveLength, $except);
		}
	}
}

function zipDir($sourcePath, $outZipPath, array $except = []) {
	global $bot;
	#$bot->send(json_encode(compact('sourcePath', 'outZipPath', 'except'), 480));
	@unlink($outZipPath);
	$zip = new ZipArchive(); 
	$res = $zip->open($outZipPath, ZIPARCHIVE::CREATE);
	#global $bot; $bot->send($res);
	folderToZip($sourcePath, $zip, strlen($sourcePath), $except); 
	#$zip->close();
	return $zip;
}

function unzipDir($zipPath, $outDirPath) {
	if (@file_exists($outDirPath) != true) {
		mkdir($outDirPath, 0777, true);
	}
	$zip = new ZipArchive();
	if ($zip->open($zipPath)) {
		$zip->extractTo($outDirPath);
	}
	$zip->close();
}
function convert_time($time) {
	if ($time == 0) {
		return 0;
	}
	// valid units
	$unit=array(-4=>'ps', -3=>'ns',-2=>'mcs',-1=>'ms',0=>'s');
	// logarithm of time in seconds based on 1000
	// take the value no more than 0, because seconds, we have the last thousand variable, then 60
	$i=min(0,floor(log($time,1000)));

	// here we divide our time into a number corresponding to units of measurement i.e. per million for seconds,
	// per thousand for milliseconds
	$t = @round($time/pow(1000,$i) , 1);
	if ($i === 0 && $t >= 60) {
		$minutes = floor($t/60);
		$remaining_s = round($t-($minutes*60));
		if ($remaining_s) {
			return "{$minutes}m{$remaining_s}s";
		} else {
			return "{$minutes}min";
		}
	}
	return $t.$unit[$i];
}
function calc_thumb_size($source_width, $source_height, $thumb_width, $thumb_height) {
	if ($thumb_width === "*" && $thumb_height === "*") {
		trigger_error("Both values must not be a wildcard");
		return false;
	}
	
	if ($thumb_width === "*") {
		$thumb_width = ceil($thumb_height * $source_width / $source_height);
	} else if ($thumb_height === "*") {
		$thumb_height = ceil($thumb_width * $source_height / $source_width);
	} else if (($source_width / $source_height) < ($thumb_width / $thumb_height)) {
		$thumb_width = ceil($thumb_height * $source_width / $source_height);
	} else if (($source_width / $source_height) > ($thumb_width / $thumb_height)) {
		$thumb_height = ceil($thumb_width * $source_height / $source_width);
	}
	
	return compact('thumb_width', 'thumb_height');
}
function rglob($pattern, $flags = 0) {
	$files = glob($pattern, $flags); 
	foreach (glob(dirname($pattern).'/*', GLOB_ONLYDIR|GLOB_NOSORT) as $dir) {
		$files = array_merge($files, rglob($dir.'/'.basename($pattern), $flags));
	}
	return $files;
}
function indoc($string) {
	global $bot;
	do {
		$name = substr(str_shuffle(md5('hhhh&#-')), 0, 10).'.txt';
	} while (file_exists($name));
	file_put_contents($name, $string);
	$bot->doc($name);
	unlink($name);
}
function protect() {
	global $bot;
	$protection = "{$bot->UpdateID()}.run";
	if (!file_exists($protection)) file_put_contents($protection, '1');
	else exit;
	$protection = realpath($protection);
	$string = "register_shutdown_function(function() { @unlink('{$protection}'); });";
	eval($string);
	return $protection;
}
function getUpdateFlavor($data) {
	global $type;
	$array = [];
	$array[] = $type;
	$array = array_merge($array, array_keys($data));
	
	if (isset($data['reply_to_message'])) {
		$array[] = 'reply';
	}
	if (isset($data['forward_from'])) {
		$array[] = 'forwarded_message';
	}
	if (isset($data['forward_from_chat'])) {
		$array[] = 'forwarded_post';
	}
	if (count(array_diff(['new_chat_photo', 'new_chat_title', 'delete_chat_photo', 'pinned_message'], $array)) === 3) {
		$array[] = 'service_alert';
	}

	return $array;
}
function getUsernames($message, $entities) { 
	$data = [];
	//$message_encode = iconv('utf-8', 'utf-16le', $message); //or utf-16
	$message_encode = mb_convert_encoding($message, "UTF-16", "UTF-8"); //or utf-16le
	foreach ($entities as $entitie) {
		if ($entitie['type'] == 'mention') {
			$username16 = substr($message_encode, $entitie['offset']*2, $entitie['length']*2);
			//$data[] = iconv('utf-16le', 'utf-8', $username16);
			$data[] = mb_convert_encoding($username16, "UTF-8", "UTF-16");
		}
	}
	return $data;
}
function setLanguage($user_id, $lang) {
	global $db, $bot;
	$language = $db->querySingle("SELECT language FROM user WHERE id={$user_id}") ?: $bot->Language() ?? 'en';
	$language = strtolower(str_replace(['-', '_'], '', $language));
	if (isset($lang->data->$language)) {
		$lang->language = $language;
	} else {
		$lang->language = 'en';
	}
	return $lang;
}
function get_any_id(string $username) {
	include_once 'madeline/madeline.php';
	global $bt;
	if (!$bt) {
		$settings = [
			'app_info' => ['api_id' => 163474, 'api_hash' => 'ce8d0741f0cb0c8558e98334109126b4'],
			'logger'   => ['logger' => 2, 'logger_param' => 'madeline/silentc.log']
		]; 
		$bt = new danog\MadelineProto\API('madeline/silentc.sss', $settings);
	}
	try {
		$info = $bt->get_info($username);
		if (isset($info['bot_api_id'])) {
			return $info['bot_api_id'];
		} else {
			return false;
		}
	} catch (Throwable $t) {
		return false;
	}
}
function get_id(string $username) {
	include_once 'madeline/madeline.php';
	global $bt;
	if (!$bt) {
		$settings = [
			'app_info' => ['api_id' => 163474, 'api_hash' => 'ce8d0741f0cb0c8558e98334109126b4'],
			'logger'   => ['logger' => 2, 'logger_param' => 'madeline/silentc.log']
		]; 
		$bt = new danog\MadelineProto\API('madeline/silentc.sss', $settings);
	}
	#try {
		$info = $bt->get_info($username);
		if ($info['type'] == 'user') {
			return $info['bot_api_id'];
		} else {
			return false;
		}
	/*} catch (Throwable $t) {
		return false;
	}*/
}
function get_chat_id(string $username) {
	include_once 'madeline/madeline.php';
	global $bt;
	if (!$bt) {
		$settings = [
			'app_info' => ['api_id' => 163474, 'api_hash' => 'ce8d0741f0cb0c8558e98334109126b4'],
			'logger'   => ['logger' => 2, 'logger_param' => 'madeline/silentc.log']
		]; 
		$bt = new danog\MadelineProto\API('madeline/silentc.sss', $settings);
	}
	#try {
		$info = $bt->get_info($username);
		if ($info['type'] != 'user') {
			return $info['bot_api_id'];
		} else {
			return false;
		}
	/*} catch (Throwable $t) {
		return false;
	}*/
}
function setTimezone($user_id) {
	global $db;
	$timezone = $db->querySingle("SELECT timezone FROM user WHERE id={$user_id}") ?: 'UTC';
	date_default_timezone_set($timezone);
}
function json($value, $default_value = "cDEFAULTc") {
	if (is_array($value) || is_object($value)) {
		return json_encode($value, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PRESERVE_ZERO_FRACTION | JSON_NUMERIC_CHECK);
	} else {
		$dec = json_decode($value, true) ?? [];
		return $dec;
	}
}
function dump($value) {
	ob_start();
	var_dump($value);
	$output = ob_get_contents();
	ob_end_clean();
	return $output;
}
function delete($rule) {
	global $bot;
	$chat = $bot->ChatID();
	$msg = $bot->MessageID();
	@$bot->deleteMessage(['chat_id' => $chat, 'message_id' => $msg]);
	$str = "Deleting {$msg} in {$chat} for {$rule}";
	if ($chat == -1001256087497) { // beta testing channel
		$bot->log($str);
	}
	
	#$time = time();
	#$db->query("INSERT INTO stats (id, date, rule) VALUES ({$chat}, {$time}, '{$rule}')");
}
function getAdmins(int $chat, $lang) {
	global $db, $bot;
	$mp_admins = json($db->querySingle("SELECT mp_admins FROM channel WHERE id={$chat}"));
	if ($mp_admins && (time()-$mp_admins['time'] < 60)) return $mp_admins['admins'];
	$bot->answer_callback($lang->updating_admin_list);
	
	try {
		include_once 'madeline/madeline.php';
		$settings = [
			'app_info' => ['api_id' => 163474, 'api_hash' => 'ce8d0741f0cb0c8558e98334109126b4'],
			'logger'   => ['logger' => 2, 'logger_param' => 'madeline/silentc.log'],
			//'peer'     => ['cache_all_peers_on_startup' => true],
		];
		$bt = new danog\MadelineProto\API('madeline/silentc.sss', $settings);
		$admins = $bt->channels->getParticipants(['channel' => $chat, 'filter' => ['_' => 'channelParticipantsAdmins'], 'offset' => 0, 'limit' => 200, 'hash' => 0]);
	
		$admins = array_column($admins['users'], null, 'id');
		$mp_admins = ['time' => time(), 'admins' => $admins];
		$js_ma = $db->quote(json($mp_admins));
		$db->query("UPDATE channel SET mp_admins=$js_ma WHERE id=$chat");
		
		return $admins;
	} catch (Throwable $t) {
		return [];
	}
}
function getGMT($timezone = null) {
	if (!$timezone) {
		$timezone = date_default_timezone_get();
	}
	$target_time_zone = new DateTimeZone($timezone);
	$date_time = new DateTime('now', $target_time_zone);
	$gmt = 'GMT '.$date_time->format('P');
	return $gmt;
}
function array_group($array, $line_limit = 2, $preserve_keys = FALSE) {
	$group = [[]];
	$line = 0;
	foreach ($array as $key => $item) {
		if (count($group[$line]) >= $line_limit) {
			$line++;
			$group[$line] = [];
		}
		if ($preserve_keys) {
			$group[$line][$key] = $item;
		} else {
			$group[$line][] = $item;
		}
	}
	return $group;
}
function ask_exec_timer($info) {
	extract($info);
	@date_default_timezone_set($timezone);
	# timezone, time, mode, last_executed, created_at
	$last = $last_executed;
	if (date('Hi') >= date('Hi', $time)) {
		if ($last == 0) {
			if (date('ymd') > date('ymd', $created_at)) {
				return true;
			} else if (date('ymd') == date('ymd', $created_at)) {
				return (date('Hi', $created_at) <= date('Hi', $time));
			}
		} else {
			return (date('ymd') > date('ymd', $last));
		}
	}
}
function array_not_unique($raw_array) {
    $dupes = array();
    natcasesort($raw_array);
    reset($raw_array);

    $old_key   = NULL;
    $old_value = NULL;
    foreach ($raw_array as $key => $value) {
        if ($value === NULL) { continue; }
        if (strcasecmp($old_value, $value) === 0) {
            $dupes[$old_key] = $old_value;
            $dupes[$key]     = $value;
        }
        $old_value = $value;
        $old_key   = $key;
    }
    return $dupes;
}
function format_size($bytes, $precision = 2) {
	$units = array(
		'B',
		'KB',
		'MB',
		'GB',
		'TB'
	);
	if (($bytes > 0 && $bytes < 1) || ($bytes < 0 && $bytes > -1)) {
		return $bytes.' B';
	}
	#$bytes = max($bytes, 0); # if $bytes is negative, max return 0
	if ($negative = ($bytes < 0)) {
		$bytes *= -1;
	}
	$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
	$pow = min($pow, count($units) - 1);
	$bytes /= pow(1024, $pow);
	return ($negative? '-' : '').round($bytes, $precision) . ' ' . $units[$pow];
}
function getSandbox() {
	#$tags = ['if', 'block', 'set', 'for'];
	$tags = [
		'autoescape',
		'filter',
		'do',
		'for',
		'set',
		'if',
		'spaceless',
		'with'
	];
	#$filters = ['merge', 'join', 'striptags', 'date', 'escape', 'trans', 'split', 'length', 'slice', 'lower', 'raw'];
	$filters = [
		'abs',
		'batch',
		'capitalize',
		'column',
		'convert_encoding',
		'date',
		'date_modify',
		'default',
		'escape',
		'first',
		'format',
		'join',
		'json_encode',
		'keys',
		'last',
		'length',
		'lower',
		'merge',
		'nl2br',
		'number_format',
		'raw',
		'replace',
		'reverse',
		'slice',
		'sort',
		'split',
		'striptags',
		'title',
		'trim',
		'upper',
		'url_encode',
		'filter',
	];
	$methods = [
		'DateTime' => ['getTimestamp'],
	];
	$properties = [];
	#$functions = ['dump'];
	$functions = [
		'attribute',
		'constant',
		'cycle',
		'date',
		'random',
		'range',
		'dump',
		'max',
		'min',
	];

	$policy = new \Twig\Sandbox\SecurityPolicy($tags, $filters, $methods, $properties, $functions);
	$sandbox = new \Twig\Extension\SandboxExtension($policy, true);
	return $sandbox;
}
function add_lang($key, $lang, $str) {
	$lang->data->$lang->$key = $str;
	$lang->save();
}