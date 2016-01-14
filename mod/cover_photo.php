<?php

/* @file cover_photo.php
   @brief Module-file with functions for handling of profile-photos

*/

require_once('include/photo/photo_driver.php');
require_once('include/identity.php');



/* @brief Initalize the cover-photo edit view
 *
 * @param $a Current application
 * @return void
 *
 */

function cover_photo_init(&$a) {

	if(! local_channel()) {
		return;
	}

	$channel = $a->get_channel();
	profile_load($a,$channel['channel_address']);

}

/* @brief Evaluate posted values
 *
 * @param $a Current application
 * @return void
 *
 */

function cover_photo_post(&$a) {

	if(! local_channel()) {
		return;
	}
	
	check_form_security_token_redirectOnErr('/cover_photo', 'cover_photo');
        
	if((x($_POST,'cropfinal')) && ($_POST['cropfinal'] == 1)) {

		// phase 2 - we have finished cropping

		if(argc() != 2) {
			notice( t('Image uploaded but image cropping failed.') . EOL );
			return;
		}

		$image_id = argv(1);

		if(substr($image_id,-2,1) == '-') {
			$scale = substr($image_id,-1,1);
			$image_id = substr($image_id,0,-2);
		}
			

		$srcX = $_POST['xstart'];
		$srcY = $_POST['ystart'];
		$srcW = $_POST['xfinal'] - $srcX;
		$srcH = $_POST['yfinal'] - $srcY;

		$r = q("SELECT * FROM photo WHERE resource_id = '%s' AND uid = %d AND scale = 0 LIMIT 1",
			dbesc($image_id),
			intval(local_channel())
		);

		if($r) {

			$base_image = $r[0];
			$base_image['data'] = (($r[0]['os_storage']) ? @file_get_contents($base_image['data']) : dbunescbin($base_image['data']));
		
			$im = photo_factory($base_image['data'], $base_image['type']);
			if($im->is_valid()) {

				$g = q("select width, height from photo where resource_id = '%s' and uid = %d and scale = 3",
					dbesc($image_id),
					intval(local_channel())
				);

				// scale these numbers to the original photo instead of the scaled photo we operated on

				$scaled_width = $g[0]['width'];
				$scaled_height = $g[0]['height'];

				if((! $scaled_width) || (! $scaled_height)) {
					logger('potential divide by zero scaling cover photo');
					return;
				}

				$orig_srcx = ( $r[0]['width'] / $scaled_width ) * $srcX;
				$orig_srcy = ( $r[0]['height'] / $scaled_height ) * $srcY;
 				$orig_srcw = ( $srcW / $scaled_width ) * $r[0]['width'];
 				$orig_srch = ( $srcH / $scaled_height ) * $r[0]['height'];

				$im->cropImageRect(1200,435,$orig_srcx, $orig_srcy, $orig_srcw, $orig_srch);

				$aid = get_account_id();

				$p = array('aid' => $aid, 'uid' => local_channel(), 'resource_id' => $base_image['resource_id'],
					'filename' => $base_image['filename'], 'album' => t('Profile Photos'));

				$p['scale'] = 7;
				$p['photo_usage'] = PHOTO_COVER;

				$r1 = $im->save($p);

				$im->doScaleImage(850,310);
				$p['scale'] = 8;

				$r2 = $im->save($p);
			
				if($r1 === false || $r2 === false) {
					// if one failed, delete them all so we can start over.
					notice( t('Image resize failed.') . EOL );
					$x = q("delete from photo where resource_id = '%s' and uid = %d and scale >= 7 ",
						dbesc($base_image['resource_id']),
						local_channel()
					);
					return;
				}

				$channel = $a->get_channel();


			}
			else
				notice( t('Unable to process image') . EOL);
		}

		goaway($a->get_baseurl() . '/profiles');
		return; // NOTREACHED
	}


	$hash = photo_new_resource();
	$smallest = 0;

	require_once('include/attach.php');

	$res = attach_store($a->get_channel(), get_observer_hash(), '', array('album' => t('Profile Photos'), 'hash' => $hash));

	logger('attach_store: ' . print_r($res,true));

	if($res && intval($res['data']['is_photo'])) {
		$i = q("select * from photo where resource_id = '%s' and uid = %d and scale = 0",
			dbesc($hash),
			intval(local_channel())
		);

		if(! $i) {
			notice( t('Image upload failed.') . EOL );
			return;
		}
		$os_storage = false;

		foreach($i as $ii) {
			$smallest = intval($ii['scale']);
			$os_storage = intval($ii['os_storage']);
			$imagedata = $ii['data'];
			$filetype = $ii['type'];

		}
	}

	$imagedata = (($os_storage) ? @file_get_contents($imagedata) : $imagedata);
	$ph = photo_factory($imagedata, $filetype);

	if(! $ph->is_valid()) {
		notice( t('Unable to process image.') . EOL );
		return;
	}

	return cover_photo_crop_ui_head($a, $ph, $hash, $smallest);
	
}

