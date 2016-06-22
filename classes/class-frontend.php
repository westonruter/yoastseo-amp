<?php
/**
 * @package     YoastSEO_AMP_Glue\Frontend
 * @author      Joost de Valk
 * @copyright   2016 Yoast BV
 * @license     GPL-2.0+
 */

if ( ! class_exists( 'YoastSEO_AMP_Frontend' ) ) {
	/**
	 * This class improves upon the AMP output by the default WordPress AMP plugin using Yoast SEO metadata.
	 */
	class YoastSEO_AMP_Frontend {

		/**
		 * @var WPSEO_Frontend
		 */
		private $front;

		/**
		 * @var array
		 */
		private $options;

		/**
		 * @var array
		 */
		private $wpseo_options;

		/**
		 * YoastSEO_AMP_Frontend constructor.
		 */
		public function __construct() {
			$this->set_options();

			if ( $this->wpseo_options['opengraph'] === true ) {
				$GLOBALS['wpseo_og'] = new WPSEO_OpenGraph;
			}

			add_action( 'amp_init', array( $this, 'post_types' ) );

			add_action( 'amp_post_template_css', array( $this, 'additional_css' ) );
			add_action( 'amp_post_template_head', array( $this, 'extra_head' ) );
			add_action( 'amp_post_template_footer', array( $this, 'extra_footer' ) );

			add_filter( 'the_content', array( $this, 'add_social' ) );

			add_filter( 'amp_post_template_data', array( $this, 'fix_amp_post_data' ) );
			add_filter( 'amp_post_template_metadata', array( $this, 'fix_amp_post_metadata' ), 10, 2 );
			add_filter( 'amp_post_template_analytics', array( $this, 'analytics' ) );

			add_filter( 'amp_content_sanitizers', array( $this, 'add_sanitizer' ) );
		}

		private function set_options() {
			$this->wpseo_options = WPSEO_Options::get_all();
			$this->options       = YoastSEO_AMP_Options::get();
		}

		/**
		 * Add our own sanitizer to the array of sanitizers
		 *
		 * @param array $sanitizers
		 *
		 * @return array
		 */
		public function add_sanitizer( $sanitizers ) {
			require_once 'class-sanitizer.php';

			$sanitizers['Yoast_AMP_Blacklist_Sanitizer'] = array();

			return $sanitizers;
		}

		/**
		 * If analytics tracking has been set, output it now.
		 *
		 * @param array $analytics
		 *
		 * @return array
		 */
		public function analytics( $analytics ) {
			if ( isset( $this->options['analytics-extra'] ) && ! empty( $this->options['analytics-extra'] ) ) {
				return $analytics;
			}

			if ( ! class_exists( 'Yoast_GA_Options' ) || Yoast_GA_Options::instance()->get_tracking_code() === null ) {
				return $analytics;
			}
			$UA = Yoast_GA_Options::instance()->get_tracking_code();

			$analytics['yst-googleanalytics'] = array(
				'type'        => 'googleanalytics',
				'attributes'  => array(),
				'config_data' => array(
					'vars'     => array(
						'account' => $UA
					),
					'triggers' => array(
						'trackPageview' => array(
							'on'      => 'visible',
							'request' => 'pageview',
						),
					),
				),
			);

			return $analytics;
		}

		/**
		 * Make AMP work for all the post types we want it for
		 */
		public function post_types() {
			$post_types = get_post_types( array( 'public' => true ), 'objects' );
			if ( is_array( $post_types ) && $post_types !== array() ) {
				foreach ( $post_types as $pt ) {
					if ( ! isset( $this->options[ 'post_types-' . $pt->name . '-amp' ] ) ) {
						continue;
					}
					if ( $this->options[ 'post_types-' . $pt->name . '-amp' ] === 'on' ) {
						add_post_type_support( $pt->name, AMP_QUERY_VAR );

						return;
					}
					if ( 'post' === $pt->name ) {
						add_action( 'wp', array( $this, 'disable_amp_for_posts' ) );

						return;
					}
					remove_post_type_support( $pt->name, AMP_QUERY_VAR );
				}
			}
		}

		/**
		 * Disables AMP for posts specifically, run later because of AMP plugin internals
		 */
		public function disable_amp_for_posts() {
			remove_post_type_support( 'post', AMP_QUERY_VAR );
		}

		/**
		 * Fix the basic AMP post data
		 *
		 * @param array $data
		 *
		 * @return array
		 */
		public function fix_amp_post_data( $data ) {
			$data['canonical_url'] = $this->front->canonical( false );
			if ( ! empty( $this->options['amp_site_icon'] ) ) {
				$data['site_icon_url'] = $this->options['amp_site_icon'];
			}

			// If we are loading extra analytics, we need to load the module too.
			if ( ! empty( $this->options['analytics-extra'] ) ) {
				$data['amp_component_scripts']['amp-analytics'] = 'https://cdn.ampproject.org/v0/amp-analytics-0.1.js';
			}
			$data['amp_component_scripts']['amp-social-share'] = 'https://cdn.ampproject.org/v0/amp-social-share-0.1.js';

			return $data;
		}

		/**
		 * Fix the AMP metadata for a post
		 *
		 * @param array $metadata
		 * @param WP_Post $post
		 *
		 * @return array
		 */
		public function fix_amp_post_metadata( $metadata, $post ) {
			$this->front = WPSEO_Frontend::get_instance();

			$this->build_organization_object( $metadata );

			$desc = $this->front->metadesc( false );
			if ( $desc ) {
				$metadata['description'] = $desc;
			}

			$image = isset( $metadata['image'] ) ? $metadata['image'] : null;

			$metadata['image'] = $this->get_image( $post, $image );
			$metadata['@type'] = $this->get_post_schema_type( $post );

			return $metadata;
		}

		/**
		 * Add additional CSS to the AMP output
		 */
		public function additional_css() {

			require 'views/additional-css.php';

			$css_builder = new YoastSEO_AMP_CSS_Builder();
			$css_builder->add_option( 'header-color', 'nav.amp-wp-title-bar', 'background' );
			$css_builder->add_option( 'headings-color', '.amp-wp-title, h2, h3, h4', 'color' );
			$css_builder->add_option( 'text-color', '.amp-wp-content', 'color' );

			$css_builder->add_option( 'blockquote-bg-color', '.amp-wp-content blockquote', 'background-color' );
			$css_builder->add_option( 'blockquote-border-color', '.amp-wp-content blockquote', 'border-color' );
			$css_builder->add_option( 'blockquote-text-color', '.amp-wp-content blockquote', 'color' );

			$css_builder->add_option( 'link-color', 'a, a:active, a:visited', 'color' );
			$css_builder->add_option( 'link-color-hover', 'a:hover, a:focus', 'color' );

			$css_builder->add_option( 'meta-color', '.amp-wp-meta li, .amp-wp-meta li a', 'color' );

			echo $css_builder->build();

			if ( ! empty( $this->options['extra-css'] ) ) {
				$safe_text = strip_tags( $this->options['extra-css'] );
				$safe_text = wp_check_invalid_utf8( $safe_text );
				$safe_text = _wp_specialchars( $safe_text, ENT_NOQUOTES );
				echo $safe_text;
			}

			echo '.social-box {
		max-width: 100%;
      }
      amp-social-share[type=whatsapp] {
        background-color: #25d366;
        background-image: url(\'data:image/svg+xml;utf8,%3Csvg%20version%3D%221.1%22%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20xmlns%3Axlink%3D%22http%3A%2F%2Fwww.w3.org%2F1999%2Fxlink%22%20x%3D%220px%22%20y%3D%220px%22%0A%09%20viewBox%3D%220%200%20512%20514.5%22%20style%3D%22enable-background%3Anew%200%200%20512%20514.5%3B%22%20xml%3Aspace%3D%22preserve%22%3E%0A%3Cstyle%20type%3D%22text%2Fcss%22%3E%0A%09.st0%7Bdisplay%3Anone%3B%7D%0A%09.st1%7Bdisplay%3Ainline%3Bfill%3A%2325D366%3B%7D%0A%09.st2%7Bfill-rule%3Aevenodd%3Bclip-rule%3Aevenodd%3Bfill%3A%23FFFFFF%3B%7D%0A%3C%2Fstyle%3E%0A%3Cg%20id%3D%22background_1_%22%20class%3D%22st0%22%3E%0A%09%3Cpath%20id%3D%22background%22%20class%3D%22st1%22%20d%3D%22M876.2%2C788.2H-364.4c-11%2C0-19.9-8.9-19.9-19.9V-258.8c0-11%2C8.9-19.9%2C19.9-19.9H876.2%0A%09%09c11%2C0%2C19.9%2C8.9%2C19.9%2C19.9V768.4C896%2C779.3%2C887.1%2C788.2%2C876.2%2C788.2z%22%2F%3E%0A%3C%2Fg%3E%0A%3Cg%20id%3D%22WhatsApp_Logo%22%3E%0A%09%3Cg%20id%3D%22WA_Logo%22%3E%0A%09%09%3Cg%3E%0A%09%09%09%3Cpath%20class%3D%22st2%22%20d%3D%22M404.4%2C108C365%2C68.5%2C312.7%2C46.8%2C256.9%2C46.8c-115%2C0-208.5%2C93.6-208.6%2C208.5c0%2C36.8%2C9.6%2C72.6%2C27.8%2C104.3%0A%09%09%09%09L46.6%2C467.7l110.6-29c30.5%2C16.6%2C64.8%2C25.4%2C99.7%2C25.4h0.1c0%2C0%2C0%2C0%2C0%2C0c114.9%2C0%2C208.5-93.6%2C208.6-208.6%0A%09%09%09%09C465.5%2C199.8%2C443.8%2C147.4%2C404.4%2C108z%20M256.9%2C428.8L256.9%2C428.8c-31.2%2C0-61.7-8.4-88.3-24.2l-6.3-3.8l-65.6%2C17.2l17.5-64l-4.1-6.6%0A%09%09%09%09c-17.4-27.6-26.5-59.5-26.5-92.2C83.6%2C159.8%2C161.3%2C82%2C256.9%2C82c46.3%2C0%2C89.8%2C18.1%2C122.6%2C50.8c32.7%2C32.8%2C50.7%2C76.3%2C50.7%2C122.6%0A%09%09%09%09C430.2%2C351.1%2C352.4%2C428.8%2C256.9%2C428.8z%20M352%2C299c-5.2-2.6-30.8-15.2-35.6-17c-4.8-1.7-8.3-2.6-11.7%2C2.6%0A%09%09%09%09c-3.5%2C5.2-13.5%2C17-16.5%2C20.4c-3%2C3.5-6.1%2C3.9-11.3%2C1.3c-5.2-2.6-22-8.1-41.9-25.9c-15.5-13.8-25.9-30.9-29-36.1%0A%09%09%09%09c-3-5.2-0.3-8%2C2.3-10.6c2.3-2.3%2C5.2-6.1%2C7.8-9.1c2.6-3%2C3.5-5.2%2C5.2-8.7c1.7-3.5%2C0.9-6.5-0.4-9.1c-1.3-2.6-11.7-28.3-16.1-38.7%0A%09%09%09%09c-4.2-10.2-8.5-8.8-11.7-8.9c-3-0.2-6.5-0.2-10-0.2c-3.5%2C0-9.1%2C1.3-13.9%2C6.5c-4.8%2C5.2-18.2%2C17.8-18.2%2C43.5%0A%09%09%09%09c0%2C25.7%2C18.7%2C50.4%2C21.3%2C53.9c2.6%2C3.5%2C36.7%2C56.1%2C89%2C78.7c12.4%2C5.4%2C22.1%2C8.6%2C29.7%2C11c12.5%2C4%2C23.8%2C3.4%2C32.8%2C2.1%0A%09%09%09%09c10-1.5%2C30.8-12.6%2C35.2-24.8c4.3-12.2%2C4.3-22.6%2C3-24.8C360.6%2C302.9%2C357.2%2C301.6%2C352%2C299z%22%2F%3E%0A%09%09%3C%2Fg%3E%0A%09%3C%2Fg%3E%0A%3C%2Fg%3E%0A%3C%2Fsvg%3E\');
        text-align: center;
        color: #0e5829;
        font-size: 18px;
        padding: 10px;
      }';
		}

		/**
		 * Outputs extra code in the head, if set
		 */
		public function extra_head() {
			$options = WPSEO_Options::get_option( 'wpseo_social' );

			if ( $options['twitter'] === true ) {
				WPSEO_Twitter::get_instance();
			}

			do_action( 'wpseo_opengraph' );

			echo strip_tags( $this->options['extra-head'], '<link><meta>' );
		}

		/**
		 * Outputs analytics code in the footer, if set
		 */
		public function extra_footer() {
			echo $this->options['analytics-extra'];
		}


		public function add_social( $content ) {
			$content .= '<div class="social-box">';
			$content .= '<amp-social-share type="facebook" data-param-app_id="1029144593839710"></amp-social-share>';
			$content .= '
				<amp-social-share type="twitter"></amp-social-share>
				<amp-social-share type="linkedin"></amp-social-share>
				<amp-social-share type="pinterest"></amp-social-share>
				<amp-social-share type="email"></amp-social-share>
			</div>';

			return $content;
		}

		/**
		 * Builds the organization object if needed.
		 *
		 * @param array $metadata
		 */
		private function build_organization_object( &$metadata ) {
			// While it's using the blog name, it's actually outputting the company name.
			if ( ! empty( $this->wpseo_options['company_name'] ) ) {
				$metadata['publisher']['name'] = $this->wpseo_options['company_name'];
			}

			// The logo needs to be 600px wide max, 60px high max.
			$logo = $this->get_image_object( $this->wpseo_options['company_logo'], array( 600, 60 ) );
			if ( is_array( $logo ) ) {
				$metadata['publisher']['logo'] = $logo;
			}
		}

		/**
		 * Builds an image object array from an image URL
		 *
		 * @param string $image_url
		 * @param string|array $size Optional. Image size. Accepts any valid image size, or an array of width
		 *                                     and height values in pixels (in that order). Default 'full'.
		 *
		 * @return array|false
		 */
		private function get_image_object( $image_url, $size = 'full' ) {
			if ( empty( $image_url ) ) {
				return false;
			}

			$image_id  = attachment_url_to_postid( $image_url );
			$image_src = wp_get_attachment_image_src( $image_id, $size );

			if ( is_array( $image_src ) ) {
				return array(
					'@type'  => 'ImageObject',
					'url'    => $image_src[0],
					'width'  => $image_src[1],
					'height' => $image_src[2]
				);
			}

			return false;
		}

		/**
		 * Retrieve the Schema.org image for the post
		 *
		 * @param WP_Post $post
		 * @param array|null $image The currently set post image
		 *
		 * @return array
		 */
		private function get_image( $post, $image ) {
			$og_image = $this->get_image_object( WPSEO_Meta::get_value( 'opengraph-image', $post->ID ) );
			if ( is_array( $og_image ) ) {
				return $og_image;
			}

			// Posts without an image fail validation in Google, leading to Search Console errors
			if ( ! is_array( $image ) && isset( $this->options['default_image'] ) ) {
				return $this->get_image_object( $this->options['default_image'] );
			}

			return $image;
		}

		/**
		 * Gets the Schema.org type for the post, based on the post type.
		 *
		 * @param WP_Post $post
		 *
		 * @return string
		 */
		private function get_post_schema_type( $post ) {
			$type = 'WebPage';
			if ( 'post' === $post->post_type ) {
				$type = 'Article';
			}

			/**
			 * Filter: 'yoastseo_amp_schema_type' - Allow changing the Schema.org type for the post
			 *
			 * @api string $type The Schema.org type for the $post
			 *
			 * @param WP_Post $post
			 */
			$type = apply_filters( 'yoastseo_amp_schema_type', $type, $post );

			return $type;
		}
	}
}
