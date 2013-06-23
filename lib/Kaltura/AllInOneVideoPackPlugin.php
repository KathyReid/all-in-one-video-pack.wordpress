<?php

class Kaltura_AllInOneVideoPackPlugin
{
	public function __construct()
	{

	}

	public function init()
	{
		if (defined('MULTISITE') && defined('WP_ALLOW_MULTISITE') && WP_ALLOW_MULTISITE)
			add_action('network_admin_menu', $this->callback('networkAdminMenuAction'));

		if (!KalturaHelpers::getOption('kaltura_partner_id') &&
			!isset($_POST['submit']) &&
			!strpos($_SERVER['REQUEST_URI'], 'page=kaltura_options'))
		{
			add_action('admin_notices', $this->callback('adminWarning'));
			return;
		}

		// filters
		add_filter('comment_text', $this->callback('commentTextFilter'));
		add_filter('media_buttons_context', $this->callback('mediaButtonsContextFilter'));
		add_filter('media_upload_tabs', $this->callback('mediaUploadTabsFilter'));
		add_filter('mce_external_plugins', $this->callback('mceExternalPluginsFilter'));
		add_filter('tiny_mce_version', $this->callback('tinyMceVersionFilter'));

		// actions
		add_action('admin_menu', $this->callback('adminMenuAction'));
		add_action('wp_print_scripts', $this->callback('printScripts'));
		add_action('wp_enqueue_scripts', $this->callback('enqueueScripts'));
		add_action('wp_enqueue_styles', $this->callback('enqueueStyles'));
		add_action('admin_enqueue_scripts', $this->callback('adminEnqueueScripts'));

		// media upload actions
		add_action('media_upload_kaltura_upload', $this->callback('mediaUploadAction'));
		add_action('media_upload_kaltura_browse', $this->callback('mediaBrowseAction'));
		add_action('admin_print_scripts-media-upload-popup', $this->callback('mediaUploadPrintScriptsAction'));

		add_action('save_post', $this->callback('savePost'));
		add_action('wp_ajax_kaltura_ajax', $this->callback('executeLibraryController'));

		if (KalturaHelpers::videoCommentsEnabled())
			add_action('comment_form', $this->callback('commentFormAction'));

		add_shortcode('kaltura-widget', $this->callback('shortcodeHandler'));
	}

	private function callback($functionName)
	{
		return array($this, $functionName);
	}

	public function adminWarning()
	{
		echo "
		<div class='updated fade'><p><strong>".__('To complete the All in One Video Pack installation, <a href="'.get_option('siteurl').'/wp-admin/options-general.php?page=kaltura_options">you must get a Partner ID.</a>')."</strong></p></div>
		";
	}

	public function mceExternalPluginsFilter($content)
	{
		$pluginUrl = KalturaHelpers::getPluginUrl();
		$content['kaltura'] = $pluginUrl . '/tinymce/kaltura_tinymce.js?v'.KalturaHelpers::getPluginVersion();
		return $content;
	}

	public function tinyMceVersionFilter($content)
	{
		return $content . '_k'.KalturaHelpers::getPluginVersion();
	}

	public function adminMenuAction()
	{
		add_options_page('All in One Video', 'All in One Video', 8, 'kaltura_options', $this->callback('executeAdminController'));
		add_media_page('All in One Video', 'All in One Video', 8, 'kaltura_library', $this->callback('executeLibraryController'));
	}

	public function printScripts()
	{
		KalturaHelpers::addWPVersionJS();
	}

	public function enqueueStyles()
	{
	}

	public function enqueueScripts()
	{
		wp_enqueue_style('kaltura', KalturaHelpers::cssUrl('css/kaltura.css'));
		wp_enqueue_script('kaltura', KalturaHelpers::jsUrl('js/kaltura.js'));
		wp_enqueue_script('jquery');
	}

	public function adminEnqueueScripts()
	{
		wp_register_script('kaltura', KalturaHelpers::jsUrl('js/kaltura.js'));
		wp_register_script('kaltura-admin', KalturaHelpers::jsUrl('js/kaltura-admin.js'));
		wp_register_script('kaltura-player-selector', KalturaHelpers::jsUrl('js/kaltura-player-selector.js'));
		wp_register_script('kaltura-entry-status-checker', KalturaHelpers::jsUrl('js/kaltura-entry-status-checker.js'));
		wp_register_script('kaltura-editable-name', KalturaHelpers::jsUrl('js/kaltura-editable-name.js'));
		wp_register_script('kaltura-jquery-validate', KalturaHelpers::jsUrl('js/jquery.validate.min.js'));
		wp_register_style('kaltura-admin', KalturaHelpers::cssUrl('css/admin.css'));

		wp_enqueue_script('kaltura');
		wp_enqueue_style('kaltura');
		wp_enqueue_style('kaltura-admin');
	}

	function executeLibraryController()
	{
		if (!isset($_GET['kaction']))
			$_GET['kaction'] = 'library';
		$controller = new Kaltura_LibraryController();
		$controller->execute();
	}

