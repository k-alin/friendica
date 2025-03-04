<?php
/**
 * @copyright Copyright (C) 2010-2022, the Friendica project
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

use Friendica\App;
use Friendica\Content\ForumManager;
use Friendica\Content\Text\BBCode;
use Friendica\Core\Cache\Enum\Duration;
use Friendica\Core\Hook;
use Friendica\Database\DBA;
use Friendica\DI;
use Friendica\Model\Contact;
use Friendica\Model\Group;
use Friendica\Model\Notification;
use Friendica\Model\Post;
use Friendica\Model\Verb;
use Friendica\Protocol\Activity;
use Friendica\Util\DateTimeFormat;
use Friendica\Util\Proxy;
use Friendica\Util\Temporal;
use Friendica\Util\XML;

/**
 * Outputs the counts and the lists of various notifications
 *
 * The output format can be controlled via the GET parameter 'format'. It can be
 * - xml (deprecated legacy default)
 * - json (outputs JSONP with the 'callback' GET parameter)
 *
 * Expected JSON structure:
 * {
 *        "result": {
 *            "intro": 0,
 *            "mail": 0,
 *            "net": 0,
 *            "home": 0,
 *            "register": 0,
 *            "all-events": 0,
 *            "all-events-today": 0,
 *            "events": 0,
 *            "events-today": 0,
 *            "birthdays": 0,
 *            "birthdays-today": 0,
 *            "groups": [ ],
 *            "forums": [ ],
 *            "notification": 0,
 *            "notifications": [ ],
 *            "sysmsgs": {
 *                "notice": [ ],
 *                "info": [ ]
 *            }
 *        }
 *    }
 *
 * @param App $a The Friendica App instance
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function ping_init(App $a)
{
	$format = 'xml';

	if (isset($_GET['format']) && $_GET['format'] == 'json') {
		$format = 'json';
	}

	$regs          = [];
	$notifications = [];

	$intro_count    = 0;
	$mail_count     = 0;
	$home_count     = 0;
	$network_count  = 0;
	$register_count = 0;
	$sysnotify_count = 0;
	$groups_unseen  = [];
	$forums_unseen  = [];

	$all_events       = 0;
	$all_events_today = 0;
	$events           = 0;
	$events_today     = 0;
	$birthdays        = 0;
	$birthdays_today  = 0;

	$data = [];
	$data['intro']    = $intro_count;
	$data['mail']     = $mail_count;
	$data['net']      = $network_count;
	$data['home']     = $home_count;
	$data['register'] = $register_count;

	$data['all-events']       = $all_events;
	$data['all-events-today'] = $all_events_today;
	$data['events']           = $events;
	$data['events-today']     = $events_today;
	$data['birthdays']        = $birthdays;
	$data['birthdays-today']  = $birthdays_today;

	if (local_user()) {
		// Different login session than the page that is calling us.
		if (!empty($_GET['uid']) && intval($_GET['uid']) != local_user()) {
			$data = ['result' => ['invalid' => 1]];

			if ($format == 'json') {
				if (isset($_GET['callback'])) {
					// JSONP support
					header("Content-type: application/javascript");
					echo $_GET['callback'] . '(' . json_encode($data) . ')';
				} else {
					header("Content-type: application/json");
					echo json_encode($data);
				}
			} else {
				header("Content-type: text/xml");
				echo XML::fromArray($data, $xml);
			}
			exit();
		}

		$notifications = ping_get_notifications(local_user());

		$condition = ["`unseen` AND `uid` = ? AND NOT `origin` AND (`vid` != ? OR `vid` IS NULL)",
			local_user(), Verb::getID(Activity::FOLLOW)];
		$items = Post::selectForUser(local_user(), ['wall', 'uid', 'uri-id'], $condition, ['limit' => 1000]);
		if (DBA::isResult($items)) {
			$items_unseen = Post::toArray($items, false);
			$arr = ['items' => $items_unseen];
			Hook::callAll('network_ping', $arr);

			foreach ($items_unseen as $item) {
				if ($item['wall']) {
					$home_count++;
				} else {
					$network_count++;
				}
			}
		}
		DBA::close($items);

		if ($network_count) {
			// Find out how unseen network posts are spread across groups
			$group_counts = Group::countUnseen();
			if (DBA::isResult($group_counts)) {
				foreach ($group_counts as $group_count) {
					if ($group_count['count'] > 0) {
						$groups_unseen[] = $group_count;
					}
				}
			}

			$forum_counts = ForumManager::countUnseenItems();
			if (DBA::isResult($forum_counts)) {
				foreach ($forum_counts as $forum_count) {
					if ($forum_count['count'] > 0) {
						$forums_unseen[] = $forum_count;
					}
				}
			}
		}

		$intros1 = DBA::toArray(DBA::p(
			"SELECT  `intro`.`id`, `intro`.`datetime`,
			`contact`.`name`, `contact`.`url`, `contact`.`photo`
			FROM `intro` INNER JOIN `contact` ON `intro`.`suggest-cid` = `contact`.`id`
			WHERE `intro`.`uid` = ? AND NOT `intro`.`blocked` AND NOT `intro`.`ignore` AND `intro`.`suggest-cid` != 0",
			local_user()
		));
		$intros2 = DBA::toArray(DBA::p(
			"SELECT `intro`.`id`, `intro`.`datetime`,
			`contact`.`name`, `contact`.`url`, `contact`.`photo`
			FROM `intro` INNER JOIN `contact` ON `intro`.`contact-id` = `contact`.`id`
			WHERE `intro`.`uid` = ? AND NOT `intro`.`blocked` AND NOT `intro`.`ignore` AND `intro`.`contact-id` != 0 AND (`intro`.`suggest-cid` = 0 OR `intro`.`suggest-cid` IS NULL)",
			local_user()
		));

		$intro_count = count($intros1) + count($intros2);
		$intros = $intros1 + $intros2;

		$myurl = DI::baseUrl() . '/profile/' . $a->getLoggedInUserNickname();
		$mail_count = DBA::count('mail', ["`uid` = ? AND NOT `seen` AND `from-url` != ?", local_user(), $myurl]);

		if (intval(DI::config()->get('config', 'register_policy')) === \Friendica\Module\Register::APPROVE && $a->isSiteAdmin()) {
			$regs = Friendica\Model\Register::getPending();

			if (DBA::isResult($regs)) {
				$register_count = count($regs);
			}
		}

		$cachekey = "ping_init:".local_user();
		$ev = DI::cache()->get($cachekey);
		if (is_null($ev)) {
			$ev = DBA::selectToArray('event', ['type', 'start'],
				["`uid` = ? AND `start` < ? AND `finish` > ? AND NOT `ignore`",
				local_user(), DateTimeFormat::utc('now + 7 days'), DateTimeFormat::utcNow()]);
			if (DBA::isResult($ev)) {
				DI::cache()->set($cachekey, $ev, Duration::HOUR);
			}
		}

		if (DBA::isResult($ev)) {
			$all_events = count($ev);

			if ($all_events) {
				$str_now = DateTimeFormat::localNow('Y-m-d');
				foreach ($ev as $x) {
					$bd = false;
					if ($x['type'] === 'birthday') {
						$birthdays ++;
						$bd = true;
					} else {
						$events ++;
					}
					if (DateTimeFormat::local($x['start'], 'Y-m-d') === $str_now) {
						$all_events_today ++;
						if ($bd) {
							$birthdays_today ++;
						} else {
							$events_today ++;
						}
					}
				}
			}
		}

		$data['intro']    = $intro_count;
		$data['mail']     = $mail_count;
		$data['net']      = ($network_count < 1000) ? $network_count : '999+';
		$data['home']     = ($home_count < 1000) ? $home_count : '999+';
		$data['register'] = $register_count;

		$data['all-events']       = $all_events;
		$data['all-events-today'] = $all_events_today;
		$data['events']           = $events;
		$data['events-today']     = $events_today;
		$data['birthdays']        = $birthdays;
		$data['birthdays-today']  = $birthdays_today;

		if (DBA::isResult($notifications)) {
			foreach ($notifications as $notif) {
				if ($notif['seen'] == 0) {
					$sysnotify_count ++;
				}
			}
		}

		// merge all notification types in one array
		if (DBA::isResult($intros)) {
			foreach ($intros as $intro) {
				$notif = [
					'id'      => 0,
					'href'    => DI::baseUrl() . '/notifications/intros/' . $intro['id'],
					'name'    => BBCode::convert($intro['name']),
					'url'     => $intro['url'],
					'photo'   => $intro['photo'],
					'date'    => $intro['datetime'],
					'seen'    => false,
					'message' => DI::l10n()->t('{0} wants to be your friend'),
				];
				$notifications[] = $notif;
			}
		}

		if (DBA::isResult($regs)) {
			if (count($regs) <= 1 || DI::pConfig()->get(local_user(), 'system', 'detailed_notif')) {
				foreach ($regs as $reg) {
					$notif = [
						'id'      => 0,
						'href'    => DI::baseUrl() . '/admin/users/pending',
						'name'    => $reg['name'],
						'url'     => $reg['url'],
						'photo'   => $reg['micro'],
						'date'    => $reg['created'],
						'seen'    => false,
						'message' => DI::l10n()->t('{0} requested registration'),
					];
					$notifications[] = $notif;
				}
			} else {
				$notif = [
					'id'      => 0,
					'href'    => DI::baseUrl() . '/admin/users/pending',
					'name'    => $regs[0]['name'],
					'url'     => $regs[0]['url'],
					'photo'   => $regs[0]['micro'],
					'date'    => $regs[0]['created'],
					'seen'    => false,
					'message' => DI::l10n()->t('{0} and %d others requested registration', count($regs) - 1),
				];
				$notifications[] = $notif;
			}
		}

		// sort notifications by $[]['date']
		$sort_function = function ($a, $b) {
			$adate = strtotime($a['date']);
			$bdate = strtotime($b['date']);

			// Unseen messages are kept at the top
			// The value 31536000 means one year. This should be enough :-)
			if (!$a['seen']) {
				$adate += 31536000;
			}
			if (!$b['seen']) {
				$bdate += 31536000;
			}

			if ($adate == $bdate) {
				return 0;
			}
			return ($adate < $bdate) ? 1 : -1;
		};
		usort($notifications, $sort_function);

		array_walk($notifications, function (&$notification) {
			$notification['photo']     = Contact::getAvatarUrlForUrl($notification['url'], local_user(), Proxy::SIZE_MICRO);
			$notification['timestamp'] = DateTimeFormat::local($notification['date']);
			$notification['date']      = Temporal::getRelativeDate($notification['date']);
		});
	}

	$sysmsgs = [];
	$sysmsgs_info = [];

	if (!empty($_SESSION['sysmsg'])) {
		$sysmsgs = $_SESSION['sysmsg'];
		unset($_SESSION['sysmsg']);
	}

	if (!empty($_SESSION['sysmsg_info'])) {
		$sysmsgs_info = $_SESSION['sysmsg_info'];
		unset($_SESSION['sysmsg_info']);
	}

	if ($format == 'json') {
		$notification_count = $sysnotify_count + $intro_count + $register_count;
		
		$data['groups'] = $groups_unseen;
		$data['forums'] = $forums_unseen;
		$data['notification'] = ($notification_count < 50) ? $notification_count : '49+';
		$data['notifications'] = $notifications;
		$data['sysmsgs'] = [
			'notice' => $sysmsgs,
			'info' => $sysmsgs_info
		];

		$json_payload = json_encode(["result" => $data]);

		if (isset($_GET['callback'])) {
			// JSONP support
			header("Content-type: application/javascript");
			echo $_GET['callback'] . '(' . $json_payload . ')';
		} else {
			header("Content-type: application/json");
			echo $json_payload;
		}
	} else {
		// Legacy slower XML format output
		$data = ping_format_xml_data($data, $sysnotify_count, $notifications, $sysmsgs, $sysmsgs_info, $groups_unseen, $forums_unseen);

		header("Content-type: text/xml");
		echo XML::fromArray(["result" => $data], $xml);
	}

	exit();
}

/**
 * Retrieves the notifications array for the given user ID
 *
 * @param int $uid User id
 * @return array Associative array of notifications
 * @throws \Friendica\Network\HTTPException\InternalServerErrorException
 */
