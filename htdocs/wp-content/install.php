<?php
/**
 * WordPress custom install script.
 *
 * Drop-ins are advanced plugins in the wp-content directory that replace WordPress functionality when present.
 *
 * Language: fi
 */

/**
 * Installs the site.
 *
 * Runs the required functions to set up and populate the database,
 * including primary admin user and initial options.
 *
 * @since 2.1.0
 *
 * @param string $blog_title    Blog title.
 * @param string $user_name     User's username.
 * @param string $user_email    User's email.
 * @param bool   $public        Whether blog is public.
 * @param string $deprecated    Optional. Not used.
 * @param string $user_password Optional. User's chosen password. Default empty (random password).
 * @param string $language      Optional. Language chosen. Default empty.
 * @return array Array keys 'url', 'user_id', 'password', and 'password_message'.
 */
function wp_install( $blog_title, $user_name, $user_email, $public, $deprecated = '', $user_password = '', $language = '' ) {
  if ( !empty( $deprecated ) )
    _deprecated_argument( __FUNCTION__, '2.6' );

  wp_check_mysql_version();
  wp_cache_flush();
  make_db_current_silent();
  populate_options();
  populate_roles();

  if ( $language ) {
    update_option( 'WPLANG', $language );
  } else {
    update_option( 'WPLANG', 'fi' ); // Use fi as default if language is not defined
  }

  update_option('blogname', $blog_title);
  update_option('admin_email', $user_email);
  update_option('blog_public', $public);
  update_option('blogdescription',__('Uusi WordPress sivusto'));

  $guessurl = wp_guess_url();

  update_option('siteurl', $guessurl);

  // If not a public blog, don't ping.
  if ( ! $public )
    update_option('default_pingback_flag', 0);

  /*
   * Create default user. If the user already exists, the user tables are
   * being shared among blogs. Just set the role in that case.
   */
  $user_id = username_exists($user_name);
  $user_password = trim($user_password);
  $email_password = false;
  if ( !$user_id && empty($user_password) ) {
    $user_password = wp_generate_password( 12, false );
    $message = __('<strong><em>Note that password</em></strong> carefully! It is a <em>random</em> password that was generated just for you.');
    $user_id = wp_create_user($user_name, $user_password, $user_email);
    update_user_option($user_id, 'default_password_nag', true, true);
    $email_password = true;
  } elseif ( ! $user_id ) {
    // Password has been provided
    $message = '<em>'.__('Your chosen password.').'</em>';
    $user_id = wp_create_user($user_name, $user_password, $user_email);
  } else {
    $message = __('User already exists. Password inherited.');
  }

  $user = new WP_User($user_id);
  $user->set_role('administrator');

  wp_install_defaults($user_id);

  wp_install_maybe_enable_pretty_permalinks();

  flush_rewrite_rules();

  wp_new_blog_notification($blog_title, $guessurl, $user_id, ($email_password ? $user_password : __('The password you chose during the install.') ) );

  wp_cache_flush();

  /**
   * Fires after a site is fully installed.
   *
   * @since 3.9.0
   *
   * @param WP_User $user The site owner.
   */
  do_action( 'wp_install', $user );

  return array('url' => $guessurl, 'user_id' => $user_id, 'password' => $user_password, 'password_message' => $message);
}

/**
 * Creates the initial content for a newly-installed site.
 *
 * Adds the default "Uncategorized" category, the first post (with comment),
 * first page, and default widgets for default theme for the current version.
 *
 * @since 2.1.0
 *
 * @param int $user_id User ID.
 */
