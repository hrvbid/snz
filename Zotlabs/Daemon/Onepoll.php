<?php /** @file */

namespace Zotlabs\Daemon;

use Zotlabs\Lib\Libzot;

require_once('include/socgraph.php');


class Onepoll {

	static public function run($argc,$argv) {

		logger('onepoll: start');
	
		if(($argc > 1) && (intval($argv[1])))
			$contact_id = intval($argv[1]);

		if(! $contact_id) {
			logger('onepoll: no contact');
			return;
		}

		$d = datetime_convert();

		$contacts = q("SELECT abook.*, xchan.*, account.*
			FROM abook LEFT JOIN account on abook_account = account_id left join xchan on xchan_hash = abook_xchan 
			where abook_id = %d
			and abook_pending = 0 and abook_archived = 0 and abook_blocked = 0 and abook_ignored = 0
			AND (( account_flags = %d ) OR ( account_flags = %d )) limit 1",
			intval($contact_id),
			intval(ACCOUNT_OK),
			intval(ACCOUNT_UNVERIFIED)
		);	

		if(! $contacts) {
			logger('onepoll: abook_id not found: ' . $contact_id);
			return;
		}

		$contact = $contacts[0];

		$t = $contact['abook_updated'];

		$importer_uid = $contact['abook_channel'];
		
		$r = q("SELECT * from channel left join xchan on channel_hash = xchan_hash where channel_id = %d limit 1",
			intval($importer_uid)
		);

		if(! $r)
			return;

		$importer = $r[0];

		logger("onepoll: poll: ({$contact['id']}) IMPORTER: {$importer['xchan_name']}, CONTACT: {$contact['xchan_name']}");

		$last_update = ((($contact['abook_updated'] === $contact['abook_created']) || ($contact['abook_updated'] <= NULL_DATE))	
			? datetime_convert('UTC','UTC','now - 7 days')
			: datetime_convert('UTC','UTC',$contact['abook_updated'] . ' - 2 days')
		);

		if($contact['xchan_network'] === 'rss') {
			logger('onepoll: processing feed ' . $contact['xchan_name'], LOGGER_DEBUG);
			$alive = handle_feed($importer['channel_id'],$contact_id,$contact['xchan_hash']);
			if ($alive) {
				q("update abook set abook_connected = '%s' where abook_id = %d",
					dbesc(datetime_convert()),
					intval($contact['abook_id'])
				);
			}
			return;
		}
	
		if($contact['xchan_network'] !== 'zot6')
			return;

		// update permissions

		$x = Libzot::refresh($contact,$importer);

		$responded = false;
		$updated   = datetime_convert();
		$connected = datetime_convert();
		if(! $x) {
			// mark for death by not updating abook_connected, this is caught in include/poller.php
			q("update abook set abook_updated = '%s' where abook_id = %d",
				dbesc($updated),
				intval($contact['abook_id'])
			);
		}
		else {
			q("update abook set abook_updated = '%s', abook_connected = '%s' where abook_id = %d",
				dbesc($updated),
				dbesc($connected),
				intval($contact['abook_id'])
			);
			$responded = true;
		}

		if(! $responded)
			return;

		if(defined('USE_OUTBOX')) {

		// @fixme
		// This needs to be converted from zotfeed to ActivityStreams (such as an ActivityPub outbox).
		// The zotfeed has serious compatibility issues between Hubzilla and Zap.

		if($contact['xchan_connurl']) {
			$fetch_feed = true;
			$x = null;

			// They haven't given us permission to see their stream

			$can_view_stream = intval(get_abconfig($importer_uid,$contact['abook_xchan'],'their_perms','view_stream'));

			if(! $can_view_stream)
				$fetch_feed = false;

			// we haven't given them permission to send us their stream

			$can_send_stream = intval(get_abconfig($importer_uid,$contact['abook_xchan'],'my_perms','send_stream'));
			
			if(! $can_send_stream)
				$fetch_feed = false;

			if($fetch_feed) {

				if(strpos($contact['xchan_connurl'],z_root()) === 0) {
					// local channel - save a network fetch
					$c = channelx_by_hash($contact['xchan_hash']);
					if($c) {
						$x = [ 
							'success' => true, 
							'body' => json_encode( [ 
								'success' => true,
								'messages' => zot_feed($c['channel_id'], $importer['xchan_hash'], [ 'mindate' => $last_update ])
							])
						];
					}
				}
				else {
					// remote fetch	

					$feedurl = str_replace('/poco/','/zotfeed/',$contact['xchan_connurl']);		
					$feedurl .= '?f=&mindate=' . urlencode($last_update) . '&zid=' . $importer['channel_address'] . '@' . \App::get_hostname();
					$recurse = 0;
					$x = z_fetch_url($feedurl, false, $recurse, [ 'session' => true ]);
				}

				logger('feed_update: ' . print_r($x,true), LOGGER_DATA);
			}

			if(($x) && ($x['success'])) {
				$total = 0;
				logger('onepoll: feed update ' . $contact['xchan_name'] . ' ' . $feedurl);

				$j = json_decode($x['body'],true);
				if($j['success'] && $j['messages']) {
					foreach($j['messages'] as $message) {
						// process delivery here once we have parsed the AS
						logger('onepoll: feed_update: process_delivery: ' . print_r($results,true), LOGGER_DATA);
						$total ++;
					}
					logger("onepoll: $total messages processed");
				}
			}
		}
			

		} // end USE_OUTBOX

		// update the poco details for this connection

		if($contact['xchan_connurl']) {	
			$r = q("SELECT xlink_id from xlink 
				where xlink_xchan = '%s' and xlink_updated > %s - INTERVAL %s and xlink_static = 0 limit 1",
				intval($contact['xchan_hash']),
				db_utcnow(), db_quoteinterval('1 DAY')
			);
			if(! $r) {
				poco_load($contact['xchan_hash'],$contact['xchan_connurl']);
			}
		}

		return;
	}
}
