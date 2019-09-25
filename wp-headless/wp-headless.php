<?php

/*
Plugin Name: WP Headless
Plugin URI: https://github.com/DrewDahlman/wp-headless
Description: A simple plugin for publishing static JSON files from Wordpress for a headless CMS.
Version: 1.0.0
Author: Drew Dahlman
Author URI: http://drewdahlman.com/
License: GPLv2
*/

// Exit if accessed directly.
if ( ! defined( "ABSPATH" ) ) {
	exit;
}

if( !class_exists("wpheadless") ){
	// Deps
	include_once("lib/uploader.php");

	class wpheadless {

		// Setup vars
		var $version = "1.0.0";
		var $settings = array();
		var $posts;

		/*
		------------------------------------------
		| construct:void (-)
		|
		| Create our class.
		|
		| @env:string - The environment to publish
		------------------------------------------ */
		function __construct( $env = '' ){

			// Settings
			$this->settings = array(
				"name"				=> "WP Headless",
				"version" 		=> $this->version,
				"link"				=> "https://github.com/DrewDahlman/wp-headless",
				"files"				=> array(),
				"env"					=> $env,
				"tmp_dir"			=> get_template_directory() . "/data" . "/",
				"content"			=> get_field("content", "options"),
				"dest"				=> get_field("destination", "options") == "" ? "wp-headless-data/" : get_field("destination", "options"),
				"sitemap_domain" => get_field("sitemap-domain", "options"),
				"webhook" => $env == "production" ? get_field("webhook-production", "options") : get_field("webhook-staging", "options"),
			);

			// Format the destination if needed
			if( substr($this->settings["dest"], -1) != "/" ){
				$this->settings["dest"] .= "/";
			}
			if( $this->settings["dest"][0] == "/" ){
				$this->settings["dest"] = substr($this->settings["dest"], 1);
			}

			// Make webhook false if string
			if($this->settings["webhook"] == "") $this->settings["webhook"] = false;

		}

		/*
		------------------------------------------
		| publish:void (-)
		|
		| Publishes the content
		------------------------------------------ */
		function publish(){
			$sitemap_items = array();
			foreach( $this->settings["content"] as $content ){

				// Define the filename
				$file_name = $this->settings["env"] . "-" . $content["file_name"] . ".json";
				$file_path = $this->settings["tmp_dir"] . $file_name;
				$file_content = array();

				// loop the content
				foreach( $content["content"] as $post ){
					$parsed_post = $this->parsePost( $post, array() );
					array_push( $file_content, $parsed_post);
					array_push( $sitemap_items, array($parsed_post['uri'], $parsed_post['post_modified']));
				}

				// Ensure temp directory exists
				if( !file_exists( $this->settings["tmp_dir"] )){
					mkdir( $this->settings["tmp_dir"] );
				}

				// Write the temp file
				$fp = fopen( $file_path, "w");
				fwrite($fp, json_encode($file_content));
				fclose($fp);

				// Create the uploader
				$this->uploader = new Uploader($file_name, $file_path, $this->settings["dest"] );

				// Upload
				if( $this->uploader->canUpload() ){
					if( $this->uploader->upload() ){

						// Success
						include("views/success.php");

						// Remove temp files
						unlink($file_path);
						rmdir($this->settings["tmp_dir"]);
					} else {
						include("views/error.php");
					}
				} else {
					$local_path = get_template_directory_uri() . "/data" . "/" . $file_name;
					include("views/success-local.php");
				}
			}

			// Final Things
			$this->callWebhook();
			$this->generateSitemap($sitemap_items);

		}

		/*
		------------------------------------------
		| callWebhook:void (-)
		|
		| Calls the webhook
		------------------------------------------ */
		function callWebhook(){
			if($this->settings["webhook"]){
				// echo 'WEBHOOK: '; print_r($this->settings["webhook"]);
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, $this->settings["webhook"]);
				curl_setopt($ch, CURLOPT_POST, 1);
				curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Length: 0'));
				$result = curl_exec($ch);
				curl_close($ch);
			}
		}

		/*
		------------------------------------------
		| generateSitemap:void (-)
		|
		| Publishes the sitemap
		| @content:array - Array for content
		------------------------------------------ */
		function generateSitemap($sitemap_items){

			// Define the filename
			$file_name = $this->settings["env"] . "-sitemap.xml";
			$file_path = $this->settings["tmp_dir"] . $file_name;
			$file_content = '';

			//Get all posts (not included in json content) and add them to the list of items
			$posts = array();
			$args = array(
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'post_type'      => array('post'),
			);
			$the_query = new WP_Query($args);
			while($the_query->have_posts()) {
				$the_query->the_post();
				$item = array(str_replace(home_url(), '', get_permalink()), get_post_modified_time('Y-m-d G:i:s'));
				array_push($sitemap_items, $item);
			}

			//Generate xml from items
			$xml = new SimpleXMLElement('<urlset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd http://www.google.com/schemas/sitemap-image/1.1 http://www.google.com/schemas/sitemap-image/1.1/sitemap-image.xsd" xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>');
			foreach($sitemap_items as $item){
			    $url = $xml->addChild('url');
			    $url->addChild('loc', $this->settings["sitemap_domain"] . $item[0]);
			    $url->addChild('lastmod', $item[1]);
			}
			$file_content = $xml->asXML();

			// Ensure temp directory exists
			if( !file_exists( $this->settings["tmp_dir"] )){
				mkdir( $this->settings["tmp_dir"] );
			}

			// Write the temp file
			$fp = fopen( $file_path, "w");
			fwrite($fp, $file_content);
			fclose($fp);

			// Create the uploader
			$this->uploader = new Uploader($file_name, $file_path, $this->settings["dest"] );

			// Upload
			if( $this->uploader->canUpload() ){
				if( $this->uploader->upload('xml') ){

					// Success
					include("views/success.php");

					// Remove temp files
					unlink($file_path);
					rmdir($this->settings["tmp_dir"]);
				} else {
					include("views/error.php");
				}
			} else {
				$local_path = get_template_directory_uri() . "/data" . "/" . $file_name;
				include("views/success-local.php");
			}
		}

		/*
		------------------------------------------
		| generateRandomString:string (-)
		|
		| Generates a random string
		------------------------------------------ */
		function generateRandomString($length = 10) {
	    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
	    $charactersLength = strlen($characters);
	    $randomString = '';
	    for ($i = 0; $i < $length; $i++) {
	        $randomString .= $characters[rand(0, $charactersLength - 1)];
	    }
	    return $randomString;
		}

		/*
		------------------------------------------
		| parsePost:array (-)
		|
		| Parses a post object recursively.
		|
		| @post:obj - A wordpress post object
		| @content:array - Array for content
		| @nested:bool - Flag for 1 level deep nesting
		------------------------------------------ */
		function parsePost( $post, $content, $nested = false ){
			// Setup the post content holder
			$content = array();

			// Essentials and base wordpress details
			foreach( $post as $post_field_key => $post_field_value ){
				$content[$post_field_key] = do_shortcode($post_field_value);
			}

			// URI for sitemap
			$content['uri'] = str_replace(home_url(), '', get_permalink($post->ID));

			// Loop custom fields
			if( get_fields( $post->ID ) ){
				foreach( get_fields($post->ID) as $post_custom_field_key => $post_custom_field_value ){

					// Check for a relationshop or a post object and process
					if( get_field_object($post_custom_field_key, $post->ID)['type'] == "relationship" ){
						if( gettype($post_custom_field_value) == "array" ){
							foreach($post_custom_field_value as $post_related_key => $post_related_value ){
								if( gettype($post_related_value) == "object" ){
									$content[$post_custom_field_key][$post_related_key] = $this->parsePost($post_related_value, array(), true);
								} else {
									$content[$post_custom_field_key][$post_related_key] = $post_related_value;
								}
							}
						}
					} else if( get_field_object($post_custom_field_key, $post->ID)['type'] == "post_object" ){

						if( gettype($post_custom_field_value) == "object" ){
							$content[$post_custom_field_key] = $this->parsePost($post_custom_field_value, array(), true);
						} else {
							$content[$post_custom_field_key] = gettype($post_custom_field_value) == "string" ? do_shortcode($post_custom_field_value) : $post_custom_field_value;
						}

					} else {
						$content[$post_custom_field_key] = gettype($post_custom_field_value) == "string" ? do_shortcode($post_custom_field_value) : $post_custom_field_value;
					}
				}
			}

			return $content;
		}

	}

	/*******************************************************
	 *
	 * Setup
	 * Setup the plugin add hooks & check requirements
	 *
	 * Version: 1.0.0
	 * @init:function - Runs a requirement check
	 * 	- If requirements are met, builds menus
	 *
	********************************************************/
	function init() {

		$pass = true;
		$dependencies = array(
			array(
				"name" 		 => "Advanced Custom Fields Pro",
				"file" 		 => "advanced-custom-fields-pro/acf.php",
				"link" 		 => "https://wordpress.org/plugins/advanced-custom-fields/",
				"required" => true
			),
			array(
				"name" 		 => "Amazon Web Services",
				"file" 		 => "amazon-web-services/amazon-web-services.php",
				"link" 		 => "https://wordpress.org/plugins/amazon-web-services/",
				"required" => true
			),
			array(
				"name" 		 => "WP Offload",
				"file" 		 => "amazon-s3-and-cloudfront/wordpress-s3.php",
				"link" 		 => "https://wordpress.org/plugins/amazon-web-services/",
				"required" => false
			)
		);

		// Loop deps and error if required
		foreach( $dependencies as $dependency ){
			if( !is_plugin_active( $dependency["file"] ) ){
				if( $dependency['required'] ){
					$class = "notice notice-error";
					$message = "WP Headless requires " . $dependency["name"] . " <a href='" . $dependency["link"] . "'>Click to download</a>";
					$pass = false;
				} else {
					$class = "notice notice-error is-dismissable";
					$message = "WP Headless doesn't require " . $dependency["name"] . " but having it installed will make things better. <a href='" . $dependency["link"] . "'>Click to download</a>";
				}
				include("views/dependency-error.php");
			}
		}

		// If no errors then create the options pages
		if( $pass ){

			// Create the publish pages and menu item
			add_menu_page( 'Publish Site', 'Publish Site', 'edit_others_posts', 'publish-site', 'about' );

			// Create the options page for ACF
			acf_add_options_page(array(
				"page_title" 	=> "Publish Settings",
				"menu_title"	=> "Content",
				"menu_slug" 	=> "publish-settings",
				"capability"	=> "edit_posts",
				"parent"			=> "publish-site",
				"redirect"		=> false,
				"position"		=> 0,
				"autoload"		=> true
			));

			// Create publish staging
			add_submenu_page('publish-site', 'Publish Staging', 'Publish Staging', 'edit_others_posts', 'publish-staging', 'publish_staging', 1);

			// Create publish production
			add_submenu_page('publish-site', 'Publish Production', 'Publish Production', 'edit_others_posts', 'publish-production', 'publish_production', 2);
		}

		/*
		------------------------------------------
		| about:void (-)
		------------------------------------------ */
		function about(){
			$headless = new wpheadless();
			require('views/about.php');
		}

		/*
		------------------------------------------
		| publish_staging:void (-)
		|
		| Publishes the site in staging
		------------------------------------------ */
		function publish_staging(){
			$headless = new wpheadless('staging');
			$headless->publish();
		}

		/*
		------------------------------------------
		| publish_production:void (-)
		|
		| Publishes the site in staging
		------------------------------------------ */
		function publish_production(){
			$headless = new wpheadless('production');
			$headless->publish();
		}



	}

	/*
	------------------------------------------
	| loadFields:void (-)
	|
	| Load the settings fields
	------------------------------------------ */
	function loadFields(){
		require("lib/settings-fields.php");
	}

	add_action('admin_menu', 'init');
	add_action('wp_loaded', 'loadFields');
}

?>