function ping_get_notifications($uid)
{
	$result  = [];
	$offset  = 0;
	$seen    = false;
	$seensql = "NOT";
	$order   = "DESC";
	$quit    = false;

	do {
		$r = DBA::toArray(DBA::p(
			"SELECT `notify`.*, `post`.`visible`, `post`.`deleted`
			FROM `notify` LEFT JOIN `post` ON `post`.`uri-id` = `notify`.`uri-id`
			WHERE `notify`.`uid` = ? AND `notify`.`msg` != ''
			AND NOT (`notify`.`type` IN (?, ?))
			AND $seensql `notify`.`seen` ORDER BY `notify`.`date` $order LIMIT ?, 50",
			$uid,
			Notification\Type::INTRO,
			Notification\Type::MAIL,
			$offset
		));

		if (!$r && !$seen) {
			$seen = true;
			$seensql = "";
			$order = "DESC";
			$offset = 0;
		} elseif (!$r) {
			$quit = true;
		} else {
			$offset += 50;
		}

		foreach ($r as $notification) {
			if (is_null($notification["visible"])) {
				$notification["visible"] = true;
			}

			if (is_null($notification["deleted"])) {
				$notification["deleted"] = 0;
			}

			if ($notification["msg_cache"]) {
				$notification["name"] = $notification["name_cache"];
				$notification["message"] = $notification["msg_cache"];
			} else {
				$notification["name"] = strip_tags(BBCode::convert($notification["name"]));
				$notification["message"] = \Friendica\Navigation\Notifications\Entity\Notify::formatMessage($notification["name"], BBCode::toPlaintext($notification["msg"]));

				// @todo Replace this with a call of the Notify model class
				DBA::update('notify', ['name_cache' => $notification["name"], 'msg_cache' => $notification["message"]], ['id' => $notification["id"]]);
			}

			$notification["href"] = DI::baseUrl() . "/notification/" . $notification["id"];

			if ($notification["visible"]
				&& !$notification["deleted"]
				&& empty($result['p:' . $notification['parent']])
			) {
				// Should we condense the notifications or show them all?
				if (($notification['verb'] != Activity::POST) || DI::pConfig()->get(local_user(), 'system', 'detailed_notif')) {
					$result[] = $notification;
				} else {
					$result['p:' . $notification['parent']] = $notification;
				}
			}
		}
	} while ((count($result) < 50) && !$quit);

	return($result);
}

