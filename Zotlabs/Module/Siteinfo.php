<?php
namespace Zotlabs\Module;

use Zotlabs\Lib\System;
use Zotlabs\Lib\Config;
use Zotlabs\Web\Controller;

class Siteinfo extends Controller {

	function init() {
		if (argv(1) === 'json' || $_REQUEST['module_format'] === 'json') {
			$data = get_site_info();
			json_return_and_die($data);
		}
	}
		
	function get() {

		$federated = 'Zot6';
		if (Config::get('system','activitypub',true)) {
			$federated .= ', ActivityPub';
		}


		call_hooks('federated_transports',$federated);
	
		$siteinfo = replace_macros(get_markup_template('siteinfo.tpl'),
			[ 
				'$title' => t('About this site'),
				'$url' => z_root(),
				'$sitenametxt' => t('Site Name'),
				'$sitename' => System::get_site_name(),
				'$headline' => t('Site Information'),
				'$site_about' => bbcode(get_config('system','siteinfo')),
				'$admin_headline' => t('Administrator'),
				'$admin_about' => bbcode(get_config('system','admininfo')),
				'$terms' => t('Terms of Service'),
				'$prj_header' => t('Software and Project information'),
				'$prj_name' => t('This site is powered by $Projectname'),
				'$prj_transport' => t('Federated and decentralised networking and identity services provided by Zot'),
				'$transport_link' => '<a href="https://zotlabs.com">https://zotlabs.com</a>',

				'$ebs' => System::ebs(),
				'$additional_text' => t('Federated transport protocols:'),
				'$additional_fed' => $federated,
				'$prj_version' => ((get_config('system','hidden_version_siteinfo')) ? '' : sprintf( t('Version %s'), System::get_project_version())),
				'$prj_linktxt' => t('Project homepage'),
				'$prj_srctxt' => t('Developer homepage'),
				'$prj_link' => System::get_project_link(),
				'$prj_src' => System::get_project_srclink(),
				'$prj_icon' => System::get_project_icon(),
			]
		);

		call_hooks('about_hook', $siteinfo); 	

		return $siteinfo;

	}

	
}
