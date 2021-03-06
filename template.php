<?php

function pcsa_preprocess_html(&$variables) {
	// Add bootstrap css to HTML
	drupal_add_css('https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css', array('type' => 'external'));

	// Mobile viewport
	$viewport = [
		'#tag' => 'meta', 
		'#attributes' => [
			'name' => 'viewport', 
			'content' => 'width=device-width, initial-scale=1, maximum-scale=1',
		],
	];
	drupal_add_html_head($viewport, 'viewport');

	drupal_add_html_head([
		'#tag' => 'meta',
		'#attributes' => [
			'name' => 'theme-color',
			'content' => '#b92525',
		],
	], 'theme-color');
}

/**
 * Override or insert variables into the page template.
 */
function pcsa_preprocess_page(&$variables) {
	drupal_add_js('https://maps.googleapis.com/maps/api/js?v=3&key=AIzaSyAWBp751VogsaCKYseOoHTEuTcTqSRsJEg', 'external');

	if ($variables['is_front']) {
		drupal_add_js(drupal_get_path('theme', 'pcsa') . '/js/header.js');
		$path = drupal_get_path('theme', 'pcsa');
		drupal_add_js(['image_root' => $path . '/images'], array('type' => 'setting'));
	}

	if(($variables['page']['sidebar_first'] ?? false) && ($variables['page']['sidebar_second'] ?? false)) {
		$variables['contentclass'] = 'col-sm-6 col-sm-push-3';
		$variables['firstsidebarpush'] = 'col-sm-pull-6';
	}
	elseif(($variables['page']['sidebar_first'] ?? false) || ($variables['page']['sidebar_second'] ?? false)){
		if($variables['page']['sidebar_first']){
			$variables['contentclass'] = 'col-sm-9 col-sm-push-3';
			$variables['firstsidebarpush'] = 'col-sm-pull-9';		
		}
		if($variables['page']['sidebar_second']){
			$variables['contentclass'] = 'col-sm-9';
		}		
	}
	else{
		$variables['contentclass'] = 'col-sm-12';
	}
}

function getUnreadData($node) {
  global $user;

  if ($user->uid) {
    // Retrieve the timestamp at which the current user last viewed the
    // specified node.
    $lastVisit = node_last_viewed($node['nid']);

    // Use the lastVisit to retrieve the number of new comments.
    $result = db_query('SELECT COUNT(c.cid) FROM {node} n INNER JOIN {comment} c ON n.nid = c.nid WHERE n.nid = :nid AND c.created > :lastVisit AND c.status = :status', [
      ':nid' => $node['nid'],
      ':lastVisit' => $lastVisit,
      ':status' =>  COMMENT_PUBLISHED,
    ]);

    return [
        'comments' => $result->fetchField(),
        'is_new' => isset($node['created']) ? $node['created'] > $lastVisit : false,
    ];
  }
  else {
    return 0;
  }
}

function pcsa_preprocess_node(&$variables) {
  if (in_array($variables['type'], ['activity', 'article', 'forum'])) {
    $unreadData = getUnreadData($variables);
    $variables['new_comment_count'] = $unreadData['comments'];
    $variables['is_new'] = $unreadData['is_new'];
  }

	// Remove 'add new comment' link from nodes.
	if (in_array($variables['type'], ['activity', 'article'])) {
		unset($variables['elements']['links']['comment']['#links']['comment-add']);
		unset($variables['content']['links']['comment']['#links']['comment-add']);
	}

	// Add regions so we can display the presence views in a node.
	if ($variables['type'] === 'activity' && !$variables['teaser']) {
		// echo("<pre>"); var_dump($variables); echo("</pre>");
		foreach (system_region_list($GLOBALS['theme']) as $region_key => $region_name) {
			// Get the content for each region and add it to the $region variable
			if ($blocks = block_get_blocks_by_region($region_key)) {
				$variables['region'][$region_key] = $blocks;
			}
			else {
				$variables['region'][$region_key] = [];
			}
		}
	}

  if (!in_array($variables['type'], ['flickr_albums_album', 'flickr_albums_photo'])) {
      $variables['theme_hook_suggestions'][] = 'node__' . $variables['view_mode'];
  }
  /* $variables['theme_hook_suggestions'][] = 'node__' . $variables['type'] . '__' . $variables['view_mode']; */
}