/**
 * Backward-compatible XML formatting for ping.php output
 * @deprecated
 *
 * @param array $data            The initial ping data array
 * @param int   $sysnotify_count Number of unseen system notifications
 * @param array $notifs          Complete list of notification
 * @param array $sysmsgs         List of system notice messages
 * @param array $sysmsgs_info    List of system info messages
 * @param array $groups_unseen   List of unseen group items
 * @param array $forums_unseen   List of unseen forum items
 *
 * @return array XML-transform ready data array
 */
function ping_format_xml_data($data, $sysnotify_count, $notifs, $sysmsgs, $sysmsgs_info, $groups_unseen, $forums_unseen)
{
	$notifications = [];
	foreach ($notifs as $key => $notif) {
		$notifications[$key . ':note'] = $notif['message'];

		$notifications[$key . ':@attributes'] = [
			'id'        => $notif['id'],
			'href'      => $notif['href'],
			'name'      => $notif['name'],
			'url'       => $notif['url'],
			'photo'     => $notif['photo'],
			'date'      => $notif['date'],
			'seen'      => $notif['seen'],
			'timestamp' => $notif['timestamp']
		];
	}

	$sysmsg = [];
	foreach ($sysmsgs as $key => $m) {
		$sysmsg[$key . ':notice'] = $m;
	}
	foreach ($sysmsgs_info as $key => $m) {
		$sysmsg[$key . ':info'] = $m;
	}

	$data['notif'] = $notifications;
	$data['@attributes'] = ['count' => $sysnotify_count + $data['intro'] + $data['mail'] + $data['register']];
	$data['sysmsgs'] = $sysmsg;

	if ($data['register'] == 0) {
		unset($data['register']);
	}

	$groups = [];
	if (count($groups_unseen)) {
		foreach ($groups_unseen as $key => $item) {
			$groups[$key . ':group'] = $item['count'];
			$groups[$key . ':@attributes'] = ['id' => $item['id']];
		}
		$data['groups'] = $groups;
	}

	$forums = [];
	if (count($forums_unseen)) {
		foreach ($forums_unseen as $key => $item) {
			$forums[$key . ':forum'] = $item['count'];
			$forums[$key . ':@attributes'] = ['id' => $item['id']];
		}
		$data['forums'] = $forums;
	}

	return $data;
}