function wp_install_defaults( $user_id ) {
  global $wpdb, $wp_rewrite, $current_site, $table_prefix;

  /** @see wp-admin/options-general.php */

  /** Time zone: "Helsinki" */
  update_option( 'timezone_string', 'Europe/Helsinki' );

  /** @see wp-admin/options-discussion.php */

  /** Before a comment appears a comment must be manually approved: true */
  update_option( 'comment_moderation', 1 );

  /** Before a comment appears the comment author must have a previously approved comment: false */
  update_option( 'comment_whitelist', 0 );

  /** Allow people to post comments on new articles (this setting may be overridden for individual articles): false */
  update_option( 'default_comment_status', 0 );

  /** Allow link notifications from other blogs: false */
  update_option( 'default_ping_status', 0 );

  /** Attempt to notify any blogs linked to from the article: false */
  update_option( 'default_pingback_flag', 0 );

  /** @see wp-admin/options-media.php */

  /** Organize my uploads into month- and year-based folders: false */
  // TODO: this might be better for seo so that links don't suffer from ageism
  //update_option( 'uploads_use_yearmonth_folders', 0 );

  /** @see wp-admin/options-permalink.php */

  /** Permalink custom structure: /%postname% */
  update_option( 'permalink_structure', '/%postname%/' );

  // First post
  $now = current_time( 'mysql' );
  $now_gmt = current_time( 'mysql', 1 );
  $first_post_guid = get_option( 'home' ) . '/?p=1';

  if ( is_multisite() ) {
    $first_post = get_site_option( 'first_post' );

    if ( empty($first_post) )
      $first_post = __( 'Welcome to <a href="SITE_URL">SITE_NAME</a>. This is your first post. Edit or delete it, then start blogging!' );

    $first_post = str_replace( "SITE_URL", esc_url( network_home_url() ), $first_post );
    $first_post = str_replace( "SITE_NAME", get_current_site()->site_name, $first_post );
  } else {
    $first_post = __("Hienoa, että päätit valita WP-palvelun!<br><br><a href='https://wp-palvelu.fi/'><img class='size-medium wp-image-6 alignright' src='https://wp-palvelu.fi/wp-palvelu-logo-blue.png' alt='wp-palvelu-logo' width='300' height='60' /></a>Voit aloittaa <a href='/wp-login.php'>kirjautumalla sisälle</a>.<br>Saat apua kysymyksiin lukemalla:<br><a href='https://wp-palvelu.fi/ohjeet/'>wp-palvelu.fi/ohjeet/</a>");
  }

  $wpdb->insert( $wpdb->posts, array(
    'post_author' => $user_id,
    'post_date' => $now,
    'post_date_gmt' => $now_gmt,
    'post_content' => $first_post,
    'post_excerpt' => '',
    'post_title' => __('Tervetuloa uudelle sivullesi!'),
    /* translators: Default post slug */
    'post_name' => sanitize_title( _x('tervetuloa', 'Default post slug') ),
    'post_modified' => $now,
    'post_modified_gmt' => $now_gmt,
    'guid' => $first_post_guid,
    'comment_count' => 0,
    'to_ping' => '',
    'pinged' => '',
    'post_content_filtered' => ''
  ));
  $wpdb->insert( $wpdb->term_relationships, array('term_taxonomy_id' => $cat_tt_id, 'object_id' => 1) );

  // Set up default widgets for default theme.
  update_option( 'widget_search', array ( 2 => array ( 'title' => '' ), '_multiwidget' => 1 ) );
  update_option( 'widget_recent-posts', array ( 2 => array ( 'title' => '', 'number' => 5 ), '_multiwidget' => 1 ) );
  update_option( 'widget_recent-comments', array ( 2 => array ( 'title' => '', 'number' => 5 ), '_multiwidget' => 1 ) );
  update_option( 'widget_archives', array ( 2 => array ( 'title' => '', 'count' => 0, 'dropdown' => 0 ), '_multiwidget' => 1 ) );
  update_option( 'widget_categories', array ( 2 => array ( 'title' => '', 'count' => 0, 'hierarchical' => 0, 'dropdown' => 0 ), '_multiwidget' => 1 ) );
  update_option( 'widget_meta', array ( 2 => array ( 'title' => '' ), '_multiwidget' => 1 ) );
  update_option( 'sidebars_widgets', array ( 'wp_inactive_widgets' => array (), 'sidebar-1' => array ( 0 => 'search-2', 1 => 'recent-posts-2', 2 => 'recent-comments-2', 3 => 'archives-2', 4 => 'categories-2', 5 => 'meta-2', ), 'array_version' => 3 ) );

  if ( ! is_multisite() )
    update_user_meta( $user_id, 'show_welcome_panel', 1 );
  elseif ( ! is_super_admin( $user_id ) && ! metadata_exists( 'user', $user_id, 'show_welcome_panel' ) )
    update_user_meta( $user_id, 'show_welcome_panel', 2 );

  if ( is_multisite() ) {
    // Flush rules to pick up the new page.
    $wp_rewrite->init();
    $wp_rewrite->flush_rules();

    $user = new WP_User($user_id);
    $wpdb->update( $wpdb->options, array('option_value' => $user->user_email), array('option_name' => 'admin_email') );

    // Remove all perms except for the login user.
    $wpdb->query( $wpdb->prepare("DELETE FROM $wpdb->usermeta WHERE user_id != %d AND meta_key = %s", $user_id, $table_prefix.'user_level') );
    $wpdb->query( $wpdb->prepare("DELETE FROM $wpdb->usermeta WHERE user_id != %d AND meta_key = %s", $user_id, $table_prefix.'capabilities') );

    // Delete any caps that snuck into the previously active blog. (Hardcoded to blog 1 for now.) TODO: Get previous_blog_id.
    if ( !is_super_admin( $user_id ) && $user_id != 1 )
      $wpdb->delete( $wpdb->usermeta, array( 'user_id' => $user_id , 'meta_key' => $wpdb->base_prefix.'1_capabilities' ) );
  }

  /** @see wp-admin/includes/upgrade.php */

  /** Default category */
  $cat_name = __('Uncategorized');

  $cat_slug = sanitize_title(_x('Uncategorized', 'Default category slug'));

  if ( global_terms_enabled() ) {
    $cat_id = $wpdb->get_var( $wpdb->prepare( "SELECT cat_ID FROM {$wpdb->sitecategories} WHERE category_nicename = %s", $cat_slug ) );

    if ( $cat_id == null ) {
      $wpdb->insert(
        $wpdb->sitecategories, array(
          'cat_ID'            => 0,
          'cat_name'          => $cat_name,
          'category_nicename' => $cat_slug,
          'last_updated'      => current_time( 'mysql', true )
        )
      );
      $cat_id = $wpdb->insert_id;
    }
    update_option( 'default_category', $cat_id );
  } else {
    $cat_id = 1;
  }

  $wpdb->insert(
    $wpdb->terms, array(
      'name'       => $cat_name,
      'slug'       => $cat_slug,
      'term_group' => 0,
      'term_id'    => $cat_id,
    )
  );

  $wpdb->insert(
    $wpdb->term_taxonomy, array(
      'count'       => 0,
      'description' => '',
      'parent'      => 0,
      'taxonomy'    => 'category',
      'term_id'     => $cat_id,
    )
  );

  $cat_tt_id = $wpdb->insert_id;

  /** @see wp-admin/includes/screen.php */

  /** Show welcome panel: false */
  update_user_meta( $user_id, 'show_welcome_panel', 0 );

  /** @see wp-includes/user.php */

  /** Disable the visual editor when writing: false */
  //update_user_meta( $user_id, 'rich_editing', 0 );

  /** Show toolbar when viewing site: false */
  //update_user_meta( $user_id, 'show_admin_bar_front', 0 );

  /** Activate some plugins automatically if they exists */
  wp_palvelu_install_activate_plugins();
}