function pcsa_form_comment_form_alter(&$form, &$form_state) {
	$form['author']['#access'] = false;

  $form['actions']['submit']['#value'] = "Plaatsen";

	if ($form['node_type']['#value'] === 'comment_node_poll') {
		$form['subject']['#title'] = 'Onderwerp';
		return;
	}

	$form['subject']['#access'] = false;
}

function pcsa_form_alter(&$form, &$form_state, $form_id) {
	// Remove labels and add HTML5 placeholder attribute to login form
	if (in_array($form_id, ['user_login', 'user_login_block']))
		$form['name']['#attributes']['placeholder'] = t('Gebruikersnaam of email');
	else if ($form_id === 'user_pass')
		$form['name']['#attributes']['placeholder'] = t('Gebruikersnaam of email');

	$form['pass']['#attributes']['placeholder'] = t( 'Wachtwoord' );
	$form['name']['#title_display'] = "invisible";
	$form['pass']['#title_display'] = "invisible";

	// Hide other form elements during password reset
	if ($form_id === 'user_profile_form') {
		global $user;
		if (!$user->uid) {
			$form['account']['#title'] = 'Password Reset';
			$form['#submit'][] = 'redirect_after_password_reset';
			foreach ($form as $key => $formElement) {
				// Special form elements
				if (substr($key, 0, 1) === '#') continue;

				$special = ['actions', 'account', 'form_id', 'form_build_id'];
				$useFields = ['field_email', 'pass', 'name'];
				if (!in_array($key, array_merge($special, $useFields))) {
					unset($form[$key]);
				}
			}
		}
	}
}

function redirect_after_password_reset($form, &$form_state) {
	global $user;
	$form_state['redirect'] = "/user/{$user->uid}/edit";
}

function pcsa_form_activity_node_form_alter(&$form, &$form_state, $form_id) {
  $form['#submit'][] = 'node_activity_submit_handler';

  if (isset($form_state['values'])) {
    $addr = $form_state['values']['field_activity_location'][LANGUAGE_NONE][0];

    $noLocation = true;
    foreach ($addr as $key => $value) {
        if ($value) $noLocation = false;
    }

    if ($noLocation) {
        $form_state['values']['field_activity_location'][LANGUAGE_NONE][0]['country'] = 'NL';
    }
  }
}

/**
 * Clear the country code from an activity address if it's the only set value.
 * This way, we can have NL as default when creating an activity, so that just
 * the thoroughfare can be set, without having to set it manually, and without
 * just a country showing up in location rendering.
 */
function node_activity_submit_handler($form, &$form_state) {
  $addr = $form_state['values']['field_activity_location'][LANGUAGE_NONE][0];

  $noLocation = true;
  foreach ($addr as $key => $value) {
      if ($value && $key !== 'country') $noLocation = false;
  }

  if ($noLocation) {
    $form_state['values']['field_activity_location'][LANGUAGE_NONE][0]['country'] = '';
  }
}

function pcsa_form_user_login_alter(&$form, &$form_state) {
	// Remove login form descriptions
	$form['name']['#description'] = t('');
	$form['pass']['#description'] = t('');
}

function pcsa_preprocess_views_view_table(&$vars) {
  if ($vars['view']->name === 'activities') {
    foreach ($vars['rows'] as $rowNum => $row) {
      $unreadData = getUnreadData($row);
      $vars['rows'][$rowNum]['new_comment_count'] = $unreadData['comments'];
      $vars['rows'][$rowNum]['is_new'] = $unreadData['is_new'];
    }
  }
}