	function executeAdminController()
	{
		$controller = new Kaltura_AdminController();
		$controller->execute();
	}

	public function commentTextFilter($content)
	{
		global $shortcode_tags;

		// we want to run our shortcode and not all
		$shortcode_tags_backup = $shortcode_tags;
		$shortcode_tags = array();

		add_shortcode('kaltura-widget', array($this, 'shortcodeHandler'));
		$content = do_shortcode($content);

		// restore the original array
		$shortcode_tags = $shortcode_tags_backup;

		return $content;
	}

	public function mediaButtonsContextFilter($content)
	{
		global $post_ID, $temp_ID;
		$uploading_iframe_ID = (int) (0 == $post_ID ? $temp_ID : $post_ID);
		$media_upload_iframe_src = "media-upload.php?post_id=$uploading_iframe_ID";
		$kaltura_iframe_src = apply_filters('kaltura_iframe_src', "$media_upload_iframe_src&amp;tab=kaltura_upload");
		$kaltura_browse_iframe_src = apply_filters('kaltura_iframe_src', "$media_upload_iframe_src&amp;tab=kaltura_browse");
		$kaltura_title = __('Add Interactive Video');
		$kaltura_button_src = KalturaHelpers::getPluginUrl() . '/images/interactive_video_button.gif';
		$content .= <<<EOF
		<a href="{$kaltura_iframe_src}&amp;TB_iframe=true&amp;height=500&amp;width=640" class="thickbox" title='$kaltura_title'><img src='$kaltura_button_src' alt='$kaltura_title' /></a>
EOF;

		return $content;
	}

	public function mediaUploadTabsFilter($content)
	{
		$content['kaltura_upload'] = __('All in One Video');
		$content['kaltura_browse'] = __('Browse Interactive Videos');
		return $content;
	}

	public function mediaUploadTabsFilterOnlyKaltura($content)
	{
		$content = array();
		return $this->mediaUploadTabsFilter($content);
	}

	public function mediaUploadAction()
	{
		$this->setKalturaOnlyMediaTabs();

		if (!isset($_GET['kaction']))
			$_GET['kaction'] = 'upload';

		$controller = new Kaltura_LibraryController();

		wp_iframe(array($controller, 'execute'));
	}

	public function mediaBrowseAction()
	{
		$this->setKalturaOnlyMediaTabs();

		if (!isset($_GET['kaction']))
			$_GET['kaction'] = 'browse';

		$controller = new Kaltura_LibraryController();

		wp_iframe(array($controller, 'execute'));
	}

	public function mediaUploadPrintScriptsAction()
	{
		wp_enqueue_script('kaltura_upload_popup', KalturaHelpers::jsUrl('js/upload-popup.js'));
	}

	public function commentFormAction($post_id)
	{
		$user = wp_get_current_user();
		if (!$user->ID && !KalturaHelpers::anonymousCommentsAllowed())
		{
			echo "You must be <a href=" . get_option('siteurl') . "/wp-login.php?redirect_to=" . urlencode(get_permalink()) . ">logged in</a> to post a <br /> video comment.";
		}
		else
		{
			$plugin_url = KalturaHelpers::getPluginUrl();
			$js_click_code = "Kaltura.openCommentCW('".$plugin_url."'); ";
			echo "<input type=\"button\" id=\"kaltura_video_comment\" name=\"kaltura_video_comment\" tabindex=\"6\" value=\"Add Video Comment\" onclick=\"" . $js_click_code . "\" />";
		}
	}