function send_cover_photo_activity($channel,$photo,$profile) {

	// for now only create activities for the default profile

	if(! intval($profile['is_default']))
		return;

	$arr = array();
	$arr['item_thread_top'] = 1;
	$arr['item_origin'] = 1;
	$arr['item_wall'] = 1;
	$arr['obj_type'] = ACTIVITY_OBJ_PHOTO;
	$arr['verb'] = ACTIVITY_UPDATE;

	$arr['object'] = json_encode(array(
		'type' => $arr['obj_type'],
		'id' => z_root() . '/photo/profile/l/' . $channel['channel_id'],
		'link' => array('rel' => 'photo', 'type' => $photo['type'], 'href' => z_root() . '/photo/profile/l/' . $channel['channel_id'])
	));

	if(stripos($profile['gender'],t('female')) !== false)
		$t = t('%1$s updated her %2$s');
	elseif(stripos($profile['gender'],t('male')) !== false)
		$t = t('%1$s updated his %2$s');
	else
		$t = t('%1$s updated their %2$s');

	$ptext = '[zrl=' . z_root() . '/photos/' . $channel['channel_address'] . '/image/' . $photo['resource_id'] . ']' . t('profile photo') . '[/zrl]';

	$ltext = '[zrl=' . z_root() . '/profile/' . $channel['channel_address'] . ']' . '[zmg=150x150]' . z_root() . '/photo/' . $photo['resource_id'] . '-4[/zmg][/zrl]'; 

	$arr['body'] = sprintf($t,$channel['channel_name'],$ptext) . "\n\n" . $ltext;

	$acl = new AccessList($channel);
	$x = $acl->get();
	$arr['allow_cid'] = $x['allow_cid'];

	$arr['allow_gid'] = $x['allow_gid'];
	$arr['deny_cid'] = $x['deny_cid'];
	$arr['deny_gid'] = $x['deny_gid'];

	$arr['uid'] = $channel['channel_id'];
	$arr['aid'] = $channel['channel_account_id'];

	$arr['owner_xchan'] = $channel['channel_hash'];
	$arr['author_xchan'] = $channel['channel_hash'];

	post_activity_item($arr);


}


/* @brief Generate content of profile-photo view
 *
 * @param $a Current application
 * @return void
 *
 */


