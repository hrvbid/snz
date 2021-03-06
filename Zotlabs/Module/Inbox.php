<?php
namespace Zotlabs\Module;

// ActivityPub delivery endpoint


use App;
use Zotlabs\Web\HTTPSig;
use Zotlabs\Lib\ActivityStreams;
use Zotlabs\Lib\Activity;
use Zotlabs\Web\Controller;
use Zotlabs\Lib\Config;
use Zotlabs\Lib\PConfig;

class Inbox extends Controller {

	function post() {

		// This SHOULD be handled by the webserver, but in the RFC it is only indicated as
		// a SHOULD and not a MUST, so some webservers fail to reject appropriately.

		if ((array_key_exists('HTTP_ACCEPT',$_SERVER)) && ($_SERVER['HTTP_ACCEPT']) 
			&& (strpos($_SERVER['HTTP_ACCEPT'],'*') === false) && (! ActivityStreams::is_as_request())) {
			logger('unhandled accept header: ' . $_SERVER['HTTP_ACCEPT'],LOGGER_DEBUG);
			http_status_exit(406,'not acceptable');
		}

		$sys_disabled = ((Config::Get('system','disable_discover_tab') || Config::Get('system','disable_activitypub_discover_tab'))  ? true : false);

		logger('inbox_args: ' . print_r(App::$argv,true));
		
		$is_public = false;

		if (argc() == 1 || argv(1) === '[public]') {
			$is_public = true;
		}
		else {
			$c = channelx_by_nick(argv(1));
			if (! $c) {
				http_status_exit(410,'Gone');
			}
			$channels = [ $c ];
		}

		$data = file_get_contents('php://input');
		if (! $data) {
			return;
		}

		logger('inbox_activity: ' . jindent($data), LOGGER_DATA);

		$hsig = HTTPSig::verify($data);

		$AS = new ActivityStreams($data);
		if ($AS->is_valid() && $AS->type === 'Announce' && is_array($AS->obj)
			&& array_key_exists('object',$AS->obj) && array_key_exists('actor',$AS->obj)) {
			// This is a relayed/forwarded Activity (as opposed to a shared/boosted object)
			// Reparse the encapsulated Activity and use that instead
			logger('relayed activity',LOGGER_DEBUG);
			$AS = new ActivityStreams($AS->obj);
		}

		//logger('debug: ' . $AS->debug());

		if (! $AS->is_valid()) {
			if ($AS->deleted) {
				// process mastodon user deletion activities, but only if we can validate the signature
				if ($hsig['header_valid'] && $hsig['content_valid'] && $hsig['portable_id']) {
					logger('removing deleted actor');
					remove_all_xchan_resources($hsig['portable_id']);
				}
				else {
					logger('ignoring deleted actor', LOGGER_DEBUG, LOG_INFO);		
				}
			}
			return;
		}
		
		// $observer_hash in this case is the sender

		if ($hsig['header_valid'] && $hsig['content_valid'] && $hsig['portable_id']) {
			$observer_hash = $hsig['portable_id'];
		}
		else {
			$observer_hash = $AS->actor['id'];
		}

		if (! $observer_hash) {
			return;
		}

		$m = parse_url($observer_hash);
		if ($m && $m['scheme'] && $m['host']) {
			if (! check_siteallowed($m['scheme'] . '://' . $m['host'])) {
				http_status_exit(403,'Permission denied');
			}
		}

		if (is_array($AS->actor) && array_key_exists('id',$AS->actor)) {
			Activity::actor_store($AS->actor['id'],$AS->actor);
		}

		if (is_array($AS->obj) && ActivityStreams::is_an_actor($AS->obj['type'])) {
			Activity::actor_store($AS->obj['id'],$AS->obj);
		}

		if (is_array($AS->obj) && is_array($AS->obj['actor']) && array_key_exists('id',$AS->obj['actor']) && $AS->obj['actor']['id'] !== $AS->actor['id']) {
			Activity::actor_store($AS->obj['actor']['id'],$AS->obj['actor']);
		}

		$test = q("update hubloc set hubloc_connected = '%s' where hubloc_hash = '%s' and hubloc_network = 'activitypub'",
			dbesc(datetime_convert()),
			dbesc($observer_hash)
		);

		if ($is_public) {

			if ($AS->type === 'Follow' && $AS->obj && ActivityStreams::is_an_actor($AS->obj['type'])) {
				$channels = q("SELECT * from channel where channel_address = '%s' and channel_removed = 0 ",
					dbesc(basename($AS->obj['id']))
				);
			}
			else {
				// deliver to anybody following $AS->actor

				$channels = q("SELECT * from channel where channel_id in ( SELECT abook_channel from abook left join xchan on abook_xchan = xchan_hash WHERE xchan_network = 'activitypub' and xchan_hash = '%s' ) and channel_removed = 0 ",
					dbesc($observer_hash)
				);
				if (! $channels) {
					$channels = [];
				}

				$parent = $AS->parent_id;
				if ($parent) {
					// this is a comment - deliver to everybody who owns the parent
	 				$owners = q("SELECT * from channel where channel_id in ( SELECT uid from item where mid = '%s' ) ",
						dbesc($parent)
					);
					if ($owners) {
						$channels = array_merge($channels,$owners);
					}
				}
			}

			if ($channels === false) {
				$channels = [];
			}

			if (in_array(ACTIVITY_PUBLIC_INBOX,$AS->recips)) {

				// look for channels with send_stream = PERMS_PUBLIC

				$r = q("select * from channel where channel_id in (select uid from pconfig where cat = 'perm_limits' and k = 'send_stream' and v = '1' ) and channel_removed = 0 ");
				if ($r) {
					$channels = array_merge($channels,$r);
				}

				if (! $sys_disabled) {
					$channels[] = get_sys_channel();
				}

			}

		}

		if (! $channels) {
			logger('no deliveries on this site');
			return;
		}

		$saved_recips = [];
		foreach ( [ 'to', 'cc', 'audience' ] as $x ) {
			if (array_key_exists($x,$AS->data)) {
				$saved_recips[$x] = $AS->data[$x];
			}
		}
		$AS->set_recips($saved_recips);


		foreach ($channels as $channel) {

			if (! PConfig::Get($channel['channel_id'],'system','activitypub',true)) {
				continue;
			}
			
			logger('inbox_channel: ' . $channel['channel_address'],LOGGER_DEBUG);

			switch ($AS->type) {
				case 'Follow':
					if ($AS->obj && ActivityStreams::is_an_actor($AS->obj['type'])) {
						// do follow activity
						Activity::follow($channel,$AS);
					}
					break;
				case 'Join':
					if ($AS->obj && $AS->obj['type'] === 'Group') {
						// do follow activity
						Activity::follow($channel,$AS);
					}
					break;
				case 'Accept':
					if ($AS->obj && $AS->obj['type'] === 'Follow') {
						// do follow activity
						Activity::follow($channel,$AS);
					}
					break;

				case 'Reject':

				default:
					break;

			}

			// These activities require permissions		

			$item = null;

			switch ($AS->type) {
				case 'Update':
					if (is_array($AS->obj) && array_key_exists('type',$AS->obj) && ActivityStreams::is_an_actor($AS->obj['type'])) {
						// pretend this is an old cache entry to force an update of all the actor details
						$AS->obj['cached'] = true;
						$AS->obj['updated'] = datetime_convert('UTC','UTC','1980-01-01', ATOM_TIME);
						Activity::actor_store($AS->obj['id'],$AS->obj);
						break;
					}
				case 'Create':
				case 'Like':
				case 'Dislike':
				case 'Announce':
				case 'Accept':
				case 'Reject':
				case 'TentativeAccept':
				case 'TentativeReject':
				case 'Add':
				case 'Arrive':
				case 'Block':
				case 'Flag':
				case 'Ignore':
				case 'Invite':
				case 'Listen':
				case 'Move':
				case 'Offer':
				case 'Question':
				case 'Read':
				case 'Travel':
				case 'View':
				case 'emojiReaction':
				case 'EmojiReaction':
					// These require a resolvable object structure
					if (is_array($AS->obj)) {
						$item = Activity::decode_note($AS);
					}
					else {
						logger('unresolved object: ' . print_r($AS->obj,true));
					}
					break;
				case 'Undo':
					if ($AS->obj && $AS->obj['type'] === 'Follow') {
						// do unfollow activity
						Activity::unfollow($channel,$AS);
						break;
					}
				case 'Leave':
					if ($AS->obj && $AS->obj['type'] === 'Group') {
						// do unfollow activity
						Activity::unfollow($channel,$AS);
						break;
					}
				case 'Tombstone':
				case 'Delete':
					Activity::drop($channel,$observer_hash,$AS);
					break;

				case 'Move':
					if($observer_hash && $observer_hash === $AS->actor && Activity::is_an_actor($AS->obj) && Activity::is_an_actor($AS->tgt)) {
						ActivityPub::move($AS->obj,$AS->tgt);
					}
					break;
				case 'Add':
				case 'Remove':
				default:
					break;

			}

			if ($item) {
				logger('parsed_item: ' . print_r($item,true),LOGGER_DATA);
				Activity::store($channel,$observer_hash,$AS,$item);
			}

		}

		http_status_exit(200,'OK');
	}

	function get() {

	}

}