/*
 * Helper which activates some useful plugins
 */
function wp_palvelu_install_activate_plugins() {

  // Get the list of all installed plugins
  $all_plugins = get_plugins();

  // Auto activate these plugins on install
  // You can override default ones using WP_AUTO_ACTIVATE_PLUGINS in your wp-config.php
  if (defined('WP_AUTO_ACTIVATE_PLUGINS')) {
    $plugins = explode(',',WP_AUTO_ACTIVATE_PLUGINS);
  } else {
    $plugins = array(
      'https-domain-alias'
    );
  }

  // Activate plugins if they can be found from installed plugins
  foreach ($all_plugins as $plugin_path => $data) {
    $plugin_name = explode('/',$plugin_path)[0]; // get the folder name from plugin
    if (in_array($plugin_name,$plugins)) { // If plugin is installed activate it
      // Do the activation
      include_once(WP_PLUGIN_DIR . '/' . $plugin_path);
      do_action('activate_plugin', $plugin_path);
      do_action('activate_' . $plugin_path);
      $current[] = $plugin_path;
      sort($current);
      update_option('active_plugins', $current);
      do_action('activated_plugin', $plugin_path);
    }
  }
}

/**
 * Notifies the site admin that the setup is complete.
 * We don't want to send any emails about this
 *
 *
 * @since 2.1.0
 *
 * @param string $blog_title Blog title.
 * @param string $blog_url   Blog url.
 * @param int    $user_id    User ID.
 * @param string $password   User's Password.
 */
function wp_new_blog_notification($blog_title, $blog_url, $user_id, $password) {
  // Do nothing please, we don't need this spam
}