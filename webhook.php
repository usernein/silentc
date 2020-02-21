<?php
ini_set('log_errors', 1);
ini_set('error_log', 'error_log.log');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

# Loading requirements...
if (!file_exists('madeline')) {
	mkdir('madeline');
}
if (!file_exists('madeline/madeline.php')) {
    copy('https://phar.madelineproto.xyz/madeline.php', 'madeline/madeline.php');
}
require 'phgram.phar';
use \phgram\{Bot, BotErrorHandler, ArrayObj};
use function \phgram\{ikb};
require 'bot.php';
require 'config.php';
require 'lang.php';
require 'functions.php';
require 'dbsetup.php';

# Creating variables...
$bot = new Bot(SILENTC_TOKEN, SILENTC_ADMIN);
$bot->report_show_view = 1;
$bot->report_show_data = 1;
BotErrorHandler::register(SILENTC_TOKEN, SILENTC_ADMIN);
BotErrorHandler::$verbose = false;
$langs = new Langs('strings/langs.json');

#$db = new MyPDO('sqlite:silentc.db');
$db = new MyPDO(...$db_data);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->setAttribute(PDO::ATTR_TIMEOUT, 15);

# Additional functions...
require 'functions/timer.php';
require 'functions/scrypt.php';

# Running...
$b = clone $bot;
$l = new Langs('strings/langs.json');
try {
	#if (@$_GET['beta']) if ($bot->UserID() && $bot->UserID() != 276145711) exit;
	handle($b, $l, $cfg);
} catch (Throwable $t) {
	echo $t;
}