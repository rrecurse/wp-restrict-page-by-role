<?php
/*
Plugin Name: PCR Restrict Post By Role
Description: Restrict access to the pages or posts. Allow only certain roles access.
Version: 1.0
Author: cdebellis
License: None
*/
if(!defined('ABSPATH')) exit; // Exit if accessed directly.

	class restrict_post_by_role {
		/**
		 * Instance of this class.
		 *
		 * @var object
		 *
		 * @since 1.0.0
		 */
		protected static $instance = null;

		/**
		 * Slug.
		 *
		 * @var string
		 *
		 * @since 1.0.0
		 */
		protected static $text_domain = 'pcr-rpbr';

		/**
		 * Initialize the plugin
		 *
		 * @since 1.0.0
		 */
		private function __construct() {

			// # make sure admin functions to check plugins are in scope!
			include_once( ABSPATH . 'wp-admin/includes/plugin.php');

			// # load plugin text domain
			add_action( 'plugins_loaded', array( $this, 'load_plugin_textdomain' ) );

			// # load styles and script
			add_action( 'admin_enqueue_scripts', array( $this, 'load_admin_styles_and_scripts' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'load_admin_styles_and_scripts' ) );		

			// # load helpers
			add_action( 'init', array( $this, 'load_helper' ) );

			// # add field in meta box submitdiv
			if ( is_admin() ) {
				add_action( 'post_submitbox_misc_actions', array( $this, 'post_submitbox_misc_actions' ) );
			}

			// # save post
			add_action('save_post', array( $this, 'register_restrict_role' ) );

			// # show error message for bookmarked pages.
			add_filter( 'the_content', array( $this, 'restrict_content_page' ), 19);

			// # strip hard links to restricted pages.
			add_filter( 'the_content', array( $this, 'strip_hard_links' ), 20);

			// # override the page title
			//add_filter('the_title', array($this, 'restrict_page_title'), 100, 1);
			//add_filter('document_title_parts', array($this, 'restrict_filter_title'), 10000, 2 );
			//add_filter('wp_title',array($this, 'restrict_filter_title'),10,1);

			// # hide menu / nav items
			add_filter('wp_nav_menu_items', array($this, 'restrict_menu_items'), null, 2);
		
			// # add after-save admin notices
			add_action('admin_notices', array($this, 'admin_notices'));

			// # if page-list is active, also filter results by role.
			if(is_plugin_active('page-list/page-list.php')) {
				add_filter('wp_list_pages', array($this, 'restrict_pagelist_items'));
			}

			if(is_plugin_active('recent-pagepost-updates/recent-pagepost-updates.php')) {
				add_filter('recent-pagepost-extended-filter', array($this, 'restrict_recent_items'), 1);
			}

			// # now filter the search results
			add_action('pre_get_posts', array($this, 'restrict_search_results'));
		}

		/**
		 * Return an instance of this class.
		 *
		 *
		 * @since 1.0.0
		 *
		 * @return object A single instance of this class.
		 */
		public static function get_instance() {
			// If the single instance hasn't been set, set it now.
			if ( null == self::$instance ) {
				self::$instance = new self;
			}

			return self::$instance;
		}

		/**
		 * Load the plugin text domain for translation.
		 *
		 *
		 * @since 1.0.0
		 *
		 * @return void
		 */
		public function load_plugin_textdomain() {
			load_plugin_textdomain( self::$text_domain, false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
		}

		/**
		 * Load styles and scripts
		 *
		 * @since 1.0.0
		 *
		 */
		public function load_admin_styles_and_scripts(){
			wp_enqueue_style( self::$text_domain . '_css_main', plugins_url( '/assets/css/main.css', __FILE__ ), array(), null, 'all' );
			wp_enqueue_style( self::$text_domain . '_css_main', plugins_url( '/assets/css/main.css', __FILE__ ), array(), null, 'all' );
			wp_enqueue_style('font-awesome', plugins_url( '/assets/css/font-awesome.min.css', __FILE__ ), array(), '2.6.8', 'all' );
			$params = array(
						'ajax_url'	=> admin_url( 'admin-ajax.php' )
					);
			wp_enqueue_script( self::$text_domain . '_js_main', plugins_url( '/assets/js/main.js', __FILE__ ), array( 'jquery' ), null, true );
			wp_localize_script( self::$text_domain . '_js_main', 'data_rpbr', $params );
		}

		/**
		 * Load auxiliary and third classes are in the class directory
		 *
		 * @since 1.0.0
		 */
		public function load_helper() {
			$class_dir = plugin_dir_path( __FILE__ ) . "/helper/";
			foreach ( glob( $class_dir . "*.php" ) as $filename ){
				include $filename;
			}
		}

		/**
		 * Pre-select specified roles - supports multple
		 *
		 *
		 * @since 1.0.0
		 *
		 * @param $selected The role array.
		 *
		 * function based on wp_dropdown_roles() found in /wp-admin/includes/template.php
		 *
		 */
		public function dropdown_roles_multi($selected = false) {
			$y = '';
			$n = '';

			$editable_roles = get_editable_roles();

			if (is_string($selected)) $selected = array($selected);

			// # remove the default WP roles for selection:
			unset($editable_roles['administrator'],
				  $editable_roles['editor'], 
				  $editable_roles['author'], 
				  $editable_roles['contributor'], 
				  $editable_roles['subscriber'], 
				  $editable_roles['app_subscriber'], 
				  $editable_roles['super_admin']
				 );

			asort($editable_roles);
			foreach ($editable_roles as $role => $details ) {
				$name = translate_user_role($details['name'] );
				// # preselect specified role
				if ( is_array($selected) && in_array($role, $selected) ) { 
					$y .= "\n\t<option selected='selected' value='" . esc_attr($role) . "'>$name</option>";
				} else {
					$n .= "\n\t<option value='" . esc_attr($role) . "'>$name</option>";
				}
			}
			
			echo $y . $n;
		}

		/**
		 * Add custom field in meta box submitdiv.
		 *
		 * @since 1.0.0
		 */
		public function post_submitbox_misc_actions() {
			$post = get_post();

			echo '<div class="misc-pub-section public-post-preview">';
				$this->get_select_html( $post );
			echo '</div>';

		}

		/**
		 * Print the select with roles for define restrict page.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_Post $post The post object.
		 */
		private function get_select_html( $post ) {

			// Check if empty $post and define $post
			if ( empty( $post ) ) {
				$post = get_post();
			}

			$restrict_access = get_post_meta( $post->ID, self::$text_domain . '_restrict_access', true );
			$select_roles = get_post_meta( $post->ID, self::$text_domain . '_select_role', true );
			$redirect_url = get_post_meta( $post->ID, self::$text_domain . '_redirect_url', true );

			// Field nonce for submit control
			wp_nonce_field( self::$text_domain . '_select_role', self::$text_domain . '_select_role_wpnonce' );

			// Create html with select for restrict access by role
			echo '<p><strong>' . __('Restrict access by role?', self::$text_domain) . '</strong></p>';
			echo '<input type="checkbox" name="' . self::$text_domain . '_restrict_access" value="1" class="' . self::$text_domain . '_restrict_access"' . checked( 1, $restrict_access, false ) . '> Yes?';

			echo '<div class="' . self::$text_domain . '_box-select-role">';
			echo '<p><strong>' . __( 'Select a role', 'pcr-rpbr' ) . '</strong></p>';

			echo '<label class="screen-reader-text" for="' . self::$text_domain . '_select_role">' . __( 'Select role', 'pcr-rpbr' ) . '</label>';

			echo '<select name="' . self::$text_domain . '_select_role[]" id="' . self::$text_domain . '_select_role" class="' . self::$text_domain . '_select_role" multiple="multiple">';

			// # select multiple roles from new dropdown_roles_multi() function
			self::dropdown_roles_multi($select_roles);
			echo '</select>';

			echo '<p><strong>' . __( 'Specify Redirect URL', 'pcr-rpbr' ) . '</strong></p>';
			echo '<label class="screen-reader-text" for="' . self::$text_domain . '_redirect_url">' . __( 'Redirect URL', 'pcr-rpbr' ) . '</label>';
			echo '<input type="text" name="' . self::$text_domain . '_redirect_url" value="'. $redirect_url .'" class="' . self::$text_domain . '_redirect_url">
			</div>';
		}

		/**
		 * Save select role for restrict access for page.
		 *
		 *
		 * @since 1.0.0
		 *
		 * @param int $post_id The post id.
		 * @param object $post The post object.
		 * @return bool false or true
		 */
		public function register_restrict_role( $post_id ) {

			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				echo $post_id;
				return false;
			}

			if (empty( $_POST[self::$text_domain . '_select_role_wpnonce'] ) || ! wp_verify_nonce( $_POST[self::$text_domain . '_select_role_wpnonce'], self::$text_domain . '_select_role' ) ) {
				return false;
			}
			
			$restrict_access = esc_attr( $_POST[self::$text_domain . '_restrict_access']);

			if (!empty($restrict_access)) {	
				update_post_meta($post_id, self::$text_domain . '_restrict_access', $restrict_access );
			} else {
				update_post_meta($post_id, self::$text_domain . '_restrict_access', 0 );
			}

			$select_roles = $_POST[self::$text_domain . '_select_role'];

			if(!empty($select_roles)) {
				update_post_meta($post_id, self::$text_domain . '_select_role', $select_roles);
			} else {
				delete_post_meta($post_id, self::$text_domain . '_select_role');
			}
			
			$redirect_url = esc_attr( $_POST[self::$text_domain . '_redirect_url'] );
			
			if(!empty($redirect_url)) {		

				if(!filter_var($redirect_url, FILTER_VALIDATE_URL) === true || $redirect_url == get_permalink(get_the_ID())) {

					// # add admin warning
					add_filter('redirect_post_location', array( $this, 'add_notice_query_var' ), 99 );
 					
				} else {
					update_post_meta($post_id, self::$text_domain . '_redirect_url', $redirect_url);
				}
				
			} else {

				delete_post_meta($post_id, self::$text_domain . '_redirect_url');
			}

			return true;
		}
 
		public function add_notice_query_var( $location ) {
			remove_filter( 'redirect_post_location', array( $this, 'add_notice_query_var' ), 99 );
			return add_query_arg( array( 'redirectURL' => 'ID' ), $location );
		}

		public function admin_notices() {
   			if ( ! isset( $_GET['redirectURL'] ) ) {
     			return;
   			}
			?>
			<div class="error"><p><?php esc_html_e( 'Not a valid Redirect URL!', 'pcr-rpbr' ); ?></p></div>
			<?php
		}


		/**
		 * Restrict menu / nav items
		 *
		 * @since 1.0.0
		 *
		 * @param string $items The menu items
		 * @return string $items
		 */
		public function restrict_menu_items($items, $args) {

			if (current_user_can('administrator') || current_user_can('editor')) {
		        return $items;
		    }

			// # get current user roles
			$user = wp_get_current_user();
			$assigned_roles = $user->roles;

			$menu = wp_get_nav_menu_items('main-nav');
			foreach ($menu as $menu_key => $menu_item) {
				$post_id = $menu_item->object_id;
				$menu_id = $menu_item->ID;

		        if ($post_id > 0) {
		            $restrict_access = (int)get_post_meta($post_id, self::$text_domain . '_restrict_access', true);
		            $select_roles    = get_post_meta($post_id, self::$text_domain . '_select_role', true);
		            if ($restrict_access !== 1 || empty($select_roles)) {
		                continue;
		            }
		 
		            $select_roles   = array_unique($select_roles);
		            $user_can_roles = array_filter($select_roles, 'current_user_can');
		 
		            if (count($user_can_roles) === 0) {
						// # we MUST strip whitespace to avoid breaking regex matches
						$items = preg_replace('/\s+/', ' ', $items);
						$items = preg_replace('|(<li id="menu-item-'.$menu_id.'"(.*?)</li>)|', '', $items);

						unset($menu[$menu_key]);
		            }
		        }
			}

			return $items;
		}


		/**
		 * Restrict recent pagepost updatesitems
		 *
		 * @since 1.0.0
		 *
		 * @param  $RecentPagePostUpdates The recent page/post items
		 * @return object $RecentPagePostUpdates
		 */

		public function restrict_recent_items($RecentPagePostUpdates) {

			if(is_array($RecentPagePostUpdates) && !empty($RecentPagePostUpdates)) {
				foreach($RecentPagePostUpdates as $key => $item) {

					$post_id = $item->ID;

					$select_roles = get_post_meta($post_id, self::$text_domain . '_select_role', true );
					$restrict_access = get_post_meta($post_id, self::$text_domain . '_restrict_access', true );

					if($restrict_access == 1 && !empty($select_roles)) {

						foreach($select_roles as $roles) {
							if(current_user_can($roles ) || current_user_can('administrator') || current_user_can('editor')) {
								return $RecentPagePostUpdates;
							}
						}

						//# now filter matching list items
						unset($RecentPagePostUpdates[$key]);
						
						// # clean up array index order
						$updatedArray = array_values($RecentPagePostUpdates);
					}
				}
			}

			return $updatedArray;

		}

		/**
		 * Restrict page-list plugin items
		 *
		 * @since 1.0.0
		 *
		 * @param string $output The wp_list_pages items
		 * @return string/html block $output
		 */

		public function restrict_pagelist_items($output) {

			// # $output returns a string block of html
			// # strip the html and create new array
			$parsed_ids = array_filter(explode("\n", strip_tags($output)));

			// # some sanity checking on array
			if(is_array($parsed_ids) && !empty($parsed_ids)) {
				foreach($parsed_ids as $key => $item) {

					// # use wp native function to get page object by page name
					// # Caveat = pages with duplicate names
					$item = get_page_by_title($item);

					$post_id = $item->ID;

					$select_roles = get_post_meta($post_id, self::$text_domain . '_select_role', true );
					$restrict_access = get_post_meta($post_id, self::$text_domain . '_restrict_access', true );

					if($restrict_access == 1 && !empty($select_roles)) {

						foreach($select_roles as $roles) {
							if(current_user_can($roles) || current_user_can('administrator') || current_user_can('editor')) {
								return $output;
							} else {

								// # now filter matching list items
								$output = preg_replace('/<li class=\"page_item\spage\-item\-'.$post_id.'\spage_item_has_children\">(.*?)<\/li>/', '', $output);

								// # unset from original array for posterity (does nothing);
								unset($parsed_ids[$key]);	
							}
						}
					}
				}
			}

			return $output;
		}

		/**
		 * Restrict search results items
		 *
		 * @since 1.0.0
		 *
		 * @param string $output The wp_list_pages items
		 * @return string/html block $output
		 */

		public function restrict_search_results($query) {

			if (current_user_can('administrator') || current_user_can('editor')) {
		        return $query;
		    }

			global $wpdb;

			$restrict = array();


			$admin_files = scandir('.');
			$remove = array('.', '..', 'admin-ajax.php');
			$admin_array = array_diff($admin_files, $remove);
			foreach ($admin_array as $k => $page) $admin_array[] = '/wp-admin/' . $page;
			$is_admin = (in_array($_SERVER['PHP_SELF'], $admin_array) ? true : false);


  			if ( $query->is_search() && $is_admin !== true) {

				$restrict_results = $wpdb->get_results("SELECT a.post_id, a.meta_value AS active, b.meta_value AS roles 
														FROM ". $wpdb->postmeta ." a 
														LEFT JOIN ". $wpdb->postmeta ." b ON a.post_id = b.post_id 
														WHERE a.meta_key = 'pcr-rpbr_restrict_access' 
														AND a.meta_value = 1 
														AND b.meta_key = 'pcr-rpbr_select_role'
														");

				foreach ($restrict_results as $restricted) {

			        if ($restricted->post_id > 0) {
			        	$post_id = $restricted->post_id;
			            $restrict_access = (int)get_post_meta($post_id, self::$text_domain . '_restrict_access', true);
			            $select_roles = get_post_meta($post_id, self::$text_domain . '_select_role', true);

			            if ($restrict_access !== 1 || empty($select_roles)) {
			                continue;
			            }
			 
			            $select_roles   = array_unique($select_roles);
			            $user_can_roles = array_filter($select_roles, 'current_user_can');
			 
			            if (count($user_can_roles) === 0) {
							$restrict[] = $post_id;
						
			            }
			        }
				}

  				// # filter matching list items
				$query->set('post__not_in', array_unique($restrict));
				$query->set('post_parent__not_in', array_unique($restrict));

  			}

  			return $query;
		}


		/**
		 * Restrict content page
		 *
		 * @since 1.0.0
		 *
		 * @param string $content The content page
		 * @return string $content
		 */
		public function restrict_content_page( $content ) {

			$post_parent = wp_get_post_parent_id(get_the_ID());
			$restricted_parent = ($post_parent ? get_post_meta($post_parent, self::$text_domain . '_restrict_access', true ) : false);

			if($restricted_parent > 0) {
				$restrict_access = get_post_meta($post_parent, self::$text_domain . '_restrict_access', true );
				$selected_roles = get_post_meta($post_parent, self::$text_domain . '_select_role', true );
				$redirect_url = get_post_meta($post_parent, self::$text_domain . '_redirect_url', true );
			} else {
				$restrict_access = get_post_meta( get_the_ID(), self::$text_domain . '_restrict_access', true );
				$selected_roles = get_post_meta( get_the_ID(), self::$text_domain . '_select_role', true );
				$redirect_url = get_post_meta( get_the_ID(), self::$text_domain . '_redirect_url', true );
			}

			if (!empty($redirect_url) && !filter_var($redirect_url, FILTER_VALIDATE_URL) === true) {
				$redirect_url = '';
			}

			if($restrict_access == 1 && !empty($selected_roles)) {
				$user = wp_get_current_user();
				$assigned_roles = $user->roles;

				foreach($assigned_roles as $role) {

					if(in_array($role, $selected_roles) || current_user_can('administrator') || current_user_can('editor')) {
						return $content;
					} 
				}

				if(!empty($redirect_url)) { 
					wp_redirect($redirect_url, 302);
					exit;
				} else {

					$content = '<div class="'.self::$text_domain . '_403">
									<div class="'.self::$text_domain . '_403_icon">
										<i class="fa fa-lock"></i>
									</div>
									<div class="pcr-rpbr_403_heading">
										<h2><span>'. __( 'Restricted Content') .'</span></h2>
									</div>
									<p> '.__( 'Apologies, you do not have the proper permissions to access this page.') .'</p>
									<div>
						  				<a href="'.get_home_url().'" class="btn btn-default">
						  					<i class="fa fa-arrow-left"></i>' . __('Back on the home page'). '
						  				</a>
						  			</div>
						  		</div>';
				}

			}
			return $content;
		}

		public function strip_hard_links($content) {


		    if (current_user_can('administrator') || current_user_can('editor')) {
		        return $content;
		    }
			
		    // # use DOMDocument to capture all of the anchor tags inside the_content
		    $dom = new DOMDocument;
		    @$dom->loadHTML($content);
		    foreach ($dom->getElementsByTagName('a') as $node) {
		        if (!$node->hasAttribute('href')) {
		            continue;
		        }
		 
		 		// # assign var to dom object href
		 		$href = $node->getAttribute('href');

		        // # now use wordpress native function url_to_postid()
		        // # url_to_postid() converts a pretty URL to a post ID.
		        $post_id = url_to_postid($href);

		        if ($post_id > 0) {
		            $restrict_access = (int)get_post_meta($post_id, self::$text_domain . '_restrict_access', true);
		            $select_roles    = get_post_meta($post_id, self::$text_domain . '_select_role', true);
		            if ($restrict_access !== 1 || empty($select_roles)) {
		                continue;
		            }
		 
		            $select_roles   = array_unique($select_roles);
		            $user_can_roles = array_filter($select_roles, 'current_user_can');
		 
		            if (count($user_can_roles) === 0) {
		                $li = $node->parentNode;
		                if ($li->nodeName === 'li') {
		                    $li->parentNode->removeChild($li);
		                }
		            }
		        }
		    }
		 
		    return $dom->saveHTML();
		}

		/**
		 * Restrict content page
		 *
		 * @since 1.0.0
		 *
		 * @param string $content The content page
		 * @return string $content
		 */
/*
		// # if using hook 'document_title_parts', use second parm $sep
		// # if using hook 'wp_title', remove second parm
		public function restrict_filter_title( $title, $sep ) {

//error_log(print_r($title,1));
		    $title['site'] = 'Restricted Content';
		    $title['tagline'] = 'Apologies, you do not have the proper permissions to access this page';

		    return $title;
		}
*/
		/**
		 * Restrict content page
		 *
		 * @since 1.0.0
		 *
		 * @param string $content The content page
		 * @return string $content
		 */
		public function restrict_page_title($title) {

			// # clean up the item values, remove whitespace and replace ampersand
			$title = trim(preg_replace('/\s+/', ' ', $title));
			$title = str_replace('&#038;', '&', $title);
			
			if(!empty($title)) {
				$post = get_page_by_title($title);

				if(!empty($post->ID) && $post->ID != '') {
					$post_id = $post->ID;

					$restrict_access = get_post_meta($post_id, self::$text_domain . '_restrict_access', true );
					$select_roles = get_post_meta($post_id, self::$text_domain . '_select_role', true );

					if($restrict_access == 1 && !empty($select_roles) && !is_admin()) {

						foreach ($select_roles as $roles) {

							if(!empty($roles)) {

								if(current_user_can($roles) || current_user_can('administrator') || current_user_can('editor')) {
									continue;
								} else {
									$title = __( 'Restricted Content', 'pcr-rpbr' );
								}
							}
						}
					}
				}
			}

			return $title;
		}

		public function invalid_redirect_url_notice() {

			$class = 'notice notice-error';
			$message = __( 'Not a valid URL!', 'pcr-rpbr' );

			printf( '<div class="%1$s"><p>%2$s</p></div>', $class, $message ); 
		}

	} // end class restrict_post_by_role();
	add_action( 'plugins_loaded', array( 'restrict_post_by_role', 'get_instance' ), 0 );