function pcsa_theme() {
	$path = drupal_get_path('theme', 'pcsa') . '/templates';
	return [
		'user_login' => [
			'render element' => 'form',
			'path' => $path,
			'template' => 'user-login',
			'preprocess functions' => [
				'pcsa_preprocess_user_login'
			],
		],
		'flickrgallery_albums' => [
			'variables' => [
				'description' => NULL,
				'albums' => NULL,
			],
			'preprocess functions' => [
				'preprocess_flickrgallery_albums',
			],
			'template'  => 'flickrgallery_albums',
			'path' => $path,
		],
		'flickrgallery_photo' => [
			'variables' => [
				'image' => NULL,
				'image_meta' => NULL,
			],
			'preprocess functions' => [
				'preprocess_flickrgallery_photo',
			],
			'template'  => 'flickrgallery_photo',
			'path' => $path,
		],
	];
}

function preprocess_flickrgallery_albums(&$variables) {
	$albums = [];
	foreach ($variables['albums'] as $key => $album_source) {
		$image_link = $album_source['image_link'];
		$title_link = $album_source['title_link'];

		preg_match('/href="([a-zA-Z0-9\/]+)"/', $image_link, $link_matches);
		$link = $link_matches[1];

		preg_match('/src="(.+?)"/', $image_link, $cover_matches);
		$cover_image = $cover_matches[1];

		preg_match('/>(.*)<\/a>/', $title_link, $title_matches);
		$title = $title_matches[1];

		$albums[$key] = [
			'link' => $link,
			'cover_image' => $cover_image,
			'title' => $title,
			'image_link' => $album_source['image_link'],
			'title_link' => $album_source['title_link'],
		];
	}
	$variables['albums'] = $albums;
}

function preprocess_flickrgallery_photo(&$variables) {
		preg_match('/href="(.+?)"/', $variables['image']['image'], $url_matches);
		preg_match('/class="(.+?)"/', $variables['image']['image'], $class_matches);
		preg_match('/rel="(.+?)"/', $variables['image']['image'], $rel_matches);
    $variables['url'] = $url_matches[1];
    $variables['class'] = $class_matches[1];
    $variables['rel'] = $rel_matches[1];
}

function pcsa_views_pre_render(&$view) {
  global $user;
	if ($view->name === 'new_content') {
		if ($user->uid) {
			$view->build_info['title'] = "Recent";
		}
		else {
			$view->build_info['title'] = "Nieuws";
		}
	}
}

function pcsa_pwa_manifest_alter(&$manifest) {
	$path = drupal_get_path('theme', 'pcsa');
	$manifest['icons'] = [
		[
			'src' => url($path . '/assets/icon-48.png'),
			'sizes' => '48x48',
			'type' => 'image/png',
		],
		[
			'src' => url($path . '/assets/icon-72.png'),
			'sizes' => '72x72',
			'type' => 'image/png',
		],
		[
			'src' => url($path . '/assets/icon-96.png'),
			'sizes' => '96x96',
			'type' => 'image/png',
		],
		[
			'src' => url($path . '/assets/icon-144.png'),
			'sizes' => '144x144',
			'type' => 'image/png',
		],
		[
			'src' => url($path . '/assets/icon-168.png'),
			'sizes' => '168x168',
			'type' => 'image/png',
		],
		[
			'src' => url($path . '/assets/icon-192.png'),
			'sizes' => '192x192',
			'type' => 'image/png',
		],
		[
			'src' => url($path . '/assets/icon-512.png'),
			'sizes' => '512x512',
			'type' => 'image/png',
		],
	];
}

/*
 * Without any preprocess hook
 */
drupal_add_js('//maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js', array(
	'type' => 'external',
	'scope' => 'header',
	'group' => JS_THEME,
	'every_page' => TRUE,
	'weight' => -1,
));