	public function shortcodeHandler($attrs)
	{
		// prevent xss
		foreach($attrs as $key => $value)
		{
			$attrs[$key] = esc_js($value);
		}

		// get the embed options from the attributes
		$embedOptions = KalturaHelpers::getEmbedOptions($attrs);

		$isComment		= isset($attrs['size']) && ($attrs['size'] == 'comments') ? true : false;
		$wid 			= $embedOptions['wid'] ? $embedOptions['wid']: '_' . KalturaHelpers::getOption('kaltura_partner_id');
		$entryId 		= $embedOptions['entryId'];
		$width 			= $embedOptions['width'];
		$height 		= $embedOptions['height'];
		$randId 		= md5($wid . $entryId . rand(0, time()));
		$divId 			= 'kaltura_wrapper_' . $randId;
		$thumbnailDivId = 'kaltura_thumbnail_' . $randId;
		$playerId 		= 'kaltura_player_' . $randId;

		$link = '';
		$link .= '<a href="http://corp.kaltura.com/Products/Features/Video-Management">Video Management</a>, ';
		$link .= '<a href="http://corp.kaltura.com/Products/Features/Video-Hosting">Video Hosting</a>, ';
		$link .= '<a href="http://corp.kaltura.com/Products/Features/Video-Streaming">Video Streaming</a>, ';
		$link .= '<a href="http://corp.kaltura.com/products/video-platform-features">Video Platform</a>';
		$html ='<script src="http://www.kaltura.com/p/'.KalturaHelpers::getOption("kaltura_partner_id").'/sp/'.KalturaHelpers::getOption("kaltura_partner_id").'00/embedIframeJs/uiconf_id/'.$embedOptions['uiconfid'].'/partner_id/'.KalturaHelpers::getOption("kaltura_partner_id").'"></script>';
		$poweredByBox ='<div class="kaltura-powered-by" style="width: ' . $embedOptions["width"] . 'px; "><div><a href="http://corp.kaltura.com/Products/Features/Video-Player" target="_blank">Video Player</a> by <a href="http://corp.kaltura.com/" target="_blank">Kaltura</a></div></div>';

		if ($isComment)
		{
			$embedOptions['flashVars'] .= '"autoPlay":"true",';
			$html.='
			<div id="' . $thumbnailDivId . '" style="width:'.$width.'px;height:'.$height.'px;">'.$link.'</div>
			<script>
				kWidget.thumbEmbed({
					"targetId": "'.$thumbnailDivId.'",
					"wid": "'.$wid.'",
					"uiconf_id": "'.$embedOptions['uiconfid'].'",
					"flashvars": {'.$embedOptions["flashVars"].'},
					"entry_id": "'.$entryId.'"
				});
			</script>
		';
		}
		else
		{
			$style = '';
			$style .= 'width:' . $width .'px;';
			$style .= 'height:' . ($height + 10) . 'px;'; // + 10 is for the powered by div
			if (@$embedOptions['align'])
				$style .= 'float:' . $embedOptions['align'] . ';';

			// append the manual style properties
			if (@$embedOptions['style'])
				$style .= $embedOptions['style'];

			$html.='
			<div id="'.$playerId.'_wrapper" class="kaltura-player-wrapper"><div id="' . $playerId . '" style="'.$style.'">'.$link.'</div>'.$poweredByBox.'</div>
			<script>
				kWidget.embed({
					"targetId": "'.$playerId.'",
					"wid": "'.$wid.'",
					"uiconf_id": "'.$embedOptions['uiconfid'].'",
					"flashvars": {'.$embedOptions['flashVars'].'},
					"entry_id": "'.$entryId.'"
				});';
			//$html .= 'alert(document.getElementById("'.$playerId.'_wrapper").innerHTML);jQuery("#'.$playerId.'_wrapper").append("'.str_replace("\"", "\\\"", $powerdByBox).'");';
			$html .= '</script>';
		}
		return $html;
	}

	//save the post permalink in the entries metadata ipon save_post event.
	public function kaltura_save_post_entries_permalink($post_ID)
	{
		$kmodel = KalturaModel::getInstance();
		if(!KalturaHelpers::getOption('kaltura_save_permalink'))
			return;

		$metadataProfileId = KalturaHelpers::getOption('kaltura_permalink_metadata_profile_id');
		$metadataFieldsResponse = $kmodel->getMetadataProfileFields($metadataProfileId);
		//the metadata profile should have only one field.
		if ($metadataFieldsResponse->totalCount != 1)
			return;

		$metadataField = $metadataFieldsResponse->objects[0];
		$permalink = get_permalink($post_ID);
		$content = $_POST['content'];
		$matches = null;
		preg_match_all('/entryid=\\\\"([^\\\\]*)/', $content ,$matches);
		if ($matches && is_array($matches) && isset($matches[1]) && is_array($matches[1]) && count($matches[1])){
			foreach ($matches[1] as $entryId){
				_update_entry_permalink($entryId,$permalink,$metadataProfileId,$metadataField->key);
			}
		}
	}

	public function savePost()
	{

	}

	public function _update_entry_permalink($entryId, $permalink, $metadataProfileId, $metadataFieldName){
		$kmodel = KalturaModel::getInstance();
		$result = $kmodel->getEntryMetadata($entryId, $metadataProfileId);
		$xmlData = '<metadata><'.$metadataFieldName.'>'.$permalink.'</'.$metadataFieldName.'></metadata>';
		if($result->totalCount == 0){
			$kmodel->addEntryMetadata($metadataProfileId, $entryId, $xmlData);
		}
		else{
			/* @var $metadata KalturaMetadata */
			$metadata = $result->objects[0];
			$kmodel->updateEntryMetadata($metadata->id, $xmlData);
		}
	}

	public function networkAdminMenuAction()
	{
		add_submenu_page('settings.php', 'All in One Video', 'All in One Video', 'manage_network_options', 'all-in-one-video-pack-mu-settings',  $this->callback('networkSettings'));
	}

	public function networkSettings()
	{
		$controller = new Kaltura_NetworkAdminController();
		$controller->execute();
	}

	private function setKalturaOnlyMediaTabs()
	{
		unset($GLOBALS['wp_filter']['media_upload_tabs']); // remove all registerd filters for the tabs
		add_filter('media_upload_tabs', $this->callback('mediaUploadTabsFilterOnlyKaltura')); // register our filter for the tabs
		media_upload_header(); // will add the tabs menu
	}
}