function cover_photo_content(&$a) {

	if(! local_channel()) {
		notice( t('Permission denied.') . EOL );
		return;
	}

	$channel = $a->get_channel();

	$newuser = false;

	if(argc() == 2 && argv(1) === 'new')
		$newuser = true;

	if(argv(1) === 'use') {
		if (argc() < 3) {
			notice( t('Permission denied.') . EOL );
			return;
		};
		
//		check_form_security_token_redirectOnErr('/cover_photo', 'cover_photo');
        
		$resource_id = argv(2);

		$r = q("SELECT id, album, scale FROM photo WHERE uid = %d AND resource_id = '%s' ORDER BY scale ASC",
			intval(local_channel()),
			dbesc($resource_id)
		);
		if(! $r) {
			notice( t('Photo not available.') . EOL );
			return;
		}
		$havescale = false;
		foreach($r as $rr) {
			if($rr['scale'] == 7)
				$havescale = true;
		}

		$r = q("SELECT `data`, `type`, resource_id, os_storage FROM photo WHERE id = %d and uid = %d limit 1",
			intval($r[0]['id']),
			intval(local_channel())

		);
		if(! $r) {
			notice( t('Photo not available.') . EOL );
			return;
		}

		if(intval($r[0]['os_storage']))
			$data = @file_get_contents($r[0]['data']);
		else
			$data = dbunescbin($r[0]['data']); 

		$ph = photo_factory($data, $r[0]['type']);
		$smallest = 0;
		if($ph->is_valid()) {
			// go ahead as if we have just uploaded a new photo to crop
			$i = q("select resource_id, scale from photo where resource_id = '%s' and uid = %d and scale = 0",
				dbesc($r[0]['resource_id']),
				intval(local_channel())
			);

			if($i) {
				$hash = $i[0]['resource_id'];
				foreach($i as $ii) {
					$smallest = intval($ii['scale']);
				}
            }
        }
 
		cover_photo_crop_ui_head($a, $ph, $hash, $smallest);
	}


	if(! x($a->data,'imagecrop')) {

		$tpl = get_markup_template('cover_photo.tpl');

		$o .= replace_macros($tpl,array(
			'$user' => $a->channel['channel_address'],
			'$lbl_upfile' => t('Upload File:'),
			'$lbl_profiles' => t('Select a profile:'),
			'$title' => t('Upload Cover Photo'),
			'$submit' => t('Upload'),
			'$profiles' => $profiles,
			'$form_security_token' => get_form_security_token("cover_photo"),
// FIXME - yuk  
			'$select' => sprintf('%s %s', t('or'), ($newuser) ? '<a href="' . $a->get_baseurl() . '">' . t('skip this step') . '</a>' : '<a href="'. $a->get_baseurl() . '/photos/' . $a->channel['channel_address'] . '">' . t('select a photo from your photo albums') . '</a>')
		));
		
		call_hooks('cover_photo_content_end', $o);
		
		return $o;
	}
	else {
		$filename = $a->data['imagecrop'] . '-3';
		$resolution = 3;
		$tpl = get_markup_template("cropcover.tpl");
		$o .= replace_macros($tpl,array(
			'$filename' => $filename,
			'$profile' => intval($_REQUEST['profile']),
			'$resource' => $a->data['imagecrop'] . '-3',
			'$image_url' => $a->get_baseurl() . '/photo/' . $filename,
			'$title' => t('Crop Image'),
			'$desc' => t('Please adjust the image cropping for optimum viewing.'),
			'$form_security_token' => get_form_security_token("cover_photo"),
			'$done' => t('Done Editing')
		));
		return $o;
	}

	return; // NOTREACHED
}

/* @brief Generate the UI for photo-cropping
 *
 * @param $a Current application
 * @param $ph Photo-Factory
 * @return void
 *
 */



function cover_photo_crop_ui_head(&$a, $ph, $hash, $smallest){

	$max_length = get_config('system','max_image_length');
	if(! $max_length)
		$max_length = MAX_IMAGE_LENGTH;
	if($max_length > 0)
		$ph->scaleImage($max_length);

	$width  = $ph->getWidth();
	$height = $ph->getHeight();

	if($width < 300 || $height < 300) {
		$ph->scaleImageUp(240);
		$width  = $ph->getWidth();
		$height = $ph->getHeight();
	}


	$a->data['imagecrop'] = $hash;
	$a->data['imagecrop_resolution'] = $smallest;
	$a->page['htmlhead'] .= replace_macros(get_markup_template("crophead.tpl"), array());
	return;
}

