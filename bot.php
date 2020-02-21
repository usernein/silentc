<?php
use function \phgram\{ikb, hide_kb, kb};
function handle ($bot, $lang, $cfg) {
  $db = new MyPDO('sqlite:silentc.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->setAttribute(PDO::ATTR_TIMEOUT, 15);


	$data = $bot->data;
	$type = array_keys($data)[1];
	$data = array_values($data)[1];
	$chat_id = $bot->ChatID() ?? 000;
	$channel_exists = $db->querySingle("SELECT 1 FROM channel WHERE id={$chat_id}");
	

	if ($channel_exists) {
		$message_id = @$data['message_id'];
		if (!$message_id) return;
		
		$channel = $db->query("SELECT * FROM channel WHERE id={$chat_id}")->fetch();
		$timers = json($channel['timers']);
		$fixed = json($channel['fixed_timers']);
		$switch = $channel['switch'];
		
		## admins checking ##
		$author = @$data['author_signature'];
		if ($author) {
			$whitelist = json($channel['whitelist']);
			$adms = $bot->getChatAdministrators(['chat_id' => $chat_id]);
			$adms = $adms->result->asArray();
			# only the "user" array of each adm
			$adms = array_column($adms, 'user');
			$adms = array_map(function($val) {
				return ['id' => $val['id'], 'signature' => $val['first_name'].(!is_null(@$val['last_name'])? ' '.$val['last_name'] : '')];
			}, $adms);
			# {id:signature,id:signature}
			$adms = array_column($adms, 'signature', 'id');
			# return only duplicated values
			$dup_adms = array_not_unique(array_map('serialize', $adms));
			$dup_adms = array_map('unserialize', $dup_adms);
			
			if (in_array($author, $dup_adms)) { # if the signature is duplicated
				# the admins using $author as signature
				$dup_adms = array_filter($dup_adms, function($signature) use ($author) {
					return ($signature == $author);
				});
				if (count(array_diff(array_keys($dup_adms), $whitelist)) != count($dup_adms)) { # if one of the duplicated admins is whitelisted
					$mentions = [];
					foreach ($dup_adms as $id => $signature) {
						$signature = htmlspecialchars($signature);
						$mentions[] = "<a href='tg://user?id={$id}'>{$signature}</a>";
					}
					if (count($mentions) == 1) {
						$dup_count = 1;
						$mentions = $mentions[0];
					} else {
						$dup_count = count($mentions);
						$last_mention = array_pop($mentions);
						$mentions = join(', ', $mentions)." %s {$last_mention}";
					}
					
					$sudoers = array_filter(json($channel['adm']), function($adm) {
						return $adm['sudo'];
					});
					
					foreach ($sudoers as $id => $adm) {
						$lang = setLanguage($id, $lang);
						$bot->forwardMessage(['from_chat_id' => $chat_id, 'chat_id' => $id, 'message_id' => $message_id]);
						$bot->send($lang->same_signature_alert($dup_count, sprintf($mentions, $lang->and)), ['reply_markup' => ikb([
							[[$lang->same_signature_del, "delete_post $chat_id $message_id"]],
							[[$lang->same_signature_nothing, 'remove_ikb']]
						]), 'chat_id' => $id]);
					}
				}
			} else { # if the signature is unique
				$id = array_search($author, $adms);
				if (in_array($id, json($channel['whitelist']))) {
					return; # WHITELISTED :D
				}
			}
		}
		
		foreach ($fixed as $time => &$info) {
			extract($info); # time, timezone, mode, last_executed
			@date_default_timezone_set($timezone);
			$last = $last_executed;
			$bool = ask_exec_timer($info);
			if ($bool) {
				$switch = $mode = (int)$mode;
				$info['last_executed'] = time();
				$fix = json($fixed);
				$db->prepare("UPDATE channel SET fixed_timers=? WHERE id={$channel['id']}")->execute([$fix]);
				$db->query("UPDATE channel SET switch={$mode} WHERE id={$channel['id']}");
			}
		}
		foreach ($timers as $time => $mode) {
			$time = (int)$time;
			if (time() >= $time) {
				$switch = $mode = (int)$mode;
				unset($timers[$time]);
				$json = json($timers);
				$db->query("UPDATE channel SET switch={$mode},timers='{$json}' WHERE id={$channel['id']}");
			}
		}
		if ($switch) {
			return delete('switch');
		}
		
		$flavors = getUpdateFlavor($data);
		$channel['block'] = json($channel['block']);
		foreach ($channel['block'] as $block) {
			if (in_array($block, $flavors)) {
				if ($block == 'sticker' && (!isset($data['sticker']) || $data['sticker']['is_animated'])) continue;
				return delete($block);
			}
		}
		
		$text = ($data['text'] ?? $data['caption'] ?? '');
		$entities = ($data['entities'] ?? $data['caption_entities'] ?? []);
		$entity = array_column($entities, 'type');
		
		if (in_array('url', $channel['block']) && (in_array('url', $entity) || array_column($entities, 'url') != [])) {
			return delete('url');
		}

		else if (in_array('forwarded_from_other', $channel['block']) && (isset($data['forward_from_chat']) && $data['forward_from_chat']['id'] != $chat_id)) {
			return delete('forwarded_from_other');
		}

		else if (in_array('username', $channel['block']) && in_array('mention', $entity)) {
			return delete('username');
		}

		else if (in_array('bold', $channel['block']) && in_array('bold', $entity)) {
			return delete('bold');
		}

		else if (in_array('italic', $channel['block']) && in_array('italic', $entity)) {
			return delete('italic');
		}

		else if (in_array('monospace', $channel['block']) && (in_array('pre', $entity) || in_array('code', $entity))) {
			return delete('monospace');
		}

		else if (in_array('hashtag', $channel['block']) && in_array('hashtag', $entity)) {
			return delete('hashtag');
		}

		else if (in_array('email', $channel['block']) && in_array('email', $entity)) {
			return delete('email');
		}

		else if (in_array('forwarded_from_public', $channel['block']) && (isset($data['forward_from_chat']) && $data['forward_from_chat']['id'] != $chat_id && isset($data['forward_from_chat']['username']))) {
			return delete('forwarded_from_public');
		}
		
		else if (in_array('animated_sticker', $channel['block']) && (isset($data['sticker']) && $data['sticker']['is_animated'])) {
			return delete('animated_sticker');
		}

		else if (in_array('channel_username', $channel['block']) && in_array('mention', $entity)) {
			$usernames = getUsernames($text, $entities);
			foreach ($usernames as $username) {
				if (@$bot->Chat($username)) {
					return delete('channel_username');
				}
			}
		}
		if (in_array('other_channel_username', $channel['block']) && in_array('mention', $entity)) {
			$usernames = getUsernames($text, $entities);
			$channel_username = ($bot->Chat()['username'] ?? null);
			foreach ($usernames as $username) {
				if (@$bot->Chat($username) && $username != $channel_username) {
					return delete('other_channel_username');
				}
			}
		}
		if (in_array('keyboard_all', $channel['block']) && isset($data['reply_markup'])) {
			return delete('keyboard_all');
		}
		/*
		if (in_array('keyboard_only', $channel['block']) && isset($data['reply_markup'])) {
			/*
			#$bot->log(json_encode($data, 480));
			$keyb = ikb([
				[['a', 'a']],
			]);
			$args = ['chat_id' => $bot->ChatID(), 'message_id' => $bot->MessageID(), 'reply_markup' => $keyb];
			
			$jso = json_encode($args, 480);
			#$bot->log($jso);
			$try = $bot->editMessageReplyMarkup($args);
			#
			mp($bot->bot_token);
			global $mp;
			$keyb = [
				'_' => 'replyInlineMarkup',
				'rows' => [
					#['_' => 'keyboardButtonRow', 'buttons' => [KeyboardButton, KeyboardButton]]
				]
			];
			try {
				$a = $mp->messages->editMessage(['peer' => $chat_id, 'id' => $message_id, 'replyMarkup' => $keyb]);
				$bot->log(print_r($a, 1));
			} catch (Throwable $t) {
				$bot->indoc('['.__LINE__."] {$t}");
				#delete('keyboard_only');
			}
			return true;
		}
		*/
		
		$blocklist = json($channel['blocklist'] ?? '[]');
		$blocklist = array_values($blocklist);
		foreach ($blocklist as $word) {
			$regex = preg_quote($word, '/');
			$regex = '/(\b|^|\s)'.$regex.'(\b|$|\s)/i';
			//$bot->log($regex);
			if (preg_match($regex, $text)) {
				return delete('blocklist');
			}
		}
		
		$channel['patterns'] = json($channel['patterns']);
		foreach ($channel['patterns'] as $pattern) {
			if (@preg_match($pattern, $text)) {
				return delete('pattern');
			}
			foreach ($entities as $entit) {
				if (isset($entit['url'])) {
					if (@preg_match($pattern, $entit['url'])) {
						return delete('pattern');
					}
				}
			}
		}
		
		$twig = json($channel['twig']);
		if (isset($twig['template']) && $twig['enabled']) {
			$debug = json($twig['debug']);
			$old_tdb = $tdb = unserialize($twig['db']);
			$old_db = json_encode($tdb, 480);
			$text = $bot->Text() ?? $bot->Caption() ?? '';
			$entities = $bot->Entities() ?? [];
			if ($entities instanceof ArrayObj) {
				$entities = $entities->asArray();
			}
			$user_channels = getUsernames($text, $entities);
			if (!$user_channels) {
				$user_channels = [];
				$user_channel = false;
			} else {
				$user_channels = array_filter($user_channels, function($val) use ($bot) {
					return @$bot->getChat(['chat_id' => $val])->ok;
				});
				$user_channel = @(array_values($user_channels)[0]);
			}
			
			$mention = $bot->Chat($chat_id)['username'] ?? $chat_id;
			require_once 'twig.phar';
			$id = $mention.'.twig';
			$loader = new \Twig\Loader\ArrayLoader([
				$id => $twig['template'],
			]);

			$twigh = new \Twig\Environment($loader, ['autoescape' => false]);
			$twigh->addExtension(new \Twig\Extension\DebugExtension());
			$twigh->addExtension(getSandbox());
			#$template = $twigh->createTemplate($twig['template'], $chat_id.'.twig');
			
			## Vars
			$adms = $bot->getChatAdministrators(['chat_id' => $chat_id]);
			$adms = $adms->result->asArray();
			# only the "user" array of each adm
			$adms = array_column($adms, 'user');
			$adms = array_map(function($val) {
				return ['id' => $val['id'], 'signature' => $val['first_name'].(!is_null(@$val['last_name'])? ' '.$val['last_name'] : '')];
			}, $adms);
			# {id:signature,id:signature}
			$adms = array_column($adms, 'signature', 'id');
			# return only duplicated values
			$dup_adms = array_not_unique(array_map('serialize', $adms));
			$dup_adms = array_map('unserialize', $dup_adms);
			$is_duplicated = (@$data['author_signature'] && in_array(@$data['author_signature'], $dup_adms));
			$args = [
				'db' => &$tdb,
				'update' => $bot->data,
				'message' => $data,
				'user_channels' => $user_channels,
				'user_channel' => $user_channel,
				'admins' => array_keys($adms),
				'signatures' => $adms,
				'is_duplicated' => $is_duplicated,
			];
			
			$disable = function() use ($db, $chat_id, $twig) {
				$twig['enabled'] = false;
				return $db->prepare('UPDATE channel SET twig=? WHERE id=?')->execute([json($twig), $chat_id]);
			};
			$alert = function($msg) use ($twig, $bot, $chat_id) {
				$adm = $twig['adm'];
				$lang = setLanguage($adm, $lang);
				$keyb = ikb([
					[[$lang->back_twig, "twig $chat_id"]]
				]);
				$bot->send($msg, ['chat_id' => $adm, 'reply_markup' => $keyb]);
			};
			$inf = $bot->Chat($chat_id);
			$chmention = isset($inf['username'])? '@'.$inf['username'] : '<b>'.htmlspecialchars($inf['title']).'</b>';
			$adm = $twig['adm'];
			$lang = setLanguage($adm, $lang);
			try {
				$res = $twigh->render($id, $args);
			} catch (Error $e) {
				$err = htmlspecialchars($e->getMessage());
				$err = str_replace('&quot;'.$id.'&quot;', 'your template', $err);
				$msg = $lang->twig_error($chmention, $err);
				$alert($msg);
				return;
			} catch (\Twig\Error\RuntimeError $e) {
				$err = htmlspecialchars($e->getMessage());
				$err = str_replace('&quot;'.$id.'&quot;', 'your template', $err);
				$msg = $lang->twig_runtime_error($chmention, $err);
				$alert($msg);
				return $disable();
			} catch (\Twig\Sandbox\SecurityError $e) {
				$err = htmlspecialchars($e->getMessage());
				$err = str_replace('&quot;'.$id.'&quot;', 'your template', $err);
				$msg = $lang->twig_sandbox_error($chmention, $err);
				$alert($msg);
				return $disable();
			} catch (\Twig\Error\SyntaxError $e) {
				$err = htmlspecialchars($e->getMessage());
				$err = str_replace('&quot;'.$id.'&quot;', 'your template', $err);
				$msg = $lang->twig_syntax_error($chmention, $err);
				$alert($msg);
				return $disable();
				// $template contains one or more syntax errors
			}
			
			$new_db = json_encode($tdb, 480);
			if (trim($res)) {
				$bot->delete();
			}
			if ($debug) {
				$twigh = new \Twig\Environment($loader, ['debug' => true, 'autoescape' => false]);
				$twigh->addExtension(new \Twig\Extension\DebugExtension());
				$twigh->addExtension(getSandbox());
				#$template = $twigh->createTemplate($twig['template'], $chat_id.'.twig');
				
				unset($args['db']);
				$args['db'] = $old_tdb;
				$res = $twigh->render($id, $args);
				foreach ($debug as $adm_id) {
					if ($bot->is_admin($adm_id, $chat_id)) {
						$lang = setLanguage($adm_id, $lang);
						$str = "<b>🌱 TWIG Debugging</b>
• <b>{$lang->template}:</b>
<pre>".htmlspecialchars($twig['template'])."</pre>
• <b>{$lang->twig_return}:</b> ".dump(htmlspecialchars(html_entity_decode($res)));
						if ($old_db != $new_db) {
							$str .= "\n• <b>{$lang->old_db}:</b> <pre>{$old_db}</pre>
• <b>{$lang->new_db}</b> <pre>{$new_db}</pre>";
						} else {
							$str .= "\n• <b>{$lang->database}:</b> <pre>{$old_db}</pre>\n• {$lang->twig_db_unchanged}";
						}
						$dump = json_encode($bot->data, 480);
						$str .= "\n• <b>Update:</b>\n<pre>{$dump}</pre>";
						$keyb = ikb([
							[[$lang->back_twig, "twig $chat_id"]]
						]);
						if (!(@$bot->send($str, ['chat_id' => $adm_id, 'reply_markup' => $keyb])->ok)) {
							$bot->indoc(html_entity_decode(strip_tags($str)), 'twig.debug.txt', ['chat_id' => $adm_id]);
						}
					}
				}
			}
			$twig['db'] = serialize($tdb);
			
			$add = $db->prepare('UPDATE channel SET twig=? WHERE id=?')->execute([json($twig), $channel['id']]);
		}
	}
	
	else if ($type == 'callback_query') {
		$call = $data['data'];
		$call_id = $data['id'];
		if (@$_GET['beta']) {
			@$bot->answerCallbackQuery(['callback_query_id' => $call_id, 'text' => $call]);
		}
		
		$user_id = $data['from']['id'];
		$lang = setLanguage($user_id, $lang);
		setTimezone($user_id);
		
		# Protection
		if (preg_match('#-100\d+#', $call, $match)) {
			if (!$bot->is_admin($user_id, $match[0])) {
				$error = $lang->callback_not_admin;
				return $bot->answerCallbackQuery(['callback_query_id' => $call_id, 'text' => $error, 'show_alert' => 1]);
			}
		}
		
		$chat_id = $data['message']['chat']['id'];
		$message_id = $data['message']['message_id'];
		
		/*
		if ((time() - $data['message']['date']) > 86400) {
			return $bot->answerCallbackQuery(['callback_query_id' => $call_id, 'text' => $lang->callback_old_message]);
		}
		*/
		
		call_checks:
		if ($call == 'start_menu') {
			$keyb = ikb([
				[[$lang->help, 'help'], [$lang->about, 'about']],
				[[$lang->my_channels, 'channels']],
			]);
			$bot->edit($lang->start_text, ['reply_markup' => $keyb]);
		}
		
		else if ($call == 'help') {
			$keyb = ikb([
				[[$lang->back, 'start_menu']]
			]);
			$bot->edit(sprintf($lang->help_text, $lang->faq, $lang->group_link), ['reply_markup' => $keyb, 'disable_web_page_preview' => false]);
		}
		
		else if ($call == 'about') {
			$keyb = ikb([
				[[$lang->back, 'start_menu']]
			]);
			$gmt = getGMT();
			$bot->edit(sprintf($lang->about_text, date('H\hi d.m.y', filemtime(__FILE__)), $gmt), ['reply_markup' => $keyb]);
		}
		
		else if ($call == 'setup') {
			$info = $db->query("SELECT language, timezone FROM user WHERE id={$user_id}")->fetch();
			
			$keyb = ikb([
				[[sprintf($lang->bs_timezone, $info['timezone']), 'set_timezone']],
				[[sprintf($lang->bs_language, $lang->NAME), 'set_language']],
			]);
			$bot->edit($lang->basic_settings_info, ['reply_markup' => $keyb]);
		} 
		
		else if ($call == 'set_language') {
			setup_language:
			$lang->NAME = "🔹{$lang->NAME}";
			$keyb = [[]];
			$line = 0;
			foreach ($lang->data as $language_name => $strings) {
				if (count($keyb[$line]) != 2) {
					$keyb[$line][] = [$strings->NAME, "set_language {$language_name}"];
				} else {
					$line++;
					$keyb[$line] = [[$strings->NAME, "set_language {$language_name}"]];
				}
			}
			$keyb[] = [[$lang->back, "setup"]];
			$keyb = ikb($keyb);
			@@$bot->edit($lang->choose_language, ['reply_markup' => $keyb]);
		}
		
		else if (preg_match('#^set_language (?<lang>.+)#', $call, $match)) {
			$language = $match['lang'];
			$db->query("UPDATE user SET language='{$language}' WHERE id={$user_id}");
			$lang->language = $language;
			$bot->answerCallbackQuery(['callback_query_id' => $call_id, 'text' => $lang->language_updated]);
			goto setup_language;
		}
		
		else if ($call == 'set_timezone') {
			$bot->deleteMessage(['chat_id' => $chat_id, 'message_id' => $message_id]);
			$timezones = json(file_get_contents('functions/timezones.json'));
			$regions = array_keys($timezones);
			
			$keyb = [[]];
			$line = 0;
			foreach ($regions as $region) {
				if (count($keyb[$line]) != 3) {
					$keyb[$line][] = $region;
				} else {
					$line++;
					$keyb[$line] = [$region];
				}
			}
			$keyb = kb($keyb, 0, ['one_time_keyboard' => true, 'resize_keyboard' => true]);
			$bot->send($lang->choose_timezone_region, ['reply_markup' => $keyb]);
			$db->query("UPDATE user SET waiting_for='region', waiting_back='setup' WHERE id={$user_id}");
		}
		
		else if ($call == 'channels') {
			list_channels:
			$bot->action();
			$db->beginTransaction();
			$result = $db->query("SELECT id, timers, switch, fixed_timers, is_channel, info FROM channel WHERE adm LIKE '%{$user_id}%'");
			
			$keyb = [];
			while ($channel = $result->fetch()) {
				if (!$bot->is_admin($user_id, $channel['id'])) continue;
				$timers = json($channel['timers']);
				$fixed = json($channel['fixed_timers']);
				$switch = &$channel['switch'];
				
				foreach ($timers as $time => $mode) {
					$time = (int)$time;
					if (time() >= $time) {
						$switch = $mode = (int)$mode;
						unset($timers[$time]);
						$json = json($timers);
						$db->query("UPDATE channel SET switch={$mode},timers='{$json}' WHERE id={$channel['id']}");
					}
				}
				foreach ($fixed as &$info) {
					extract($info); # time, timezone, mode, last_executed
					@date_default_timezone_set($timezone);
					$last = $last_executed;
					$bool = ask_exec_timer($info);
					if ($bool) {
						$info['last_executed'] = time();
						$switch = $mode = (int)$mode;
						$db->query("UPDATE channel SET switch={$mode} WHERE id={$channel['id']}");
					}
				}
				$fix = json($fixed);
				$db->prepare("UPDATE channel SET fixed_timers=? WHERE id={$channel['id']}")->execute([$fix]);
				
				$info = json($channel['info']);
				if (!$info || !isset($info['updated_date']) || time()-$info['updated_date'] >=  86400) {
					$info = @$bot->Chat($channel['id']);
					if (!$info) continue;
					$info = ['updated_date' => time(), 'title' => $info['title'], 'username' => @$info['username'], 'adms' => 0, 'members' => 0];
					$json = $db->quote(json($info));
					$db->query("UPDATE channel SET info=$json WHERE id={$channel['id']}");
				}
				$keyb[] = [[($info['username']? '@'.$info['username'] : $info['title']), "open {$channel['id']}"], [($channel['switch'] ? '🔥' : '…'), "switch {$channel['id']}"]];
			}
			if ($keyb == [] || $bot->update_type == 'message') {
				$keyb[] = [[$lang->add_channel, 'add_channel']];
			}
			if ($bot->update_type == 'callback_query') $keyb[] = [[$lang->back, 'start_menu']];
			$keyb = ikb($keyb);
			@$bot->act($lang->control_menu, ['reply_markup' => $keyb]);
			$db->commit();
		}
		
		else if ($call == 'add_channel') {
			$keyb = ikb([
				[[$lang->cancel, 'cancel']],
			]);
			$bot->edit($lang->connect, ['reply_markup' => $keyb]);
			#$bot->edit($lang->connect);
			$db->query("UPDATE user SET waiting_for='channel', waiting_back='channels' WHERE id={$user_id}");
		}

		else if (preg_match('#^switch (?<id>.+)#', $call, $channel)) {
			$time = time();
			$db->query("UPDATE channel SET switch=(switch <> 1), last_editor={$user_id}, last_edit_time=".time()." WHERE id={$channel['id']}");
			goto list_channels;
		}

		else if (preg_match('#^open (?<id>.+)#', $call, $channel)) {
			overview:
			$db->beginTransaction();
			$channel = $db->query("SELECT * FROM channel WHERE id={$channel['id']}")->fetch();
			if (!$channel) {
				$keyb = [
					[[$lang->add_channel, 'add_channel']]
				];
				return $bot->send($lang->no_more_channel, ['reply_markup' => $keyb]);
			}
			$channel_info = ($db_info = json($channel['info'])) ?: $bot->Chat($channel['id']);
			if (!isset($channel_info['title'])) {
				$info = @$bot->Chat($channel['id']);
				$channel_info = $info = ['updated_date' => time(), 'title' => $info['title'], 'username' => @$info['username'], 'adms' => 0, 'members' => 0];
				$json = $db->quote(json($info));
				$db->query("UPDATE channel SET info=$json WHERE id={$channel['id']}");
			}
			$timers = json($channel['timers']);
			$ftimers = json($channel['fixed_timers']);
			$timers = array_replace($ftimers, $timers);
			
			ksort($timers);
			$now = date('Hi');
			$timers_str = [];
			
			$past = [];
			
			foreach ($timers as $time => $info) {
				if (!is_array($info)) {
					$info = ['mode' => $info];
					$info['time'] = $time;
				}
				$time = $info['time'];
				$mode = $info['mode'];
				$hm = date('Hi', $time);
				
				if (date('Hi') >= $hm) {
					$past[$time] = $info;
					continue;
				}
				if (isset($info['timezone'])) {
					$timers_str[$hm] = "\n  • ".($mode? '🔥' : '…')." <i>".date('H\hi', $time)." ({$lang->runs_daily})</i>";
				} else {
					$timers_str[$hm] = "\n  • ".($mode? '🔥' : '…')." <i>".date('H\hi d.m.Y', $time)." ({$lang->runs_once})</i>";
				}
			}
			foreach ($past as $time => $info) {
				$mode = $info['mode'];
				$hm = date('Hi', $time);
				if (isset($info['timezone'])) {
					$timers_str[$hm] = "\n  • ".($mode? '🔥' : '…')." <i>".date('H\hi', $time)." ({$lang->runs_daily})</i>";
				} else {
					$timers_str[$hm] = "\n  • ".($mode? '🔥' : '…')." <i>".date('H\hi d.m.Y', $time)." ({$lang->runs_once})</i>";
				}
			}
			
			#ksort($timers_str);
			$timers_str = join('', $timers_str);
			
			/*if ($timers_str == '') {
				$timers_str = "\n".$lang->no_timers;
			}*/
			
			if (!(@$db_info['members'])) {
				$members_quant = $bot->getChatMembersCount(['chat_id' => $channel['id']])->result;
				$channel_mention = isset($channel_info['username'])? '@'.$channel_info['username'] : $channel['id'];
				$administrators_quant = count(getAdmins($channel['id'], $lang));
				$db_info['members'] = $members_quant;
				$db_info['adms'] = $administrators_quant;
				$db_info_json = $db->quote(json($db_info));
				$db->query("UPDATE channel SET info={$db_info_json} WHERE id={$channel['id']}");
			}
			
			$members_quant = $db_info['members'];
			$administrators_quant = $db_info['adms'];
			
			$switch_str = $channel['switch']? $lang->overview_switch_on : '';
			$str = "💻 ".(isset($channel_info['username'])? "@{$channel_info['username']}" : "<b>{$channel_info['title']}</b>")." - {$lang->overview}
🎫 <pre>{$channel['id']}</pre>
👥 {$members_quant} {$lang->members}".($administrators_quant? " | 👮‍♀ {$administrators_quant} {$lang->admins}" : '')."

";
			if ($timers_str) {
				$str .= "<b>{$lang->timers}:</b>{$timers_str}\n\n";
			}
			$block = json($channel['block']);
			$contents = $types = [];
			foreach ($block as $item) {
				if (isset($lang->{"types_$item"})) {
					$types[] = $lang->{"types_$item"};
				} else if (isset($lang->{"contents_$item"})) {
					$contents[] = $lang->{"contents_$item"};
				}
			}
			
			if ($contents) {
				$str.= "<b>{$lang->op_content}:</b> ".join(', ', $contents)."\n\n";
			}
			if ($types) {
				$str.= "<b>{$lang->op_types}:</b> ".join(', ', $types)."\n\n";
			}
			if ($channel['twig'] && $channel['twig'] != '[]' && json($channel['twig'])['enabled']) {
				$str .= "<b>{$lang->using_twig}</b>\n\n";
			}
			if (json($channel['patterns'])) {
				$str .= "<b>{$lang->using_regex}</b>\n\n";
			}
			if (json($channel['blocklist'])) {
				$str .= "<b>{$lang->using_blocklist}</b>\n\n";
			}
			if ($whitelist = json($channel['whitelist'])) {
				$str.= "<b>{$lang->op_whitelisted}:</b> ".join(', ', array_map(function ($val) use ($bot) { return $bot->mention($val); }, $whitelist))."\n\n";
			}

			$str .= "{$switch_str}";

			/*if ($channel['last_editor']) {
				$str .= "\n\n". sprintf($lang->last_modify_by, $bot->mention($channel['last_editor']), date('H\hi d.m.y', $channel['last_edit_time']));
			}*/
			
			$keyb = ikb([
				[[($channel['switch']? '🔥' : '…'), "overview_switch {$channel['id']}"]],
				[[$lang->deleting_rules, "deleting_rules {$channel['id']}"], [$lang->whitelist, "whitelist {$channel['id']}"]],
				[[$lang->import_settings, "import_settings {$channel['id']}"], [$lang->export_settings, "export_settings {$channel['id']}"]],
				[[$lang->settings, "settings {$channel['id']}"]],
				/*[[$lang->info, "info {$channel['id']}"]],*/
				[[$lang->back_to_channels, "channels"]],
			]);
			@$bot->edit($str, ['reply_markup' => $keyb]);
			$db->commit();
		}
		
		else if (preg_match('#^overview_switch (?<id>.+)#', $call, $channel)) {
			$time = time();
			$db->query("UPDATE channel SET switch=(switch <> 1), last_editor={$user_id}, last_edit_time=".time()." WHERE id={$channel['id']}");
			goto overview;
		}
		
		else if (preg_match('#^deleting_rules (?<id>.+)#', $call, $channel)) {
			$keyb = ikb([
				[[$lang->settings_types, "types {$channel['id']}"], [$lang->settings_contents, "content {$channel['id']}"]],
				[[$lang->settings_patterns.' (RegEx)', "patterns {$channel['id']} 0 0"], [$lang->settings_twig, "twig {$channel['id']}"]],
				[[$lang->settings_blocklist, "blocklist {$channel['id']} 0 0"]],	
				[[$lang->timers, "timers {$channel['id']}"]],
				[[$lang->back, "open {$channel['id']}"]],
			]);
			$bot->edit($lang->deleting_rules_info, ['reply_markup' => $keyb]);
		}
		
		else if (preg_match('#^whitelist (?<id>.+)#', $call, $channel)) {
			$whitelist = json($db->querySingle("SELECT whitelist FROM channel WHERE id={$channel['id']}"));
			fetch_whitelist:
			$using_mp = true;
			$adms = getAdmins($channel['id'], $lang);
			if (!$adms) {
				$using_mp = false;
				$adms = $bot->getChatAdministrators(['chat_id' => $channel['id']]);
				$adms = $adms->result->asArray();
				# only the "user" array of each adm
				$adms = array_column($adms, 'user');
				# indexed by id
				$adms = array_column($adms, null, 'id');
			}
			
			$lines = [[]];
			$line = 0;
			$me = $bot->getMe()->id;
			foreach ($adms as $id => $user) {
				if ($id == $me) continue;
				if (!isset($lines[$line]) || count($lines[$line]) >= 2) $line++;
				if (!isset($user['first_name'])) continue;
				$text = isset($user['username'])? '@'.$user['username'] : $user['first_name'];
				if (in_array($id, $whitelist)) $text .= " ✅";
				$lines[$line][] = [$text, "whitelist_switch {$id} {$channel['id']}"];
			}
			$lines[] = [[$lang->back, "open {$channel['id']}"]];
			$keyb = ikb($lines);
			$bot->edit($lang->whitelist_info.($using_mp? '' : "\n\n❕ Not showing bots."), ['reply_markup' => $keyb]);
		}
		
		else if (preg_match('#^whitelist_switch (?<user>.+) (?<id>.+)#', $call, $match)) {
			$q = $db->query("SELECT whitelist, adm FROM channel WHERE id={$match['id']}")->fetch();
			extract($q);
			$whitelist = json($whitelist);
			$adm = json($adm);
			
			if (!$adm[$user_id]['sudo']) {
				return @$bot->answer_callback($lang->not_sudo_alert, ['show_alert' => true]);
			}
			
			#$keyb = new KeyboardObject($bot->ReplyMarkup());
			#$text = &$keyb->getFromData($call)->text;
			
			$user = $match['user'];
			$key = array_search($user, $whitelist);
			if ($key !== false) { #if user is in whitelist array
				unset($whitelist[$key]);
				#$text = str_replace(' ✅', '', $text);
			} else {
				$whitelist[] = $user;
				#$text .= ' ✅';
			}
			
			#$bot->editKeyboard($keyb->save());
			
			$js_wl = $db->quote(json($whitelist)); 
			$db->query("UPDATE channel SET whitelist=$js_wl WHERE id={$match['id']}");
			$channel = $match;
			goto fetch_whitelist;
		}
		
		else if (preg_match('#^settings (?<id>.+)#', $call, $channel)) {
			$keyb = ikb([
				[[$lang->settings_administrators, "admins {$channel['id']}"]],
				[[$lang->reset_channel, "reset_channel {$channel['id']}"], [$lang->remove_channel, "remove_channel {$channel['id']}"]],
				[[$lang->back, "open {$channel['id']}"]],
			]);
			$bot->edit($lang->settings_info, ['reply_markup' => $keyb]);
		}

		else if (preg_match('#^types (?<id>.+)#', $call, $channel)) {
			$channel = $db->query("SELECT id, block FROM channel WHERE id={$channel['id']}")->fetch();
			$block = json($channel['block']);
			types:
			$format = [
				[['forwarded_from_public']],
				[['forwarded_from_other']],
				[['forwarded_post'], ['forwarded_message']],
				[['keyboard_all']],
				[['poll'],['service_alert']],
				[['video_note'],['contact']],
				[['animated_sticker'],['sticker']],
				[['location'],['document']],
				[['voice'], ['audio']],
				[['video'], ['photo']],
				[['edited_channel_post']],
			];
			foreach ($format as &$line) {
				foreach ($line as &$btn) {
					$type = $btn[0];
					$key = "types_{$type}";
					$name = $lang->$key;
					if (in_array($type, $block)) $name .= " 🔥";
					if ($type == 'keyboard' && (in_array('keyboard_all', $block) || in_array('keyboard_only', $block))) {
						$name .= " 🔥";
					}
					$btn = [$name, "sw_type {$type} {$channel['id']}"];
				}
			}
			$format[] = [[$lang->back, "deleting_rules {$channel['id']}"]];
			$keyb = ikb($format);
			
			@$bot->edit($lang->settings_types_info, ['reply_markup' => $keyb]);
		}
		
		/*
		else if (preg_match('#^sw_type (?<type>keyboard) (?<id>.+)#', $call, $matches)) {
			$block = $db->querySingle("SELECT block FROM channel WHERE id={$matches['id']}");
			$block = json($block);
			keyboard:
			$konly = in_array('keyboard_only', $block)? ' 🔥' : '';
			$kall = in_array('keyboard_all', $block)? ' 🔥' : '';
			$keyb = ikb([
				[[$lang->types_keyboard_only.$konly, "sw_type keyboard_only {$matches['id']}"]],
				[[$lang->types_keyboard_all.$kall, "sw_type keyboard_all {$matches['id']}"]],
				[[$lang->back, "types {$matches['id']}"]],
			]);
			@$bot->edit($lang->types_keyboard_info, ['reply_markup' => $keyb]);
		}
		
		
		else if (preg_match('#^sw_type (?<type>keyboard_only) (?<id>.+)#', $call, $matches)) {
			$block = $db->querySingle("SELECT block FROM channel WHERE id={$matches['id']}");
			$block = json($block);
			#$bot->log($block);
			if (in_array($matches['type'], $block)) {
				$pos = array_search($matches['type'], $block);
				if ($pos !== false) unset($block[$pos]);
			} else {
				$pos = array_search('keyboard_all', $block);
				if ($pos !== false) unset($block[$pos]);
				$block[] = 'keyboard_only';
			}
			$db->query("UPDATE channel SET block='".json($block)."', last_editor={$user_id}, last_edit_time=".time()." WHERE id={$matches['id']}");
			goto keyboard;
		}
		
		else if (preg_match('#^sw_type (?<type>keyboard_all) (?<id>.+)#', $call, $matches)) {
			$block = $db->querySingle("SELECT block FROM channel WHERE id={$matches['id']}");
			$block = json($block);
			if (in_array($matches['type'], $block)) {
				$pos = array_search($matches['type'], $block);
				if ($pos !== false) unset($block[$pos]);
			} else {
				$pos = array_search('keyboard_only', $block);
				if ($pos !== false) unset($block[$pos]);
				$block[] = 'keyboard_all';
			}
			$db->query("UPDATE channel SET block='".json($block)."', last_editor={$user_id}, last_edit_time=".time()." WHERE id={$matches['id']}");
			goto keyboard;
		}
		*/
		

		else if (preg_match('#^sw_type (?<type>.+) (?<id>.+)#', $call, $matches)) {
			$block = $db->querySingle("SELECT block FROM channel WHERE id={$matches['id']}");
			$block = json($block);
			if (in_array($matches['type'], $block)) {
				$pos = array_search($matches['type'], $block);
				if ($pos !== false) unset($block[$pos]);
			} else {
				$block[] = $matches['type'];
			}
			$js_bl = $db->quote(json($block));
			$db->query("UPDATE channel SET block=$js_bl, last_editor={$user_id}, last_edit_time=".time()." WHERE id={$matches['id']}");
			$channel = $matches;
			goto types;
		}
		
		else if (preg_match('#^content (?<id>.+)#', $call, $channel)) {
			$content = ['url','monospace','bold','italic','username','channel_username','other_channel_username','hashtag','email'];
			$channel = $db->query("SELECT id, block FROM channel WHERE id={$channel['id']}")->fetch();
			$block = json($channel['block']);
			$type_key= 0;
			
			$format = [
				[[]],
				[[],[],[]],
				[[]],
				[[]],
				[[]],
				[[],[]]
			];
			foreach ($format as &$line) {
				foreach ($line as &$btn) {
					$type = $content[$type_key];
					$type_key++;
					$key = "contents_{$type}";
					$name = $lang->$key;
					if (in_array($type, $block)) $name .= " 🔥";
					$btn = [$name, "sw_content {$type} {$channel['id']}"];
				}
			}
			$format[] = [[$lang->back, "deleting_rules {$channel['id']}"]];
			$keyb = ikb($format);
			
			$bot->edit($lang->settings_contents_info, ['reply_markup' => $keyb]);
		}

		else if (preg_match('#^sw_content (?<type>.+) (?<id>.+)#', $call, $matches)) {
			$block = $db->query("SELECT block FROM channel WHERE id={$matches['id']}")->fetch()['block'];
			$block = json($block);
			if (in_array($matches['type'], $block)) {
				$pos = array_search($matches['type'], $block);
				if ($pos !== false) unset($block[$pos]);
			} else {
				$block[] = $matches['type'];
			}
			$db->query("UPDATE channel SET block='".json($block)."', last_editor={$user_id}, last_edit_time=".time()." WHERE id={$matches['id']}");
			$content = ['url','monospace','bold','italic','username','channel_username','other_channel_username','hashtag','email'];
			$type_key = 0;
			
			$format = [
				[[]],
				[[],[],[]],
				[[]],
				[[]],
				[[]],
				[[],[]]
			];
			foreach ($format as &$line) {
				foreach ($line as &$btn) {
					$type = $content[$type_key];
					$type_key++;
					$key = "contents_{$type}";
					$name = $lang->$key;
					if (in_array($type, $block)) $name .= " 🔥";
					$btn = [$name, "sw_content {$type} {$matches['id']}"];
				}
			}
			$format[] = [[$lang->back, "deleting_rules {$matches['id']}"]];
			$keyb = ikb($format);
			
			$bot->edit($lang->settings_contents_info, ['reply_markup' => $keyb]);
		}

		else if (preg_match('#^patterns (?<id>.+?) (?<offset>.+) (?<old_offset>.+)#', $call, $channel)) {
			$new_offset = $offset = $channel['offset'] ?? 0;
			$old_offset = $channel['old_offset'];
			if ($old_offset > $offset) {
				$diff = $old_offset - $offset;
				$old_offset = $offset-$diff;
			}
			$patterns = $db->querySingle("SELECT patterns FROM channel WHERE id={$channel['id']}");
			$patterns = json($patterns);
			
			$patterns = array_values($patterns);
			$total = count($patterns);
			$patterns = array_slice($patterns, $offset, null, true);
			
			$reply = $lang->settings_patterns_info."\n\n";
			if ($patterns == []) {
				$reply .= $lang->no_patterns;
			} else {
				$reply .= $lang->registered_patterns;
			}
			$keyb = [[]];
			$line = 0;
			foreach ($patterns as $key => $pattern) {
				$pattern = htmlspecialchars($pattern);
				if (mb_strlen($reply . "\n  <b>". ($key+1) ."</b>. <pre>{$pattern}</pre>") < 4096) {
					$reply .= "\n  <b>". ($key+1) ."</b>. <pre>{$pattern}</pre>";
					$new_offset = $key+1;
				} else break;
				if (count($keyb[$line]) != 2) {
					$keyb[$line][] = [sprintf($lang->delete_pattern, $key+1), "delete_pattern $key {$channel['id']} {$offset} {$old_offset}"];
				} else {
					$line++;
					$keyb[$line] = [[sprintf($lang->delete_pattern, $key+1), "delete_pattern $key {$channel['id']} {$offset} {$old_offset}"]];
				}
			}
			$line = [];
			if ($offset > 0) $line[] = ['◀', "patterns {$channel['id']} {$old_offset} {$offset}"];
			if ($total > $new_offset) $line[] = ['▶', "patterns {$channel['id']} {$new_offset} {$offset}"];
			$keyb[] = $line;
			$keyb[] = [[$lang->add_pattern, "add_pattern {$channel['id']}"]];
			$keyb[] = [[$lang->back, "deleting_rules {$channel['id']}"]];
			$keyb = ikb($keyb);
			$bot->edit($reply, ['reply_markup' => $keyb]);
		}

		else if (preg_match('#^delete_pattern (?<key>.+) (?<id>.+?) (?<offset>.+) (?<old_offset>.+)#', $call, $matches)) {
			$new_offset = $offset = $matches['offset'] ?? 0;
			$old_offset = $matches['old_offset'];
			if ($old_offset > $offset) {
				$diff = $old_offset - $offset;
				$old_offset = $offset-$diff;
			}
			$patterns = $db->querySingle("SELECT patterns FROM channel WHERE id={$matches['id']}");
			$patterns = json($patterns);
			$patterns = array_values($patterns);
			unset($patterns[$matches['key']]);
			$patterns = array_values($patterns);
			
			$patterns_encoded = json($patterns);
			$stmt = $db->prepare("UPDATE channel SET patterns=?, last_editor={$user_id}, last_edit_time=".time()." WHERE id={$matches['id']}");
			$stmt->bindValue(1, $patterns_encoded);
			$stmt->execute();
			
			$total = count($patterns);
			$patterns = array_slice($patterns, $offset, null, true);
			
			$reply = $lang->settings_patterns_info."\n\n";
			if ($patterns == []) {
				$reply .= $lang->no_patterns;
			} else {
				$reply .= $lang->registered_patterns;
			}
			$keyb = [[]];
			$line = 0;
			foreach ($patterns as $key => $pattern) {
				$pattern = htmlspecialchars($pattern);
				if (mb_strlen($reply . "\n  <b>". ($key+1) ."</b>. <pre>{$pattern}</pre>") < 4096) {
					$reply .= "\n  <b>". ($key+1) ."</b>. <pre>{$pattern}</pre>";
					$new_offset = $key+1;
				} else break;
				if (count($keyb[$line]) != 2) {
					$keyb[$line][] = [sprintf($lang->delete_pattern, $key+1), "delete_pattern $key {$matches['id']} {$offset} {$old_offset}"];
				} else {
					$line++;
					$keyb[$line] = [[sprintf($lang->delete_pattern, $key+1), "delete_pattern $key {$matches['id']} {$offset} {$old_offset}"]];
				}
			}
			$line = [];
			if ($offset > 0) $line[] = ['◀', "patterns {$matches['id']} {$old_offset} {$offset}"];
			if ($total > $new_offset) $line[] = ['▶', "patterns {$matches['id']} {$new_offset} {$offset}"];
			$keyb[] = $line;
			$keyb[] = [[$lang->add_pattern, "add_pattern {$matches['id']}"]];
			$keyb[] = [[$lang->back, "deleting_rules {$matches['id']}"]];
			$keyb = ikb($keyb);
			$bot->edit($reply, ['reply_markup' => $keyb]);
		}

		else if (preg_match('#^add_pattern (?<id>.+)#', $call, $channel)) {
			$reply = $lang->add_pattern_info;
			$keyb = ikb([
				[[$lang->cancel, 'cancel']],
			]);
			$bot->edit($reply, ['reply_markup' => $keyb]);
			$db->query("UPDATE user SET waiting_for='pattern', waiting_param='{$channel['id']}', waiting_back='patterns {$channel['id']} 0 0' WHERE id={$user_id}");
		}
		
		else if (preg_match("#^twig (?<id>.+)#", $call, $channel)) {
			$twig = $db->queryJson("SELECT twig FROM channel WHERE id={$channel['id']}");
			list_twig:
			
			if (!$twig || !isset($twig['template'])) {
				$str = $lang->twig_info($lang->twig_no_templates);
				$keyb = ikb([
					[[$lang->twig_add_template, "twig_add_template {$channel['id']}"]],
					[[$lang->back, "deleting_rules {$channel['id']}"]]
				]);
				$bot->edit($str, ['reply_markup' => $keyb]);
			} else {
				$str = $lang->twig_info($lang->twig_templates);
				if (mb_strlen($str . "\n• <pre>".htmlspecialchars($twig['template'])."</pre>") > 4096) {
					$indoc = $twig['template'];
					$str .= "\n-- {$lang->too_big_alert} --";
				} else {
					$str .= "\n• <pre>".htmlspecialchars($twig['template'])."</pre>";
				}
				$debug = in_array($user_id, json($twig['debug']));
				$enabled = $twig['enabled'];
				$keyb = ikb([
					[[($enabled? $lang->enabled : $lang->disabled), "twig_switch {$channel['id']}"]],
					[[$lang->update_twig, "twig_add_template {$channel['id']}"], [$lang->delete_twig, "del_twig {$channel['id']}"]],
					[[$lang->twig_show_db, "twig_show_db {$channel['id']}"], [$lang->twig_reset_db, "twig_reset_db {$channel['id']}"]],
					[[$lang->twig_edit, "twig_edit {$channel['id']}"]],
					[[$lang->twig_debug.($debug? ' ✅' : ' ✖'), "twig_debug {$channel['id']}"]],
					[[$lang->back, "deleting_rules {$channel['id']}"]],
				]);
				$bot->edit($str, ['reply_markup' => $keyb]);
				if (isset($indoc) && $indoc) {
					$bot->indoc($indoc, 'twig.template.txt');
				}
			}
		}
		
		else if (preg_match('#^twig_switch (?<id>.+)#', $call, $channel)) {
			$twig = $db->queryJson("SELECT twig FROM channel WHERE id={$channel['id']}");
			$twig['enabled'] = !$twig['enabled'];
			$db->prepare("UPDATE channel SET twig=?, last_editor={$user_id}, last_edit_time=".time()." WHERE id={$channel['id']}")->execute([json($twig)]);
			goto list_twig;
		}
		
		else if (preg_match('#^twig_add_template (?<id>.+)#', $call, $channel)) {
			$reply = $lang->twig_waiting;
			$keyb = ikb([
				[[$lang->cancel, 'cancel']],
			]);
			$bot->edit($reply, ['reply_markup' => $keyb]);
			$db->query("UPDATE user SET waiting_for='twig', waiting_param='{$channel['id']}', waiting_back='twig {$channel['id']}' WHERE id={$user_id}");
		}
		
		else if (preg_match('#^del_twig (?<id>.+)#', $call, $channel)) {
			$twig = $db->queryJson("SELECT twig FROM channel WHERE id={$channel['id']}");
			unset($twig['template']);
			$db->prepare("UPDATE channel SET twig=?, last_editor={$user_id}, last_edit_time=".time()." WHERE id={$channel['id']}")->execute([json($twig)]);
			goto list_twig;
		}
		
		else if (preg_match('#^twig_show_db (?<id>.+)#', $call, $channel)) {
			$twig = $db->queryJson("SELECT twig FROM channel WHERE id={$channel['id']}");
			$new_db = unserialize($twig['db']);
			$param = $channel['id'];
			$ikb = ikb([
				[[$lang->back, "twig $param"]]
			]);
			if (strlen($new_db) <= 4000) {
				$new_db = htmlspecialchars(json_encode($new_db, 480));
				$bot->edit("<code>$new_db</code>", ['reply_markup' => $ikb]);
			} else {
				$bot->indoc($new_db, 'twig.db.txt');
			}
		}
		
		else if (preg_match('#^twig_reset_db (?<id>.+)#', $call, $channel)) {
			$twig = $db->queryJson("SELECT twig FROM channel WHERE id={$channel['id']}");
			$twig['db'] = serialize(null);
			$db->prepare("UPDATE channel SET twig=?, last_editor={$user_id}, last_edit_time=".time()." WHERE id={$channel['id']}")->execute([json($twig)]);
			$bot->answer_callback($lang->twig_db_reseted, ['show_alert' => true]);
		}
		
		else if (preg_match('#^twig_debug (?<id>.+)#', $call, $channel)) {
			$twig = $db->queryJson("SELECT twig FROM channel WHERE id={$channel['id']}");
			$debug = json($twig['debug']);
			$pos = array_search($user_id, $debug);
			if ($pos !== false) {
				unset($debug[$pos]);
			} else {
				$debug[] = $user_id;
			}
			$twig['debug'] = json($debug);
			
			$db->prepare("UPDATE channel SET twig=?, last_editor={$user_id}, last_edit_time=".time()." WHERE id={$channel['id']}")->execute([json($twig)]);
			goto list_twig;
		}
		
		else if (preg_match('#^twig_edit (?<id>.+)#', $call, $channel)) {
			$reply = $lang->twig_edit_waiting;
			$keyb = ikb([
				[[$lang->cancel, 'cancel']],
			]);
			$bot->edit($reply, ['reply_markup' => $keyb]);
			$db->query("UPDATE user SET waiting_for='twig_edit', waiting_param='{$channel['id']}', waiting_back='twig {$channel['id']}' WHERE id={$user_id}");
		}
		
		else if (preg_match('#^blocklist (?<id>.+?) (?<offset>.+) (?<old_offset>.+)#', $call, $channel)) {
			$new_offset = $offset = $channel['offset'] ?? 0;
			$old_offset = $channel['old_offset'];
			if ($old_offset > $offset) {
				$diff = $old_offset - $offset;
				$old_offset = $offset-$diff;
			}
			$channel = $db->query("SELECT id, blocklist FROM channel WHERE id={$channel['id']}")->fetch();
			$blocklist = json($channel['blocklist'] ?? '[]');
			
			$blocklist = array_values($blocklist);
			$total = count($blocklist);
			$blocklist = array_slice($blocklist, $offset, null, true);
			
			$reply = $lang->settings_blocklist_info."\n\n";
			if ($blocklist == []) {
				$reply .= $lang->no_blocklisted;
			} else {
				$reply .= $lang->registered_blocklisted;
			}
			$keyb = [[]];
			$line = 0;
			foreach ($blocklist as $key => $word) {
				$word = htmlspecialchars($word);
				if (mb_strlen($reply . "\n  <b>". ($key+1) ."</b>. <pre>{$word}</pre>") < 4096) {
					$reply .= "\n  <b>". ($key+1) ."</b>. <pre>{$word}</pre>";
					$new_offset = $key+1;
				} else break;
				if (count($keyb[$line]) != 2) {
					$keyb[$line][] = [sprintf($lang->delete_blocklist, $key+1), "delete_blocklist $key {$channel['id']} {$offset} {$old_offset}"];
				} else {
					$line++;
					$keyb[$line] = [[sprintf($lang->delete_blocklist, $key+1), "delete_blocklist $key {$channel['id']} {$offset} {$old_offset}"]];
				}
			}
			$line = [];
			if ($offset > 0) $line[] = ['◀', "blocklist {$channel['id']} {$old_offset} {$offset}"];
			if ($total > $new_offset) $line[] = ['▶', "blocklist {$channel['id']} {$new_offset} {$offset}"];
			$keyb[] = $line;
			$keyb[] = [[$lang->add_blocklist, "add_blocklist {$channel['id']}"]];
			$keyb[] = [[$lang->back, "deleting_rules {$channel['id']}"]];
			$keyb = ikb($keyb);
			$bot->edit($reply, ['reply_markup' => $keyb]);
		}
		
		else if (preg_match('#^delete_blocklist (?<key>.+) (?<id>.+?) (?<offset>.+) (?<old_offset>.+)#', $call, $matches)) {
			$new_offset = $offset = $matches['offset'] ?? 0;
			$old_offset = $matches['old_offset'];
			if ($old_offset > $offset) {
				$diff = $old_offset - $offset;
				$old_offset = $offset-$diff;
			}
			$blocklist = $db->querySingle("SELECT blocklist FROM channel WHERE id={$matches['id']}");
			$blocklist = json($blocklist);
			$blocklist = array_values($blocklist);
			unset($blocklist[$matches['key']]);
			$blocklist = array_values($blocklist);
			
			$blocklist_encoded = json($blocklist);
			$stmt = $db->prepare("UPDATE channel SET blocklist=?, last_editor={$user_id}, last_edit_time=".time()." WHERE id={$matches['id']}");
			$stmt->bindValue(1, $blocklist_encoded);
			$stmt->execute();
			
			$total = count($blocklist);
			$blocklist = array_slice($blocklist, $offset, null, true);
			
			$reply = $lang->settings_blocklist_info."\n\n";
			if ($blocklist == []) {
				$reply .= $lang->no_blocklisted;
			} else {
				$reply .= $lang->registered_blocklisted;
			}
			$keyb = [[]];
			$line = 0;
			foreach ($blocklist as $key => $word) {
				$word = htmlspecialchars($word);
				if (mb_strlen($reply . "\n  <b>". ($key+1) ."</b>. <pre>{$word}</pre>") < 4096) {
					$reply .= "\n  <b>". ($key+1) ."</b>. <pre>{$word}</pre>";
					$new_offset = $key+1;
				} else break;
				if (count($keyb[$line]) != 2) {
					$keyb[$line][] = [sprintf($lang->delete_blocklist, $key+1), "delete_blocklist $key {$matches['id']} {$offset} {$old_offset}"];
				} else {
					$line++;
					$keyb[$line] = [[sprintf($lang->delete_blocklist, $key+1), "delete_blocklist $key {$matches['id']} {$offset} {$old_offset}"]];
				}
			}
			$line = [];
			if ($offset > 0) $line[] = ['◀', "blocklist {$matches['id']} {$old_offset} {$offset}"];
			if ($total > $new_offset) $line[] = ['▶', "blocklist {$matches['id']} {$new_offset} {$offset}"];
			$keyb[] = $line;
			$keyb[] = [[$lang->add_blocklist, "add_blocklist {$matches['id']}"]];
			$keyb[] = [[$lang->back, "deleting_rules {$matches['id']}"]];
			$keyb = ikb($keyb);
			$bot->edit($reply, ['reply_markup' => $keyb]);
		}

		else if (preg_match('#^add_blocklist (?<id>.+)#', $call, $channel)) {
			$reply = $lang->add_blocklist_info;
			#$bot->edit($reply);
			$keyb = ikb([
				[[$lang->cancel, 'cancel']],
			]);
			$bot->edit($reply, ['reply_markup' => $keyb]);
			$db->query("UPDATE user SET waiting_for='blocklist', waiting_param='{$channel['id']}', waiting_back='blocklist {$channel['id']} 0 0' WHERE id={$user_id}");
		}

		else if (preg_match('#^timers (?<id>.+)#', $call, $channel)) {
			$timers = $db->querySingle("SELECT timers FROM channel WHERE id={$channel['id']}");
			$timers = json($timers);
			fetch_timers:
			ksort($timers);
			
			$reply = $lang->timers_info;
			$reply .= "\n\n";
			
			$keyb = [[]];
			$key = 1;
			$line = 0;
			foreach ($timers as $time => $mode) {
				$mode = (int)$mode;
				$time = (int)$time;
				if (time() >= $time) {
					unset($timers[$time]);
					$json = json($timers);
					$db->query("UPDATE channel SET timers='{$json}',switch={$mode} WHERE id={$channel['id']}");
					continue;
				}
				$reply .= "<b>{$key}</b>. ".($mode? '🔥' : '…')." <i>".date('H\hi d.m.Y', $time)."</i>\n";
				if (count($keyb[$line]) != 2) {
					$keyb[$line][] = [sprintf($lang->delete_timer, $key), "delete_timer {$time} {$channel['id']}"];
				} else {
					$line++;
					$keyb[$line] = [[sprintf($lang->delete_timer, $key), "delete_timer {$time} {$channel['id']}"]];
				}
				$key++;
			}
			
			if ($timers == []) {
				$reply .= $lang->no_timers;
			}
			
			$keyb[] = [[$lang->add, "add_timer {$channel['id']}"], [$lang->refresh, "timers {$channel['id']}"]];
			$keyb[] = [[$lang->fixed_timers, "fixed_timers {$channel['id']}"]];
			$keyb[] = [[$lang->back, "deleting_rules {$channel['id']}"]];
			
			$keyb = ikb($keyb);
			
			@$bot->edit($reply, ['reply_markup' => $keyb]);
		}
		
		else if (preg_match('#^delete_timer (?<timer>.+) (?<id>.+)#', $call, $channel)) {
			$timers = $db->querySingle("SELECT timers FROM channel WHERE id={$channel['id']}");
			$timers = json($timers);
			unset($timers[$channel['timer']]);
			$timers_json = json($timers);
			$db->query("UPDATE channel SET timers='{$timers_json}' WHERE id={$channel['id']}");
			goto fetch_timers;
		}
		
		else if (preg_match('#^add_timer (?<id>.+)#', $call, $channel)) {
			$reply = $lang->select_timer_type;
			$keyb = ikb([
				[['🔥', "timers_on {$channel['id']}"], ['…', "timers_off {$channel['id']}"]],
				[[$lang->back, "timers {$channel['id']}"]]
			]);
			$bot->edit($reply, ['reply_markup' => $keyb]);
		}
		
		else if (preg_match('#^timers_on (?<id>.+)#', $call, $channel)) {
			$reply = $lang->timers_on_info;
			$keyb = ikb([
				[[$lang->help, "timers_help {$channel['id']} on"]],
				[[$lang->cancel, "cancel"]]
			]);
			$bot->send($reply, ['reply_markup' => $keyb, 'disable_web_page_preview' => false]);
			$db->query("UPDATE user SET waiting_for='timer_on', waiting_param='{$channel['id']}', waiting_back='timers {$channel['id']}' WHERE id={$user_id}");
		}
		
		else if (preg_match('#^timers_off (?<id>.+)#', $call, $channel)) {
			$reply = $lang->timers_off_info;
			$keyb = ikb([
				[[$lang->help, "timers_help {$channel['id']} off"]],
				[[$lang->cancel, "cancel"]]
			]);
			$bot->send($reply, ['reply_markup' => $keyb, 'disable_web_page_preview' => false]);
			$db->query("UPDATE user SET waiting_for='timer_off', waiting_param='{$channel['id']}', waiting_back='timers {$channel['id']}' WHERE id={$user_id}");
		}
		
		else if (preg_match('#^timers_help (?<id>.+) (?<type>.+)#', $call, $channel)) {
			$reply = $lang->timers_help;
			$bot->send($reply, ['disable_web_page_preview' => false]);
		}
		
		else if (preg_match('#^fixed_timers (?<id>.+)#', $call, $channel)) {
			$timers = $db->querySingle("SELECT fixed_timers FROM channel WHERE id={$channel['id']}");
			$timers = json($timers) ?? [];
			fetch_fixed_timers:
			ksort($timers);
			
			$reply = $lang->fixed_timers_info;
			$reply .= "\n\n";
			
			$keyb = [[]];
			$key = 1;
			$line = 0;
			foreach ($timers as $time => &$info) {
				extract($info); # time, timezone, mode, last_executed
				@date_default_timezone_set($timezone);
				$last = $last_executed;
				$bool = ask_exec_timer($info);
				if ($bool) {
					$info['last_executed'] = time();
					$fix = json($timers);
					$db->prepare("UPDATE channel SET fixed_timers=? WHERE id={$channel['id']}")->execute([$fix]);
					$db->query("UPDATE channel SET switch={$mode} WHERE id={$channel['id']}");
				}
				$reply .= "<b>{$key}</b>. ".($mode? '🔥' : '…')." <i>".date('H\hi', $time)."</i> (".getGMT($timezone).")\n";
				if (count($keyb[$line]) != 2) {
					$keyb[$line][] = [sprintf($lang->delete_timer, $key), "delete_fixed_timer {$time} {$channel['id']}"];
				} else {
					$line++;
					$keyb[$line] = [[sprintf($lang->delete_timer, $key), "delete_fixed_timer {$time} {$channel['id']}"]];
				}
				$key++;
			}
			
			if ($timers == []) {
				$reply .= $lang->no_timers;
			}
			
			$keyb[] = [[$lang->add, "add_fixed_timer {$channel['id']}"], [$lang->refresh, "fixed_timers {$channel['id']}"]];
			$keyb[] = [[$lang->back, "deleting_rules {$channel['id']}"]];
			
			$keyb = ikb($keyb);
			
			@$bot->edit($reply, ['reply_markup' => $keyb]);
		}
		
		else if (preg_match('#^delete_fixed_timer (?<timer>.+) (?<id>.+)#', $call, $channel)) {
			$timers = $db->querySingle("SELECT fixed_timers FROM channel WHERE id={$channel['id']}");
			$timers = json($timers) ?? [];
			unset($timers[$channel['timer']]);
			$timers_json = json($timers);
			$db->query("UPDATE channel SET fixed_timers='{$timers_json}' WHERE id={$channel['id']}");
			goto fetch_fixed_timers;
		}
		
		else if (preg_match('#^add_fixed_timer (?<id>.+)#', $call, $channel)) {
			$reply = $lang->select_fixed_timer_type;
			$keyb = ikb([
				[['🔥', "fixed_timers_on {$channel['id']}"], ['…', "fixed_timers_off {$channel['id']}"]],
				[[$lang->back, "fixed_timers {$channel['id']}"]]
			]);
			$bot->edit($reply, ['reply_markup' => $keyb]);
		}
		
		else if (preg_match('#^fixed_timers_on (?<id>.+)#', $call, $channel)) {
			$reply = $lang->fixed_timers_on_info;
			$keyb = ikb([
				[[$lang->cancel, 'cancel']],
			]);
			$bot->send($reply, ['disable_web_page_preview' => false, 'reply_markup' => $keyb]);
			$db->query("UPDATE user SET waiting_for='fixed_timer_on', waiting_param='{$channel['id']}', waiting_back='fixed_timers {$channel['id']}' WHERE id={$user_id}");
		}
		
		else if (preg_match('#^fixed_timers_off (?<id>.+)#', $call, $channel)) {
			$reply = $lang->fixed_timers_off_info;
			$keyb = ikb([
				[[$lang->cancel, 'cancel']],
			]);
			$bot->send($reply, ['disable_web_page_preview' => false, 'reply_markup' => $keyb]);
			$db->query("UPDATE user SET waiting_for='fixed_timer_off', waiting_param='{$channel['id']}', waiting_back='fixed_timers {$channel['id']}' WHERE id={$user_id}");
		}
		
		else if (preg_match('#^admins (?<id>.+)#', $call, $channel)) {
			$adms = $db->querySingle("SELECT adm FROM channel WHERE id={$channel['id']}");
			$adms = json($adms);
			fetch_adms:
			$reply = $lang->settings_administrators_info;
			$owner = array_keys($adms)[0];
			
			$keyb = [];
			
			$name = ($bot->Chat($owner)['first_name'] ?? $owner) . ' 💼';
			$keyb[] = [[$name, "adm_profile {$owner} {$channel['id']}"]];
			
			$sudo = $adms[$user_id]['sudo'];
			if ($adms[$user_id]['sudo']) {
				unset($adms[$owner]);
				foreach ($adms as $id => $info) {
					if ($id == $user_id) {
						$name = $bot->Chat($user_id)['first_name'] . ($info['sudo']? ' 🛂' : '');
						$keyb[] = [[$name, "adm_profile {$id} {$channel['id']}"]];
					} else {
						$name = ($bot->Chat($id)['first_name'] ?? $id) . ($info['sudo'] ? ' 🛂' : '');
						if ($info['inviter'] == $user_id) {
							$keyb[] = [[$name, "adm_profile {$id} {$channel['id']}"], ['🛂', "sw_sudo {$id} {$channel['id']}"], ['🗑', "delete_adm {$id} {$channel['id']}"]];
						} else {
							$keyb[] = [[$name, "adm_profile {$id} {$channel['id']}"]];
						}
					}
				}
			} else {
				unset($adms[$owner]);
				$line = 1;
				$keyb[1] = [];
				foreach ($adms as $id => $info) {
					$name = ($bot->Chat($id)['first_name'] ?? $id) . ($info['sudo'] ? ' 🛂' : '');
					if (count($keyb[$line]) != 2) {
						$keyb[$line][] = [$name, "adm_profile {$id} {$channel['id']}"];
					} else {
						$line++;
						$keyb[$line] = [[$name, "adm_profile {$id} {$channel['id']}"]];
					}
				}
			}
			
			if ($user_id == $owner || $sudo) {
				$keyb[] = [[$lang->add_admin, "add_admin {$channel['id']}"]];
			}
			$keyb[] = [[$lang->back, "settings {$channel['id']}"]];
			$keyb = ikb($keyb);
			$bot->edit($reply, ['reply_markup' => $keyb]);
		}
		
		else if (preg_match('#^sw_sudo (?<adm>.+) (?<id>.+)#', $call, $channel)) {
			$adms = $db->querySingle("SELECT adm FROM channel WHERE id={$channel['id']}");
			$adms = json($adms);
			$adms[$channel['adm']]['sudo'] = !$adms[$channel['adm']]['sudo'];
			$adms_encoded = json($adms);
			$db->query("UPDATE channel SET adm='{$adms_encoded}' WHERE id={$channel['id']}");
			goto fetch_adms;
		}
		
		else if (preg_match('#^delete_adm (?<adm>.+) (?<id>.+)#', $call, $channel)) {
			$adms = $db->querySingle("SELECT adm FROM channel WHERE id={$channel['id']}");
			$adms = json($adms);
			unset($adms[$channel['adm']]);
			$adms_encoded = json($adms);
			$db->query("UPDATE channel SET adm='{$adms_encoded}' WHERE id={$channel['id']}");
			goto fetch_adms;
		}
		
		else if (preg_match('#^add_admin (?<id>.+)#', $call, $channel)) {
			$keyb = ikb([
				[[$lang->cancel, 'cancel']]
			]);
			$bot->edit($lang->add_admin_info, ['reply_markup' => $keyb]);
			$reg = $db->querySingle("SELECT adm FROM channel WHERE id={$channel['id']}");
			$reg = json($reg);
			$adms = $bot->getChatAdministrators(['chat_id' => $channel['id']]);
			$adms = $adms['result']();
			$adms = array_column($adms, 'user');
			$adms = array_column($adms, null, 'id');
			
			$options = [];
			$me = $bot->getMe()->id;
			foreach ($adms as $adm) {
				if (in_array($adm['id'], [$me, $user_id]) || isset($reg[$adm['id']])) continue;
				$options[] = isset($adm['username'])? '@'.$adm['username'] : "{$adm['id']} ({$adm['first_name']})";
			}
			$lines = array_group($options);
			$keyb = kb($lines, 0, ['one_time_keyboard' => true, 'resize_keyboard' => true]);
			$bot->send($lang->or_select_adm_from_kb, ['reply_markup' => $keyb]);
			$db->query("UPDATE user SET waiting_for='admin', waiting_param='{$channel['id']}', waiting_back='admins {$channel['id']}' WHERE id={$user_id}");
		}
		
		else if (preg_match('#^reset_channel (?<id>-\d+)(?: (?<force>force))?#', $call, $channel)) {
			if (!isset($channel['force'])) {
				$keyb = ikb([
					[[$lang->reset, $call.' force']],
					[[$lang->back, "settings {$channel['id']}"]]
				]);
				$bot->edit($lang->reset_ask, ['reply_markup' => $keyb]);
			} else {
				$bot->answer_callback($lang->channel_reseted, ['show_alert' => true]);
				$db->query("UPDATE channel SET
switch=0, timers='[]', fixed_timers='[]', block='[]', patterns='[]', blocklist='[]', whitelist='[]', twig='[]'
WHERE id={$channel['id']}");
				goto overview;
			}
		}
		
		else if (preg_match('#^remove_channel (?<id>-\d+)(?: (?<force>force))?#', $call, $channel)) {
			$keyb = ikb([
				[[$lang->back_to_channels, 'channels']]
			]);
			
			$adms = $db->querySingle("SELECT adm FROM channel WHERE id={$channel['id']}");
			$adms = json($adms);
			$owner = array_keys($adms)[0];
			if ($owner != $user_id) {
				$bot->edit($lang->channel_removed, ['reply_markup' => $keyb]);
				unset($adms[$user_id]);
				$adms = json($adms);
				$db->query("UPDATE channel SET adm='{$adms}' WHERE id={$channel['id']}");
			} else {
				if (!isset($channel['force'])) {
					$keyb = ikb([
						[[$lang->delete, $call.' force']],
						[[$lang->back, "settings {$channel['id']}"]]
					]);
					$bot->edit($lang->delete_ask, ['reply_markup' => $keyb]);
				} else {
					$bot->edit($lang->channel_deleted, ['reply_markup' => $keyb]);
					$db->query("DELETE FROM channel WHERE id={$channel['id']}");
					$bot->leaveChat(['chat_id' => $channel['id']]);
				}
			}
		}
		
		else if (preg_match('#^import_settings (?<id>.+)#', $call, $channel)) {
			$keyb = ikb([
				[[$lang->cancel, 'cancel']],
			]);
			$bot->edit($lang->waiting_for_import, ['reply_markup' => $keyb]);
			$db->query("UPDATE user SET waiting_for='import', waiting_param='{$channel['id']}', waiting_back='open {$channel['id']}' WHERE id={$user_id}");
		}
		
		else if (preg_match('#^export_settings (?<id>.+)#', $call, $channel)) {
			$mode = '111111';
			fetch_export:
			$reply = $lang->export_settings_info;
			$buttons = [
				"settings_types" => 'types',
				"settings_contents" => 'contents',
				"settings_patterns" => 'patterns',
				"settings_blocklist" => 'blocklist',
				"timers" => 'timers',
				"fixed_timers" => 'fixed_timers',
			];
			$keyb = [];
			$key = 0;
			foreach ($buttons as $btn => $data) {
				$item_mode = substr($mode, $key, 1);
				$btn = $lang->$btn.($item_mode? ' ✅' : '');
				$keyb[] = [[$btn, "sw_export {$data} {$mode} {$channel['id']}"]];
				$key++;
			}
			$keyb[] = [[$lang->export, "export {$mode} {$channel['id']}"], [$lang->back, "open {$channel['id']}"]];
			$keyb = ikb($keyb);
			$bot->edit($reply, ['reply_markup' => $keyb]);
		}
		
		else if (preg_match('#^sw_export (?<type>.+) (?<mode>.+) (?<id>.+)#', $call, $match)) {
			$types = ['types' => 0, 'contents' => 1, 'patterns' => 2, 'blocklist' => 3, 'timers' => 4, 'fixed_timers' => 5];
			$offset = $types[$match['type']];
			$value = (int) substr($match['mode'], $offset, 1);
			$value = (int) !$value;
			$mode = substr_replace($match['mode'], $value, $offset, 1);
			$channel = $match;
			goto fetch_export;
		}
		
		else if (preg_match('#^export (?<mode>.+) (?<id>.+)#', $call, $match)) {
			$modes = str_split($match['mode'], 1);
			$types = ['types', 'contents', 'patterns', 'blocklist', 'timers', 'fixed_timers'];
			$types = array_combine($types, $modes);
			$data = [
				'info' => ['creator' => $user_id, 'channel' => $match['id'], 'date' => time()],
			];
			$info = $db->query("SELECT * FROM channel WHERE id={$match['id']}")->fetch();
			$info['block'] = json($info['block']);
			#$bot->send(dump($info));
			#$bot->send(dump($types));
			foreach ($types as $type => $mode) {
				if ((int)$mode != 1) {
					$data[$type] = [];
				} else {
					if ($type == 'timers') {
						$timers = $info['timers'];
						$data[$type] = json($timers);
					} else if ($type == 'patterns') {
						$patterns = $info['patterns'];
						$data[$type] = json($patterns);
					} else if ($type == 'blocklist') {
						$blocklist = $info['blocklist'];
						$data[$type] = json($blocklist);
					} else if ($type == 'types') {
						$data[$type] = [];
						foreach ($info['block'] as $block) {
							$str = "{$type}_{$block}";
							if (isset($lang->$str)) {
								$data[$type][] = $block;
							}
						}
					} else if ($type == 'contents') {
						$data[$type] = [];
						foreach ($info['block'] as $block) {
							$str = "{$type}_{$block}";
							if (isset($lang->$str)) {
								$data[$type][] = $block;
							}
						}
					} else if ($type == 'fixed_timers') {
						$fixed_timers = $info['fixed_timers'];
						$data[$type] = json($fixed_timers);
					}
				}
			}
			$time = time();
			$db->query("INSERT INTO `import` (date, adm, id) VALUES ({$time}, {$user_id}, {$match['id']})");
			$data['id'] = $db->lastInsertId();
			$data = json($data);
			#$bot->send(($data));
			$crypt = new SCrypt('pauxis00', 'd9c37168ac697c57');
			$data = $crypt->encrypt($data);
			$name = "{$user_id}_{$time}.cfg";
			file_put_contents($name, $data);
			$bot->doc($name);
			unlink($name);
		}
		
		else if (preg_match("#^import_cfg (?<file_id>.+) (?<id>.+)#", $call, $match)) {
			$bot->action();
			$crypt = new SCrypt('pauxis00', 'd9c37168ac697c57');
			$content = $bot->read_file($match['file_id']);
			$content = $crypt->decrypt($content);
			$data = json($content);
			$reply = "{$lang->import_data}
{$lang->import_info}
  • {$lang->import_creator} {$bot->mention($data['info']['creator'])}\n";
			if ($data['info']['channel']) {
				$chat = $bot->Chat($data['info']['channel']);
				$reply .= "  • {$lang->import_channel} ".($chat? ($chat['username']? "@{$chat['username']}" : $chat['title']) : $data['info']['channel'])."\n";
			}
			$reply .= "  • {$lang->import_date} ".date('H\hi d.m.Y', $data['info']['date']).' ('.getGMT().') '."\n\n";
			unset($data['info'], $data['id']);
			foreach ($data as $key => $value) {
				if ($value == []) continue;
				$type = "import_{$key}";
				$reply .= "{$lang->$type}";
				if ($key == 'timers') {
					ksort($value);
					foreach ($value as $time => $mode) {
						$reply .= "\n  • ".($mode? '🔥' : '…')." <i>". date('H\hi d.m.Y', $time)."</i>";
					}
				} else if ($key == 'fixed_timers') {
					ksort($value);
					foreach ($value as $time => $timer_info) {
						extract($timer_info);
						$reply .= "\n  • ".($mode? '🔥' : '…')." <i>".date('H\hi', $time)."</i> (".getGMT($timezone).")";
					}
				} else if ($key == 'patterns') {
					foreach ($value as $pattern) {
						$reply .= "\n  • <pre>". htmlspecialchars($pattern) ."</pre>";
					}
				} else if ($key == 'blocklist') {
					foreach ($value as $blockword) {
						$reply .= "\n  • <pre>". htmlspecialchars($blockword) ."</pre>";
					}
				} else if ($key == 'contents' || $key == 'types') {
					foreach ($value as $item) {
						$type = "{$key}_{$item}";
						$reply .= "\n  • {$lang->$type}";
					}
				}
				
				$reply .= "\n\n";
			}
			$keyb = ikb([
				[[$lang->apply, "import {$match['file_id']} {$match['id']}"], [$lang->cancel, "cancel_import {$match['id']}"]]
			]);
			$bot->edit($reply, ['reply_markup' => $keyb]);
		}
		
		else if (preg_match('#^import (?<file_id>.+) (?<id>.+)#', $call, $match)) {
			$bot->action();
			$crypt = new SCrypt('pauxis00', 'd9c37168ac697c57');
			$content = $bot->read_file($match['file_id']);
			$content = $crypt->decrypt($content);
			$data = json($content);
			$data['block'] = array_merge($data['types'], $data['contents']);
			$id = $data['id'];
			unset($data['id'], $data['info'], $data['types'], $data['contents']);
			foreach ($data as $key => $array) {
				if (!in_array($key, ['block', 'patterns', 'blocklist', 'timers', 'fixed_timers'])) continue; # do you believe i was not checking the values before publishing?
				if ($array == []) continue;
				$json = $db->quote(json($array));
				$db->query("UPDATE channel SET {$key}={$json} WHERE id={$match['id']}"); # S H A M E F U L
			}
			$keyb = ikb([
				[[$lang->go_panel, "open {$match['id']}"]]
			]);
			$bot->edit($lang->import_successful, ['reply_markup' => $keyb]);
			$db->query("UPDATE `import` SET imported=(imported + 1) WHERE `key`={$id}");
		}
		
		else if (preg_match('#^cancel_import (?<id>.+)#', $call, $channel)) {
			$keyb = ikb([
				[[$lang->go_panel, "open {$channel['id']}"]]
			]);
			$bot->edit($lang->import_canceled, ['reply_markup' => $keyb]);
		} else if ($call == 'cancel') {
			$result = $db->query("SELECT waiting_back FROM user WHERE id={$user_id}")->fetch();
			$back = $result['waiting_back'] ?: 'channels';
			
			$bot->deleteMessage(['chat_id' => $chat_id, 'message_id' => $bot->send('Removing keyboard', ['reply_markup' => hide_kb()])->message_id]);
			$db->query("UPDATE user SET waiting_for='', waiting_param='', waiting_back='' WHERE id={$user_id}");
			$data['data'] = $call = $back;
			goto call_checks;
		} else if (preg_match('#^delete_post (?<chat_id>.+) (?<message_id>.+)#', $call, $match)) {
			extract($match);
			$del = @$bot->delete($message_id, $chat_id);
			if ($del->ok) {
				post_deleted:
				$text = \phgram\entities_to_html($bot->Text(), $bot->Entities()->asArray());
				$text .= "\n\n✅ ".$lang->post_deleted;
				$bot->edit($text);
			} else {
				$error = $del->description;
				$code = $del->error_code;
				if ($error == "Bad Request: message to delete not found") {
					$bot->answer_callback($lang->post_already_deleted, ['show_alert' => 'true']);
					goto post_deleted;
				}
				$bot->answer_callback("Error $code: $error", ['show_alert' => true]);
			}
		} else if ($call == 'remove_ikb') {
			$bot->editMessageReplyMarkup(['message_id' => $message_id, 'chat_id' => $chat_id, 'reply_markup' => ikb([])]);
		} else {
			$bot->send('Bad data ;(');
		}
		
		@$bot->answerCallbackQuery(['callback_query_id' => @$call_id]);
	}
	
	else if ($type == 'message') {
		$text = $bot->Text();
		$chat_id = $data['chat']['id'];
		$user_id = $data['from']['id'];
		$type = $data['chat']['type'];
		$message_id = $data['message_id'];
		$replied = $bot->ReplyToMessage();
		
		$lang = setLanguage($user_id, $lang);
		setTimezone($user_id);
		
		text_checks:
		
		if ($type != 'private') {
			if ($text == '/connect' || $text == '/connect@silentgbot') {
				if (!$bot->is_admin($user_id, $chat_id)) {
					return @$bot->delete();
				} else if (!$bot->getChatMember(['chat_id' => $chat_id, 'user_id' => $bot->getMe()['id']])['can_delete_messages']) {
					return $bot->reply($lang->connect_permissions_error);
				} else if ($db->querySingle("SELECT 1 FROM channel WHERE id={$chat['id']}")) {
					$adm = array_keys(json($db->querySingle("SELECT adm FROM channel WHERE id={$chat_id}")))[0];
					if ($adm == $user_id) {
						$keyb = ikb([
							[[$lang->cancel, 'cancel']]
						]);
						return $bot->send($lang->connect_already_registered, ['reply_markup' => $keyb]);
					} else {
						$keyb = ikb([
							[[$lang->cancel, 'cancel']]
						]);
						return $bot->send(sprintf($lang->connect_other_admin, "<a href='tg://user?id={$adm}'>{$bot->Chat($adm)['first_name']}</a>"), ['reply_markup' => $keyb]);
					}
				} else {
					$adms = [$user_id => ['sudo' => true, 'inviter' => $user_id]];
					$adms = json($adms);
					$time = time();
					if ($db->query("INSERT INTO channel (id, adm, date) VALUES ({$chat_id}, '{$adms}', {$time})")) {
						$bot->send(sprintf($lang->connect_success, $chat['title']));
						$db->query("UPDATE user SET waiting_for='' WHERE id={$user_id}");
					} else {
						return $bot->reply($lang->connect_oops);
					}
				}
			}
			/*if (substr($text, -11) == '@silentcbot') {
				$text = preg_replace('#@silentcbot$#', '', $text);
				$ikb = ikb([
					[['Start using the bot', 't.me/silentcbot', 'url']],
					[['@hpxlist', 'https://t.me/joinchat/AAAAAEi56S9GuFH7EOAtzA', 'url']],
				]);
				$bot->reply("❕Unfortunately, at the moment this bot doesn't work on groups. SilentC is a channel-only tool.
Use it in PM.", ['reply_markup' => $ikb]);
			}*/ # i disabled it because i saw some groups getting spammed with this message (yes, i could have used leaveChat)
		}
		
		else if ($db->querySingle("SELECT 1 FROM user WHERE id={$user_id} AND waiting_for!=''")) {
			$result = $db->query("SELECT waiting_for, waiting_param, waiting_back FROM user WHERE id={$user_id}")->fetch();
			$waiting_for = $result['waiting_for'];
			$param = $result['waiting_param'];
			$back = $result['waiting_back'] ?: 'channels';
			
			if ($text == '/cancel') {
				$bot->deleteMessage(['chat_id' => $chat_id, 'message_id' => $bot->send('Removing keyboard', ['reply_markup' => hide_kb()])->message_id]);
				$keyb = ikb([
					[[$lang->back, $back]],
				]);
				$bot->send($lang->command_canceled, ['reply_markup' => $keyb]);
				$db->query("UPDATE user SET waiting_for='', waiting_param='', waiting_back='' WHERE id={$user_id}");
			} else if ($waiting_for == 'channel') {
				$bot->action();
				
				$chat = $data['forward_from_chat'] ?? @$bot->Chat($text);
				#$bot->log($chat);
				if (!isset($chat['id'])) {
					connect_error:
					$reply = $lang->connect_error;
					return $bot->send($reply);
				}
				if (!$bot->in_chat($bot->getMe()['id'], $chat['id'])) {
					$keyb = ikb([
						[[$lang->cancel, 'cancel']]
					]);
					return $bot->send($lang->connect_bot_not_in, ['reply_markup' => $keyb]);
				} else if (!$bot->is_admin($user_id, $chat['id'])) {
					$keyb = ikb([
						[[$lang->cancel, 'cancel']]
					]);
					return $bot->send($lang->connect_not_admin, ['reply_markup' => $keyb]);
				} else if (!$bot->getChatMember(['chat_id' => $chat['id'], 'user_id' => $bot->getMe()['id']])['can_delete_messages']) {
					$keyb = ikb([
						[[$lang->cancel, 'cancel']]
					]);
					return $bot->send($lang->connect_permissions_error, ['reply_markup' => $keyb]);
				} else if ($db->querySingle("SELECT 1 FROM channel WHERE id={$chat['id']}")) {
					$adm = array_keys(json($db->querySingle("SELECT adm FROM channel WHERE id={$chat['id']}")))[0];
					if ($adm == $user_id) {
						$keyb = ikb([
							[[$lang->cancel, 'cancel']]
						]);
						return $bot->send($lang->connect_already_registered, ['reply_markup' => $keyb]);
					} else {
						$keyb = ikb([
							[[$lang->cancel, 'cancel']]
						]);
						return $bot->send(sprintf($lang->connect_other_admin, "<a href='tg://user?id={$adm}'>{$bot->Chat($adm)['first_name']}</a>"), ['reply_markup' => $keyb]);
					}
				} else {
					$adms = [$user_id => ['sudo' => true, 'inviter' => $user_id]];
					$adms = json($adms);
					$time = time();
					if ($db->query("
INSERT INTO 
channel (id, adm, date, timers, fixed_timers, block, patterns, blocklist, info, whitelist, mp_admins, twig)
VALUES ({$chat['id']}, '{$adms}', {$time}, '[]', '[]', '[]', '[]', '[]', '[]', '[]', '[]', '[]')
")) {
						$bot->send(sprintf($lang->connect_success, $chat['title']));
						$db->query("UPDATE user SET waiting_for='' WHERE id={$user_id}");
					} else {
						$keyb = ikb([
							[[$lang->cancel, 'cancel']]
						]);
						return $bot->send($lang->connect_oops, ['reply_markup' => $keyb]);
					}
				}
			} else if ($waiting_for == 'pattern') {
				ini_set('track_errors', 'on');
				$php_errormsg = '';
				@preg_match($text, '');
				
				if ($php_errormsg != '') {
					$keyb = ikb([
						[[$lang->cancel, 'cancel']]
					]);
					$bot->send($lang->invalid_pattern, ['reply_markup' => $keyb]);
				} else {
					$id = $param;
					
					$patterns = $db->querySingle("SELECT patterns FROM channel WHERE id={$id}");
					$patterns = json($patterns);
					$patterns = array_values($patterns);
					$patterns[] = $text;
					
					$patterns_encoded = json($patterns);
					$stmt = $db->prepare("UPDATE channel SET patterns=?, last_editor={$user_id}, last_edit_time=".time()." WHERE id={$id}");
					$stmt->bindValue(1, $patterns_encoded);
					$stmt->execute();
					
					$keyb = ikb([
						[[$lang->back, "patterns {$id} 0 0"]]
					]);
					$bot->reply($lang->pattern_added, ['reply_markup' => $keyb]);
					$db->query("UPDATE user SET waiting_for='', waiting_param='', waiting_back='' WHERE id={$user_id}");
				}
				ini_set('track_errors', 'off');
			} else if ($waiting_for == 'blocklist') {
				$id = $param;
					
				$blocklist = $db->querySingle("SELECT blocklist FROM channel WHERE id={$id}") ?: '[]';
				$blocklist = json($blocklist);
				$blocklist = array_values($blocklist);
				$blocklist[] = $text;
				
				$blocklist_encoded = json($blocklist);
				$stmt = $db->prepare("UPDATE channel SET blocklist=?, last_editor={$user_id}, last_edit_time=".time()." WHERE id={$id}");
				$stmt->bindValue(1, $blocklist_encoded);
				$stmt->execute();
					
				$keyb = ikb([
					[[$lang->back, "blocklist {$id} 0 0"]]
				]);
				$bot->reply($lang->blocklist_added, ['reply_markup' => $keyb]);
					
				$db->query("UPDATE user SET waiting_for='', waiting_param='', waiting_back='' WHERE id={$user_id}");
			} else if ($waiting_for == 'admin') {
				$bot->action();
				if (isset($data['forward_from'])) {
					$id = $data['forward_from']['id'];
				} else if (preg_match('#^@[a-z]\w+$#is', $text)) {
					$id = get_id($text);
					if (!$id) {
						$keyb = ikb([
							[[$lang->cancel, 'cancel']]
						]);
						return $bot->reply($lang->invalid_admin_username, ['reply_markup' => $keyb]);
					}
				} else if (preg_match('#^(?<id>\d+) (.+)$#', $text, $match)) {
					$id = $match['id'];
				} else {
					$keyb = ikb([
						[[$lang->cancel, 'cancel']]
					]);
					return $bot->reply($lang->add_admin_error, ['reply_markup' => $keyb]);
				}
				
				if (!$bot->is_admin($id, $param)) {
					$keyb = ikb([
						[[$lang->cancel, 'cancel']]
					]);
					return $bot->send($lang->add_admin_error, ['reply_markup' => $keyb]);
				} else if ($db->querySingle("SELECT 1 FROM channel WHERE adm LIKE '%{$id}%' AND id={$param}")) {
					$keyb = ikb([
						[[$lang->cancel, 'cancel']]
					]);
					return $bot->send($lang->admin_already_added, ['reply_markup' => $keyb]);
				} else {
					$keyb = ikb([
						[[$lang->back, "admins {$param}"]]
					]);
					$bot->reply($lang->admin_added, ['reply_markup' => $keyb]);
					
					$adms = $db->querySingle("SELECT adm FROM channel WHERE id={$param}");
					$adms = json($adms);
					$adms[$id] = ['sudo' => false, 'inviter' => $user_id];
					$adms = json($adms);
					$db->query("UPDATE channel SET adm='{$adms}' WHERE id={$param}");
					$db->query("UPDATE user SET waiting_for='', waiting_param='', waiting_back='' WHERE id={$user_id}");
				}
				$bot->deleteMessage(['chat_id' => $chat_id, 'message_id' => $bot->send('Removing keyboard', ['reply_markup' => hide_kb()])->message_id]);
			} else if ($waiting_for == 'import') {
				if (!isset($data['document'])) {
					$keyb = ikb([
						[[$lang->cancel, 'cancel']]
					]);
					return $bot->send($lang->import_error_not_document, ['reply_markup' => $keyb]);
				}
				$match = ['file_id' => $data['document']['file_id'], 'id' => $param];
				$bot->action();
				$crypt = new SCrypt('pauxis00', 'd9c37168ac697c57');
				$content = $bot->read_file($match['file_id']);
				$content = $crypt->decrypt($content);
				$data = json($content);
				if (!$data) {
					$keyb = ikb([
						[[$lang->cancel, 'cancel']]
					]);
					$bot->send($lang->invalid_import_file, ['reply_markup' => $keyb]);
					return false;
				}
				$reply = "{$lang->import_data}
{$lang->import_info}
  • {$lang->import_creator} {$bot->mention($data['info']['creator'])}\n";
				if ($data['info']['channel']) {
					$chat = $bot->Chat($data['info']['channel']);
					$reply .= "  • {$lang->import_channel} ".($chat? ($chat['username']? "@{$chat['username']}" : $chat['title']) : $data['info']['channel'])."\n";
				}
				$reply .= "  • {$lang->import_date} ".date('H\hi d.m.Y', $data['info']['date']).' ('.getGMT().') '."\n\n";
				unset($data['info'], $data['id']);
				foreach ($data as $key => $value) {
					if ($value == []) continue;
					$type = "import_{$key}";
					$reply .= "{$lang->$type}";
					if ($key == 'timers') {
						ksort($value);
						foreach ($value as $time => $mode) {
							$reply .= "\n  • ".($mode? '🔥' : '…')." <i>". date('H\hi d.m.Y', $timer)."</i>";
						}
					} else if ($key == 'fixed_timers') {
						ksort($value);
						foreach ($value as $time => $timer_info) {
							extract($timer_info);
							$reply .= "\n  • ".($mode? '🔥' : '…')." <i>".date('H\hi', $time)."</i> (".getGMT($timezone).")";
						}
					} else if ($key == 'patterns') {
						foreach ($value as $pattern) {
							$reply .= "\n  • <pre>". htmlspecialchars($pattern) ."</pre>";
						}
					} else if ($key == 'contents' || $key == 'types') {
						foreach ($value as $item) {
							$type = "{$key}_{$item}";
							$reply .= "\n  • {$lang->$type}";
						}
					}
					
					$reply .= "\n\n";
				}
				$keyb = ikb([
					[[$lang->apply, "import {$match['file_id']} {$match['id']}"], [$lang->cancel, "cancel_import {$match['id']}"]]
				]);
				
				$bot->reply($reply, ['reply_markup' => $keyb]);
				$db->query("UPDATE user SET waiting_for='', waiting_param='', waiting_back='' WHERE id={$user_id}");
			} else if ($waiting_for == 'region') {
				$timezones = json(file_get_contents('functions/timezones.json'));
				$regions = array_keys($timezones);
				if (!in_array($text, $regions)) {
					$keyb = ikb([
						[[$lang->cancel, 'cancel']]
					]);
					$bot->send($lang->invalid_region, ['reply_markup' => $keyb]);
				} else {
					$keyb = [[]];
					$line = 0;
					foreach ($timezones[$text] as $timezone) {
						if (count($keyb[$line]) != 2) {
							$keyb[$line][] = $timezone;
						} else {
							$line++;
							$keyb[$line] = [$timezone];
						}
					}
					$keyb = kb($keyb, 0, ['one_time_keyboard' => true, 'resize_keyboard' => true]);
					$bot->send($lang->choose_timezone, ['reply_markup' => $keyb]);
					$db->query("UPDATE user SET waiting_for='timezone', waiting_back='setup' WHERE id={$user_id}");
				}
			} else if ($waiting_for == 'timezone') {
				ini_set('track_errors', 'on');
				$php_errormsg = '';
				@date_default_timezone_set($text);
				
				if ($php_errormsg) {
					$keyb = ikb([
						[[$lang->cancel, 'cancel']]
					]);
					$bot->send($lang->invalid_timezone, ['reply_markup' => $keyb]);
				} else {
					$keyb = ikb([
						[[$lang->back, 'setup']]
					]);
					$bot->send(sprintf($lang->timezone_set, $text), ['reply_markup' => $keyb]);
					$db->query("UPDATE user SET timezone='{$text}' WHERE id={$user_id}");
					$db->query("UPDATE user SET waiting_for='', waiting_param='', waiting_back='' WHERE id={$user_id}");
					
					$bot->deleteMessage(['chat_id' => $chat_id, 'message_id' => $bot->send('Removing keyboard', ['reply_markup' => hide_kb()])->message_id]);
				}
				ini_set('track_errors', 'off');
			} else if ($waiting_for == 'timer_on' || $waiting_for == 'timer_off') {
				if (preg_match('#^\d{1,2}([ :\.]\d{1,4}){0,4}$#isu', $text)) {
					$timestamp = timer($text);
					if (!$timestamp) {
						$keyb = ikb([
							[[$lang->cancel, 'cancel']]
						]);
						$bot->send($lang->schedule_failed, ['reply_markup' => $keyb]);
						return false;
					}
					if ($timestamp <= time()) {
						$keyb = ikb([
							[[$lang->cancel, 'cancel']]
						]);
						$bot->send($lang->low_timestamp_error, ['reply_markup' => $keyb]);
						return false;
					}
					$keyb = ikb([
						[[$lang->back, "timers {$param}"]],
					]);
					$bot->send(sprintf($lang->timer_set_to, date('H\hi, d.m.Y', $timestamp)), ['reply_markup' => $keyb]);
					$timers = $db->querySingle("SELECT timers FROM channel WHERE id={$param}");
					$timers = json($timers);
					$timers[$timestamp] = (int) ($waiting_for == 'timer_on');
					$timers = json($timers);
					$db->query("UPDATE channel SET timers='{$timers}' WHERE id={$param}");
					$db->query("UPDATE user SET waiting_for='', waiting_param='', waiting_back='' WHERE id={$user_id}");
				} else {
					$keyb = ikb([
						[[$lang->cancel, 'cancel']]
					]);
					$bot->send($lang->timer_format_error, ['reply_markup' => $keyb]);
				}
			} else if ($waiting_for == 'fixed_timer_on' || $waiting_for == 'fixed_timer_off') {
				if (preg_match('#^\d{1,2}([ :\.]\d{1,4}){0,4}$#isu', $text)) {
					$timestamp = fixed_timer($text);
					if (!$timestamp) {
						$keyb = ikb([
							[[$lang->cancel, 'cancel']]
						]);
						$bot->send($lang->schedule_failed, ['reply_markup' => $keyb]);
						return false;
					}
					$keyb = ikb([
						[[$lang->back, "fixed_timers {$param}"]],
					]);
					$bot->send(sprintf($lang->fixed_timer_set_to, date('H\hi', $timestamp)), ['reply_markup' => $keyb]);
					$timers = $db->querySingle("SELECT fixed_timers FROM channel WHERE id={$param}");
					$timers = json($timers);
					$timers[$timestamp] = [
						'timezone' => date_default_timezone_get(),
						'time' => $timestamp,
						'mode' => (int)($waiting_for == 'fixed_timer_on'),
						'last_executed' => 0,
						'created_at' => time(),
					];
					$timers = json($timers);
					$db->query("UPDATE channel SET fixed_timers='{$timers}' WHERE id={$param}");
					$db->query("UPDATE user SET waiting_for='', waiting_param='', waiting_back='' WHERE id={$user_id}");
				} else {
					$keyb = ikb([
						[[$lang->cancel, 'cancel']]
					]);
					$bot->send($lang->timer_format_error, ['reply_markup' => $keyb]);
				}
			}
			else if ($waiting_for == 'dev') {
				$msg = $text;
				$dev_chat = $cfg['sudoers'][0];
				send_dev:
				$id = $bot->forwardMessage(['chat_id' => $dev_chat, 'from_chat_id' => $chat_id, 'message_id' => $message_id])->message_id;
				
				$alert = $bot->send($lang->dev_chat_sent)->message_id;
				$message = 'DEV '.json_encode(['user_from' => $user_id, 'chat_from' => $chat_id, 'message_id' => $message_id, 'alert_id' => $alert]);
				$bot->send($message, ['chat_id' => $dev_chat, 'reply_to_message_id' => $id]);
			}
			else if ($waiting_for == 'twig') {
				require_once 'twig.phar';
				$template = $text;
				$loader = new \Twig\Loader\ArrayLoader([
					'index' => 'Hello {{ name }}!',
				]);
				$twig = new \Twig\Environment($loader, ['debug' => true]);
				$twig->addExtension(new \Twig\Extension\DebugExtension());
				$twig->addExtension(getSandbox());
				try {
					$inf = $bot->Chat($param);
					$chmention = $inf['username'] ?? $param;
					$id = 'new_'.$chmention.'.twig';
					$twig->parse($twig->tokenize(new \Twig\Source($template, $id)));
					// the $template is valid
				} catch (\Twig\Error\SyntaxError $e) {
					$err = htmlspecialchars($e->getMessage());
					$err = str_replace('&quot;'.$id.'&quot;', 'your template', $err);
					return $bot->reply("❗{$lang->syntax_error}: <i>{$err}</i>");
					// $template contains one or more syntax errors
				}
				
				$twig = $db->queryJson("SELECT twig FROM channel WHERE id={$param}");
				$twig = [
					'enabled' => true,
					'template' => $template,
					'db' => ($twig['db'] ?? serialize(null)),
					'debug' => ($twig['debug'] ?? '[]'),
					'adm' => $user_id,
					'time' => time()
				];
				$add = $db->prepare('UPDATE channel SET twig=? WHERE id=?')->execute([json($twig), $param]);
				if ($add) {
					$keyb = ikb([
						[[$lang->back, "twig {$param}"]],
					]);
					$bot->send($lang->twig_added, ['reply_markup' => $keyb]);
					$db->query("UPDATE user SET waiting_for='', waiting_param='', waiting_back='' WHERE id={$user_id}");
				} else {
					$bot->send('Failed. Try again.');
				}
			}
			else if ($waiting_for == 'twig_edit') {
				$twig = $db->queryJson("SELECT twig FROM channel WHERE id={$param}");
				$twig['db'] = unserialize($twig['db']);
				if (preg_match('#^/edit (?<key>.+?) (?<value>.+)#isu', $text, $match)) {
					extract($match);
					$keys = explode('.', $key);
					if (substr($key, -1, 1) == '.') array_pop($keys);
					if (!is_array($twig['db'])) $twig['db'] = [];
					if ($key == '.') {
						$twig['db'][] = $value;
					}
					else if (count($keys) > 1) {
						$key_str = "";
						foreach ($keys as $k) {
							if ($k === '') {
								$key_str .= '[]';
							} else {
								$key_str .= '['.$db->quote($k).']';
							}
						}
						eval('$twig[\'db\']'.$key_str.' = $value;');
					} else {
						$key = $keys[0];
						$twig['db'][$key] = $value;
					}
				} else if ($text) {
					$dec = @json_decode($text, 1);
					$twig['db'] = $dec === null && $text !== 'null'? $text : $dec;
				} else if ($file_id = $message->find('file_id')) {
					$cont = $bot->read_file($file_id);
					$dec = json_decode($cont, 1);
					$twig['db'] = $dec === null && $cont !== 'null'? $cont : $dec;
				} else {
					return $bot->send($lang->invalid_value_twig_db);
				}
				$new_db = json_encode($twig['db'], 480);
				$twig['db'] = serialize($twig['db']);
				$add = $db->prepare('UPDATE channel SET twig=? WHERE id=?')->execute([json($twig), $param]);
				if ($add) {
					$ikb = ikb([
						[[$lang->back, "cancel"]]
					]);
					if (strlen($new_db) <= 4000) {
						$new_db = htmlspecialchars($new_db);
						$bot->send($lang->twig_db_new_value_set."\n\n<code>$new_db</code>", ['reply_markup' => $ikb]);
					} else {
						$msg = $bot->indoc($new_db, 'twig.db.txt');
						$msg->reply($lang->twig_db_new_value_set, ['reply_markup' => $ikb]);
					}
					#$db->query("UPDATE user SET waiting_for='', waiting_param='', waiting_back='' WHERE id={$user_id}");
				} else {
					$bot->send('Failed. Try again.');
				}
				
			}
			else {
				$db->query("UPDATE user SET waiting_for='', waiting_param='', waiting_back='' WHERE id={$user_id}");
				goto text_checks;
			}
		}

		else if (preg_match('#^/start\b#', $text)) {
			$keyb = ikb([
				[[$lang->help, 'help'], [$lang->about, 'about']],
				[[$lang->my_channels, 'channels']],
			]);
			$bot->send($lang->start_text, ['reply_markup' => $keyb]);
			if (!$db->querySingle("SELECT 1 FROM user WHERE id={$user_id}")) {
				$keyb = ikb([
					[[$lang->letsgo, 'setup']],
				]);
				$bot->send($lang->basic_settings_alert, ['reply_markup' => $keyb]);
				
				$language = $bot->Language() ?: 'en';
				$language = strtolower(str_replace(['-', '_'], '', $language));
				$time = time();
				$timezone = $language == 'ptbr'? 'America/Sao_Paulo' : 'UTC';
				$db->query("INSERT INTO user (id, language, date, timezone) VALUES ({$user_id}, '{$language}', {$time}, '{$timezone}')");
			}
		}

		else if ($text == '/menu') {
			goto list_channels;
		}

		else if ($text == '/help') {
			$bot->sendMessage(['chat_id' => $chat_id, 'text' => $lang->faq, 'parse_mode' => 'html']);
		}

		else if ($text == '/connect') {
			$keyb = ikb([
				[[$lang->cancel, 'cancel']],
			]);
			$bot->send($lang->connect, ['reply_markup' => $keyb]);
			#$bot->send($lang->connect);
			$db->query("UPDATE user SET waiting_for='channel' WHERE id={$user_id}");
		}
		
		else if ($text == '/settings') {
			$info = $db->query("SELECT language, timezone FROM user WHERE id={$user_id}")->fetch();
			
			$keyb = ikb([
				[[sprintf($lang->bs_timezone, $info['timezone']), 'set_timezone']],
				[[sprintf($lang->bs_language, $lang->NAME), 'set_language']],
			]);
			$bot->send($lang->basic_settings_info, ['reply_markup' => $keyb]);
		} else if (preg_match('#^/dev( (?<msg>.+))?#isu', $text, $match)) {
			extract($match);
			if (isset($msg)) {
				goto send_dev;
			} else {
				$keyb = ikb([
					[[$lang->cancel, 'cancel']],
				]);
				$bot->send($lang->dev_chat_activated, ['reply_markup' => $keyb]);
				#$bot->send($lang->dev_chat_activated);
				$db->query("UPDATE user SET waiting_for='dev' WHERE id={$user_id}");
			}
		}
		
		
		else if (isset($data['document']) && preg_match('#\.cfg$#', $data['document']['file_name'])) {
			$bot->action();
			$crypt = new SCrypt('pauxis00', 'd9c37168ac697c57');
			
			$file_id = $data['document']['file_id'];
			$content = $bot->read_file($file_id);
			$data = $crypt->decrypt($content);
			$data = @json($data);
			
			if ($data && isset($data['id']) && isset($data['timers']) && isset($data['types']) && isset($data['contents']) && isset($data['patterns']) && isset($data['info']) && isset($data['fixed_timers'])) {
				$reply = $lang->select_channel_to_import;
				$result = $db->query("SELECT id FROM channel WHERE adm LIKE '%{$user_id}%'");
				
				$keyb = [];
				while ($channel = $result->fetch()) {
					$info = @$bot->Chat($channel['id']);
					if (!$info || !$bot->is_admin($user_id, $channel['id'])) {
						continue;
					}
					$keyb[] = [[$info['title'], "import_cfg {$file_id} {$channel['id']}"]];
				}
				if ($keyb == []) {
					return $bot->send($lang->no_channels);
				}
				$keyb = ikb($keyb);
				$bot->reply($reply, ['reply_markup' => $keyb]);
			} else {
				$bot->reply($lang->invalid_import_file);
			}
		}
		
		else if (preg_match('#^/update(?: (?<lang>\w+))#isu', $text, $match) && isset($replied['document'])) {
			if (@$_GET['beta'] != true) {
				return false;
			}
			$l = $match['lang'] ?? $lang->language;
			$file_id = $replied['document']['file_id'];
			$content = $bot->read_file($file_id);
			$str = json_decode($content, 1);
			if (!$str) return $bot->send('fail');
			$lang->data->$l = $str;
			$lang->save();
			$bot->send('Done!');
		}
		
		# for sudoers
		else if (in_array($user_id, $cfg['sudoers'])) {
			if (isset($replied['text']) && substr($replied->text, 0, 4) == 'DEV ') {
				$json = substr($replied['text'], 4);
				$json = json_decode($json, 1);
				extract($json);
				@$bot->deleteMessage(['chat_id' => $chat_from, 'message_id' => $alert_id]);
				$bot->send($text, ['chat_id' => $chat_from, 'reply_to_message_id' => $message_id]);
			}
			
			else if ($text == '/stats') {
				$msg = $bot->send('Wait a while...');
				$bot->action();
				$stats_id = $cfg['sudoers'][0];
				$template = "
<b>Total users:</b> %s
<b>New users (24h):</b> %s
<b>New users (7d):</b> %s
<b>New users (30d):</b> %s

<b>Total channels:</b> %s
<b>New channels (24h):</b> %s
<b>New channels (7d):</b> %s
<b>New channels (30d):</b> %s

<b>Top languages:</b>
%s
";
				
				$time_24h_ago = time() - (24*60*60);
				$time_7d_ago = time() - (7*24*60*60);
				$time_30d_ago = time() - (30*24*60*60);
				
				$args['total_users'] = $db->querySingle('SELECT COUNT(*) as count FROM user');
				$args['new_users_24'] = $db->querySingle("SELECT COUNT(*) FROM user WHERE date > {$time_24h_ago}");
				$args['new_users_7'] = $db->querySingle("SELECT COUNT(*) FROM user WHERE date > {$time_7d_ago}");
				$args['new_users_30'] = $db->querySingle("SELECT COUNT(*) FROM user WHERE date > {$time_30d_ago}");

				$args['total_channels'] = $db->querySingle('SELECT COUNT(*) as count FROM channel');
				$args['new_channels_24'] = $db->querySingle("SELECT COUNT(*) FROM channel WHERE date > {$time_24h_ago}");
				$args['new_channels_7'] = $db->querySingle("SELECT COUNT(*) FROM channel WHERE date > {$time_7d_ago}");
				$args['new_channels_30'] = $db->querySingle("SELECT COUNT(*) FROM channel WHERE date > {$time_30d_ago}");
				

				$lgs = $db->query('SELECT language, count(language) as count FROM user GROUP BY language ORDER BY count DESC LIMIT 10');
				$langs_str = '';
				foreach ($lgs as $row) {
					$langs_str .= "{$row['language']} - {$row['count']}\n";
				}
				$args['langs'] = $langs_str;
				$message = vsprintf($template, $args);
				
				$bot->edit($message, ['message_id' => $msg->message_id]);
				#stats
				$final = '';
				
				// days
				$msg = "Growl of channels in the recent 30 days:

				%s";
				$str = '';
				$last = null;
				foreach (range(30, 1) as $day) {
					$day_unix = strtotime("$day days ago");
					$date = date('d.m.Y', $day_unix);
					$channels = $db->querySingle("SELECT COUNT(*) FROM channel WHERE date<{$day_unix}");
					
					if ($last) {
						$diff = $channels - $last;
						$diff = $diff>0? '+'.$diff : ($diff == 0? '=' : $diff);
						$str .= "[{$date}] {$channels} ($diff)\n";
					} else {
						$str .= "[{$date}] {$channels}\n";
					}
					$last = $channels;
				}
				$final .= sprintf($msg, $str)."\n";
				
				// months
				$msg = "Growl of channels in the recent 12 months:

				%s";
				$str = '';
				$last = null;
				foreach (range(12, 1) as $month) {
					$month_unix = strtotime("$month months ago");
					$date = date('d.m.Y', $month_unix);
					$channels = $db->querySingle("SELECT COUNT(*) FROM channel WHERE date<{$month_unix}");
					
					if ($last) {
						$diff = $channels - $last;
						$diff = $diff>0? '+'.$diff : ($diff == 0? '=' : $diff);
						$str .= "[{$date}] {$channels} ($diff)\n";
					} else {
						$str .= "[{$date}] {$channels}\n";
					}
					$last = $channels;
				}
				$final .= sprintf($msg, $str)."\n";
				
				$bot->send($final, ['chat_id' => $stats_id]);
			}
			
			else if (preg_match('#^/sql (?<sql>.+)#isu', $text, $match)) {
				extract($match);
				$q = $db->query($sql);
				$s = $q->fetchAll();
				$bot->indoc(json_encode($s, 480), 'sql.txt');
			}
			
			else if (preg_match('#^/(\s+|ev(al)?\s+)(?<code>.+)#isu', $text, $match)) {
				protect();
				$bot->action();
				\phgram\BotErrorHandler::$admin = $chat_id;
				\phgram\BotErrorHandler::$verbose = true;
				ob_start();
				try {
					eval($match['code']);
				} catch (Throwable $t) {
					echo $t;
				}
				$out = ob_get_contents();
				ob_end_clean();
				if ($out) {
					if (!(@$bot->sendMessage(['chat_id' => $chat_id, 'text' => $out, 'reply_to_message_id' => $bot->MessageID()])->ok)) {
						indoc($out);
					}
				}
			}
			
			else if (preg_match('#^/info (?<id>.+)?#', $text, $match)) {
				$bot->action();
				$id = $match['id'];
				
				mp($bot->bot_token);
				global $mp;
				try {
					$info = $mp->get_info($id);
					if ($info) {
						$type = $info['type'];
					}
				} catch (Throwable $t) {
					$type = null;
					$err = $t->getMessage();
				}
				
				if (!$type) {
					return $bot->send($err);
				} else if ($type == 'user') {
					$id = $info['bot_api_id'];
					$q = $db->query("select * from user where id like $id")->fetch();
					if (isset($q['id'])) {
						foreach ($q as &$val) {
							if ($val && is_string($val) && ($val[0] == '[' || $val[0] == '{')) {
								$val = json_decode($val, 1);
							}
						}
					}
					@$bot->send(json_encode($q, 480))->ok || $bot->indoc(json_encode($q, 480), 'db_info.json');
					@$bot->send(json_encode($info, 480))->ok || $bot->indoc(json_encode($info, 480), 'mtproto_info.json');
					$q = $db->query("select id from channel where adm like '%{$id}%' ");
					$chs = [];
					foreach ($q as $row) {
						$chat = @$bot->Chat($row['id']) ?? [];
						if ($chat) {
							$mention = (isset($chat['username'])? '@'.$chat['username'] : $chat['title']);
						} else {
							$mention = '';
						}
						$chs[] = "<pre>{$row['id']}</pre> ($mention)";
					}
					$bot->send($bot->mention($id).' is in: '.join(', ', $chs));
				} else if ($type == 'channel') {
					$id = $info['bot_api_id'];
					$q = $db->query("select * from channel where id like $id")->fetch();
					if (isset($q['id'])) {
						foreach ($q as &$val) {
							if (is_string($val) && is_array(json_decode($val, 1))) {
								$val = json_decode($val, 1);
							}
						}
					}
					@$bot->send(json_encode($q, 480))->ok || $bot->indoc(json_encode($q, 480), 'db_info.json');
					@$bot->send(json_encode($info, 480))->ok || $bot->indoc(json_encode($info, 480), 'mtproto_info.json');
				}
			} else if ($text == '/info' && $replied) {
				$bot->action();
				$id = $replied->forward_from->id ?? $replied->forward_from_chat->id ?? 0;
				
				mp($bot->bot_token);
				global $mp;
				try {
					$info = $mp->get_info($id);
					if ($info) {
						$type = $info['type'];
					}
				} catch (Throwable $t) {
					$type = null;
					$err = $t->getMessage();
				}
				
				if (!$type) {
					return $bot->send($err);
				} else if ($type == 'user') {
					$id = $info['bot_api_id'];
					$q = $db->query("select * from user where id like $id")->fetch();
					if (isset($q['id'])) {
						foreach ($q as &$val) {
							if ($val && is_string($val) && ($val[0] == '[' || $val[0] == '{')) {
								$val = json_decode($val, 1);
							}
						}
					}
					@$bot->send(json_encode($q, 480))->ok || $bot->indoc(json_encode($q, 480), 'db_info.json');
					@$bot->send(json_encode($info, 480))->ok || $bot->indoc(json_encode($info, 480), 'mtproto_info.json');
					$q = $db->query("select id from channel where adm like '%{$id}%' ");
					$chs = [];
					foreach ($q as $row) {
						$chat = @$bot->Chat($row['id']) ?? [];
						if ($chat) {
							$mention = (isset($chat['username'])? '@'.$chat['username'] : $chat['title']);
						} else {
							$mention = '';
						}
						$chs[] = "<pre>{$row['id']}</pre> ($mention)";
					}
					$bot->send($bot->mention($id).' is in: '.join(', ', $chs));
				} else if ($type == 'channel') {
					$id = $info['bot_api_id'];
					$q = $db->query("select * from channel where id like $id")->fetch();
					if (isset($q['id'])) {
						foreach ($q as &$val) {
							if (is_string($val) && ($val[0] == '[' || $val[0] == '{')) {
								$val = json_decode($val, 1);
							}
						}
					}
					@$bot->send(json_encode($q, 480))->ok || $bot->indoc(json_encode($q, 480), 'db_info.json');
					@$bot->send(json_encode($info, 480))->ok || $bot->indoc(json_encode($info, 480), 'mtproto_info.json');
				}
			}
		}
	}
}