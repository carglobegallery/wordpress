 (cd "$(git rev-parse --show-toplevel)" && git apply --3way <<'EOF' 
diff --git a/envira-instagram-bridge/envira-instagram-bridge.php b/envira-instagram-bridge/envira-instagram-bridge.php
new file mode 100644
index 0000000000000000000000000000000000000000..afe9f06a801001a6607d85a80909315c973a3c08
--- /dev/null
+++ b/envira-instagram-bridge/envira-instagram-bridge.php
@@ -0,0 +1,876 @@
+<?php
+/**
+ * Plugin Name: Envira Instagram Bridge
+ * Description: Automates publishing random Envira galleries as Instagram carousels via the Instagram Graph API.
+ * Version: 0.1.0
+ * Author: OpenAI Assistant
+ * License: GPLv2 or later
+ * License URI: https://www.gnu.org/licenses/gpl-2.0.html
+ * Text Domain: envira-instagram-bridge
+ */
+
+if ( ! defined( 'ABSPATH' ) ) {
+    exit;
+}
+
+if ( ! defined( 'ENVIRA_INSTAGRAM_BRIDGE_FILE' ) ) {
+    define( 'ENVIRA_INSTAGRAM_BRIDGE_FILE', __FILE__ );
+}
+
+if ( ! class_exists( 'Envira_Instagram_Bridge' ) ) {
+    /**
+     * Main plugin bootstrap.
+     */
+    class Envira_Instagram_Bridge {
+
+        const OPTION_KEY = 'envira_instagram_bridge_settings';
+        const CRON_HOOK  = 'envira_instagram_bridge_publish_event';
+
+        /**
+         * Singleton instance.
+         *
+         * @var Envira_Instagram_Bridge
+         */
+        protected static $instance;
+
+        /**
+         * Cached settings array.
+         *
+         * @var array
+         */
+        protected $settings = [];
+
+        /**
+         * Admin handler instance.
+         *
+         * @var Envira_Instagram_Bridge_Admin
+         */
+        protected $admin;
+
+        /**
+         * Publisher handler instance.
+         *
+         * @var Envira_Instagram_Bridge_Publisher
+         */
+        protected $publisher;
+
+        /**
+         * Retrieve singleton instance.
+         *
+         * @return Envira_Instagram_Bridge
+         */
+        public static function get_instance() {
+            if ( ! self::$instance ) {
+                self::$instance = new self();
+            }
+
+            return self::$instance;
+        }
+
+        /**
+         * Constructor.
+         */
+        protected function __construct() {
+            $this->settings  = $this->get_settings();
+            $this->publisher = new Envira_Instagram_Bridge_Publisher( $this );
+            $this->admin     = new Envira_Instagram_Bridge_Admin( $this );
+
+            register_activation_hook( ENVIRA_INSTAGRAM_BRIDGE_FILE, [ $this, 'activate' ] );
+            register_deactivation_hook( ENVIRA_INSTAGRAM_BRIDGE_FILE, [ $this, 'deactivate' ] );
+
+            add_action( self::CRON_HOOK, [ $this, 'handle_scheduled_publish' ] );
+            add_action( 'init', [ $this, 'maybe_schedule_event' ] );
+            add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
+
+            $this->admin->init();
+        }
+
+        /**
+         * Load translation files.
+         */
+        public function load_textdomain() {
+            load_plugin_textdomain( 'envira-instagram-bridge', false, dirname( plugin_basename( ENVIRA_INSTAGRAM_BRIDGE_FILE ) ) . '/languages/' );
+        }
+
+        /**
+         * Activation callback.
+         */
+        public function activate() {
+            $this->maybe_schedule_event();
+        }
+
+        /**
+         * Deactivation callback.
+         */
+        public function deactivate() {
+            $timestamp = wp_next_scheduled( self::CRON_HOOK );
+
+            if ( $timestamp ) {
+                wp_unschedule_event( $timestamp, self::CRON_HOOK );
+            }
+        }
+
+        /**
+         * Ensure the cron event is scheduled based on settings.
+         */
+        public function maybe_schedule_event() {
+            $recurrence = $this->settings['schedule'] ?? 'daily';
+
+            if ( ! in_array( $recurrence, array_keys( wp_get_schedules() ), true ) ) {
+                $recurrence = 'daily';
+            }
+
+            $timestamp = wp_next_scheduled( self::CRON_HOOK );
+
+            if ( $timestamp ) {
+                $current_recurrence = wp_get_schedule( self::CRON_HOOK );
+
+                if ( $current_recurrence !== $recurrence ) {
+                    wp_unschedule_event( $timestamp, self::CRON_HOOK );
+                    $timestamp = false;
+                }
+            }
+
+            if ( ! $timestamp ) {
+                wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, self::CRON_HOOK );
+            }
+        }
+
+        /**
+         * Triggered by cron event to publish a carousel.
+         */
+        public function handle_scheduled_publish() {
+            $result = $this->publisher->publish_random_gallery();
+
+            if ( is_wp_error( $result ) ) {
+                $this->log( 'Scheduled publish failed: ' . $result->get_error_message() );
+            }
+        }
+
+        /**
+         * Retrieve plugin settings from the database.
+         *
+         * @return array
+         */
+        public function get_settings() {
+            $defaults = [
+                'app_id'           => '',
+                'app_secret'       => '',
+                'access_token'     => '',
+                'ig_user_id'       => '',
+                'caption_template' => __( 'Fotografie z galerie {gallery_title}.', 'envira-instagram-bridge' ),
+                'schedule'         => 'daily',
+                'image_count'      => 5,
+                'allowed_statuses' => [ 'publish' ],
+            ];
+
+            $settings = get_option( self::OPTION_KEY, [] );
+
+            return wp_parse_args( $settings, $defaults );
+        }
+
+        /**
+         * Persist plugin settings.
+         *
+         * @param array $settings Settings to save.
+         */
+        public function update_settings( array $settings ) {
+            $existing = $this->get_settings();
+            $settings = wp_parse_args( $settings, $existing );
+
+            update_option( self::OPTION_KEY, $settings );
+            $this->settings = $settings;
+
+            $this->maybe_schedule_event();
+        }
+
+        /**
+         * Return plugin setting by key.
+         *
+         * @param string $key Setting key.
+         * @param mixed  $default Default value.
+         * @return mixed
+         */
+        public function get_setting( $key, $default = null ) {
+            return $this->settings[ $key ] ?? $default;
+        }
+
+        /**
+         * Simple logger wrapper.
+         *
+         * @param string $message Message to log.
+         */
+        public function log( $message ) {
+            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
+                error_log( '[Envira Instagram Bridge] ' . $message );
+            }
+        }
+
+        /**
+         * Return publisher instance.
+         *
+         * @return Envira_Instagram_Bridge_Publisher
+         */
+        public function get_publisher() {
+            return $this->publisher;
+        }
+    }
+}
+
+if ( ! class_exists( 'Envira_Instagram_Bridge_Admin' ) ) {
+    /**
+     * Admin functionality.
+     */
+    class Envira_Instagram_Bridge_Admin {
+
+        /**
+         * Plugin instance.
+         *
+         * @var Envira_Instagram_Bridge
+         */
+        protected $plugin;
+
+        /**
+         * Constructor.
+         *
+         * @param Envira_Instagram_Bridge $plugin Plugin instance.
+         */
+        public function __construct( Envira_Instagram_Bridge $plugin ) {
+            $this->plugin = $plugin;
+        }
+
+        /**
+         * Initialise admin hooks.
+         */
+        public function init() {
+            add_action( 'admin_menu', [ $this, 'register_settings_page' ] );
+            add_action( 'admin_init', [ $this, 'register_settings' ] );
+            add_action( 'admin_post_envira_instagram_bridge_publish_now', [ $this, 'handle_manual_publish' ] );
+            add_action( 'admin_notices', [ $this, 'maybe_show_setup_notice' ] );
+        }
+
+        /**
+         * Register settings page.
+         */
+        public function register_settings_page() {
+            add_options_page(
+                __( 'Envira Instagram Bridge', 'envira-instagram-bridge' ),
+                __( 'Envira Instagram Bridge', 'envira-instagram-bridge' ),
+                'manage_options',
+                'envira-instagram-bridge',
+                [ $this, 'render_settings_page' ]
+            );
+        }
+
+        /**
+         * Register settings fields.
+         */
+        public function register_settings() {
+            register_setting( 'envira_instagram_bridge', Envira_Instagram_Bridge::OPTION_KEY, [ $this, 'sanitize_settings' ] );
+
+            add_settings_section(
+                'envira_instagram_bridge_api',
+                __( 'Instagram API', 'envira-instagram-bridge' ),
+                function () {
+                    esc_html_e( 'Vyplňte údaje z vaší Facebook aplikace a Instagram Business účtu.', 'envira-instagram-bridge' );
+                },
+                'envira-instagram-bridge'
+            );
+
+            add_settings_field(
+                'envira_instagram_bridge_app_id',
+                __( 'Facebook App ID', 'envira-instagram-bridge' ),
+                [ $this, 'render_text_field' ],
+                'envira-instagram-bridge',
+                'envira_instagram_bridge_api',
+                [
+                    'label_for'   => 'envira_instagram_bridge_app_id',
+                    'name'        => 'app_id',
+                    'description' => __( 'Identifikátor vaší Facebook aplikace.', 'envira-instagram-bridge' ),
+                ]
+            );
+
+            add_settings_field(
+                'envira_instagram_bridge_app_secret',
+                __( 'Facebook App Secret', 'envira-instagram-bridge' ),
+                [ $this, 'render_text_field' ],
+                'envira-instagram-bridge',
+                'envira_instagram_bridge_api',
+                [
+                    'label_for'   => 'envira_instagram_bridge_app_secret',
+                    'name'        => 'app_secret',
+                    'description' => __( 'Tajný klíč aplikace. Uchovává se pouze ve WordPress databázi.', 'envira-instagram-bridge' ),
+                ]
+            );
+
+            add_settings_field(
+                'envira_instagram_bridge_access_token',
+                __( 'Access token', 'envira-instagram-bridge' ),
+                [ $this, 'render_textarea_field' ],
+                'envira-instagram-bridge',
+                'envira_instagram_bridge_api',
+                [
+                    'label_for'   => 'envira_instagram_bridge_access_token',
+                    'name'        => 'access_token',
+                    'description' => __( 'Dlouhodobý Instagram access token. Plugin se pokusí token prodloužit, pokud zadáte krátkodobý token.', 'envira-instagram-bridge' ),
+                ]
+            );
+
+            add_settings_field(
+                'envira_instagram_bridge_user_id',
+                __( 'Instagram User ID', 'envira-instagram-bridge' ),
+                [ $this, 'render_text_field' ],
+                'envira-instagram-bridge',
+                'envira_instagram_bridge_api',
+                [
+                    'label_for'   => 'envira_instagram_bridge_user_id',
+                    'name'        => 'ig_user_id',
+                    'description' => __( 'Numerické ID Instagram Business/Creator účtu.', 'envira-instagram-bridge' ),
+                ]
+            );
+
+            add_settings_section(
+                'envira_instagram_bridge_behavior',
+                __( 'Chování publikace', 'envira-instagram-bridge' ),
+                function () {
+                    esc_html_e( 'Nastavte, jak často a co se má publikovat.', 'envira-instagram-bridge' );
+                },
+                'envira-instagram-bridge'
+            );
+
+            add_settings_field(
+                'envira_instagram_bridge_caption_template',
+                __( 'Šablona popisku', 'envira-instagram-bridge' ),
+                [ $this, 'render_textarea_field' ],
+                'envira-instagram-bridge',
+                'envira_instagram_bridge_behavior',
+                [
+                    'label_for'   => 'envira_instagram_bridge_caption_template',
+                    'name'        => 'caption_template',
+                    'description' => __( 'Dostupné zástupné symboly: {gallery_title}, {gallery_url}.', 'envira-instagram-bridge' ),
+                ]
+            );
+
+            add_settings_field(
+                'envira_instagram_bridge_image_count',
+                __( 'Počet obrázků', 'envira-instagram-bridge' ),
+                [ $this, 'render_number_field' ],
+                'envira-instagram-bridge',
+                'envira_instagram_bridge_behavior',
+                [
+                    'label_for'   => 'envira_instagram_bridge_image_count',
+                    'name'        => 'image_count',
+                    'min'         => 1,
+                    'max'         => 10,
+                    'description' => __( 'Počet snímků v carouselu (2–10 podle limitu Instagramu).', 'envira-instagram-bridge' ),
+                ]
+            );
+
+            add_settings_field(
+                'envira_instagram_bridge_schedule',
+                __( 'Frekvence', 'envira-instagram-bridge' ),
+                [ $this, 'render_schedule_field' ],
+                'envira-instagram-bridge',
+                'envira_instagram_bridge_behavior',
+                [
+                    'label_for'   => 'envira_instagram_bridge_schedule',
+                    'name'        => 'schedule',
+                    'description' => __( 'Výběr frekvence WP-Cron úlohy.', 'envira-instagram-bridge' ),
+                ]
+            );
+
+            add_settings_field(
+                'envira_instagram_bridge_statuses',
+                __( 'Stavy galerií', 'envira-instagram-bridge' ),
+                [ $this, 'render_status_checkboxes' ],
+                'envira-instagram-bridge',
+                'envira_instagram_bridge_behavior',
+                [
+                    'label_for'   => 'envira_instagram_bridge_statuses',
+                    'name'        => 'allowed_statuses',
+                    'description' => __( 'Které stavy Envira galerií mohou být publikovány.', 'envira-instagram-bridge' ),
+                ]
+            );
+        }
+
+        /**
+         * Sanitize callback used by register_setting.
+         *
+         * @param array $settings Raw settings.
+         * @return array
+         */
+        public function sanitize_settings( $settings ) {
+            $this->plugin->update_settings( (array) $settings );
+
+            return $this->plugin->get_settings();
+        }
+
+        /**
+         * Render settings page.
+         */
+        public function render_settings_page() {
+            if ( ! current_user_can( 'manage_options' ) ) {
+                return;
+            }
+
+            $settings = $this->plugin->get_settings();
+            ?>
+            <div class="wrap">
+                <h1><?php esc_html_e( 'Envira Instagram Bridge', 'envira-instagram-bridge' ); ?></h1>
+                <form action="options.php" method="post">
+                    <?php
+                    settings_fields( 'envira_instagram_bridge' );
+                    do_settings_sections( 'envira-instagram-bridge' );
+                    submit_button();
+                    ?>
+                </form>
+                <hr />
+                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
+                    <?php wp_nonce_field( 'envira_instagram_bridge_publish_now', '_envira_instagram_bridge_nonce' ); ?>
+                    <input type="hidden" name="action" value="envira_instagram_bridge_publish_now">
+                    <?php submit_button( __( 'Publikovat teď', 'envira-instagram-bridge' ), 'secondary' ); ?>
+                </form>
+                <p class="description">
+                    <?php esc_html_e( 'Kliknutím na „Publikovat teď“ okamžitě odešlete náhodnou galerii na Instagram s aktuálním nastavením.', 'envira-instagram-bridge' ); ?>
+                </p>
+                <?php $this->render_status_table( $settings ); ?>
+            </div>
+            <?php
+        }
+
+        /**
+         * Render table describing current configuration state.
+         *
+         * @param array $settings Plugin settings.
+         */
+        protected function render_status_table( array $settings ) {
+            $rows = [
+                __( 'Facebook App ID', 'envira-instagram-bridge' ) => ! empty( $settings['app_id'] ),
+                __( 'Facebook App Secret', 'envira-instagram-bridge' ) => ! empty( $settings['app_secret'] ),
+                __( 'Access token', 'envira-instagram-bridge' ) => ! empty( $settings['access_token'] ),
+                __( 'Instagram User ID', 'envira-instagram-bridge' ) => ! empty( $settings['ig_user_id'] ),
+            ];
+            ?>
+            <h2><?php esc_html_e( 'Stav konfigurace', 'envira-instagram-bridge' ); ?></h2>
+            <table class="widefat striped">
+                <thead>
+                    <tr>
+                        <th><?php esc_html_e( 'Položka', 'envira-instagram-bridge' ); ?></th>
+                        <th><?php esc_html_e( 'Stav', 'envira-instagram-bridge' ); ?></th>
+                    </tr>
+                </thead>
+                <tbody>
+                    <?php foreach ( $rows as $label => $enabled ) : ?>
+                        <tr>
+                            <td><?php echo esc_html( $label ); ?></td>
+                            <td>
+                                <?php if ( $enabled ) : ?>
+                                    <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
+                                    <span class="screen-reader-text"><?php esc_html_e( 'Vyplněno', 'envira-instagram-bridge' ); ?></span>
+                                <?php else : ?>
+                                    <span class="dashicons dashicons-warning" aria-hidden="true"></span>
+                                    <span class="screen-reader-text"><?php esc_html_e( 'Chybí', 'envira-instagram-bridge' ); ?></span>
+                                <?php endif; ?>
+                            </td>
+                        </tr>
+                    <?php endforeach; ?>
+                </tbody>
+            </table>
+            <?php
+        }
+
+        /**
+         * Handle manual publish action.
+         */
+        public function handle_manual_publish() {
+            if ( ! current_user_can( 'manage_options' ) ) {
+                wp_die( esc_html__( 'Nemáte oprávnění.', 'envira-instagram-bridge' ) );
+            }
+
+            check_admin_referer( 'envira_instagram_bridge_publish_now', '_envira_instagram_bridge_nonce' );
+
+            $result = $this->plugin->get_publisher()->publish_random_gallery();
+
+            if ( is_wp_error( $result ) ) {
+                $message = rawurlencode( $result->get_error_message() );
+                wp_safe_redirect( add_query_arg( 'envira_instagram_bridge_error', $message, wp_get_referer() ?: admin_url() ) );
+                exit;
+            }
+
+            wp_safe_redirect( add_query_arg( 'envira_instagram_bridge_success', 1, wp_get_referer() ?: admin_url() ) );
+            exit;
+        }
+
+        /**
+         * Display admin notice if required fields missing.
+         */
+        public function maybe_show_setup_notice() {
+            if ( ! current_user_can( 'manage_options' ) ) {
+                return;
+            }
+
+            if ( isset( $_GET['envira_instagram_bridge_success'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
+                echo '<div class="notice notice-success"><p>' . esc_html__( 'Publikace proběhla úspěšně.', 'envira-instagram-bridge' ) . '</p></div>';
+            }
+
+            if ( isset( $_GET['envira_instagram_bridge_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
+                $error = sanitize_text_field( wp_unslash( $_GET['envira_instagram_bridge_error'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
+                echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
+            }
+
+            $settings = $this->plugin->get_settings();
+            $required = [ 'app_id', 'app_secret', 'access_token', 'ig_user_id' ];
+
+            foreach ( $required as $key ) {
+                if ( empty( $settings[ $key ] ) ) {
+                    echo '<div class="notice notice-warning"><p>' . esc_html__( 'Dokončete nastavení Envira Instagram Bridge v Sekci Nastavení → Envira Instagram Bridge.', 'envira-instagram-bridge' ) . '</p></div>';
+                    break;
+                }
+            }
+        }
+
+        /**
+         * Render text field helper.
+         *
+         * @param array $args Field arguments.
+         */
+        public function render_text_field( array $args ) {
+            $settings = $this->plugin->get_settings();
+            $value    = $settings[ $args['name'] ] ?? '';
+            ?>
+            <input type="text" id="<?php echo esc_attr( $args['label_for'] ); ?>" name="<?php echo esc_attr( Envira_Instagram_Bridge::OPTION_KEY . '[' . $args['name'] . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
+            <?php if ( ! empty( $args['description'] ) ) : ?>
+                <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
+            <?php endif; ?>
+            <?php
+        }
+
+        /**
+         * Render textarea field helper.
+         *
+         * @param array $args Field arguments.
+         */
+        public function render_textarea_field( array $args ) {
+            $settings = $this->plugin->get_settings();
+            $value    = $settings[ $args['name'] ] ?? '';
+            ?>
+            <textarea id="<?php echo esc_attr( $args['label_for'] ); ?>" name="<?php echo esc_attr( Envira_Instagram_Bridge::OPTION_KEY . '[' . $args['name'] . ']' ); ?>" rows="4" class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
+            <?php if ( ! empty( $args['description'] ) ) : ?>
+                <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
+            <?php endif; ?>
+            <?php
+        }
+
+        /**
+         * Render number field helper.
+         *
+         * @param array $args Field arguments.
+         */
+        public function render_number_field( array $args ) {
+            $settings = $this->plugin->get_settings();
+            $value    = $settings[ $args['name'] ] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
+            ?>
+            <input type="number" id="<?php echo esc_attr( $args['label_for'] ); ?>" name="<?php echo esc_attr( Envira_Instagram_Bridge::OPTION_KEY . '[' . $args['name'] . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" min="<?php echo esc_attr( $args['min'] ); ?>" max="<?php echo esc_attr( $args['max'] ); ?>" />
+            <?php if ( ! empty( $args['description'] ) ) : ?>
+                <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
+            <?php endif; ?>
+            <?php
+        }
+
+        /**
+         * Render schedule select field.
+         *
+         * @param array $args Field arguments.
+         */
+        public function render_schedule_field( array $args ) {
+            $settings   = $this->plugin->get_settings();
+            $value      = $settings[ $args['name'] ] ?? 'daily';
+            $schedules  = wp_get_schedules();
+            ?>
+            <select id="<?php echo esc_attr( $args['label_for'] ); ?>" name="<?php echo esc_attr( Envira_Instagram_Bridge::OPTION_KEY . '[' . $args['name'] . ']' ); ?>">
+                <?php foreach ( $schedules as $key => $schedule ) : ?>
+                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>>
+                        <?php echo esc_html( $schedule['display'] ); ?>
+                    </option>
+                <?php endforeach; ?>
+            </select>
+            <?php if ( ! empty( $args['description'] ) ) : ?>
+                <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
+            <?php endif; ?>
+            <?php
+        }
+
+        /**
+         * Render allowed statuses checkboxes.
+         *
+         * @param array $args Field arguments.
+         */
+        public function render_status_checkboxes( array $args ) {
+            $settings = $this->plugin->get_settings();
+            $value    = (array) ( $settings[ $args['name'] ] ?? [ 'publish' ] );
+            $options  = [
+                'publish' => __( 'Publikováno', 'envira-instagram-bridge' ),
+                'draft'   => __( 'Koncept', 'envira-instagram-bridge' ),
+                'pending' => __( 'Čeká na schválení', 'envira-instagram-bridge' ),
+            ];
+            ?>
+            <?php foreach ( $options as $key => $label ) : ?>
+                <label>
+                    <input type="checkbox" name="<?php echo esc_attr( Envira_Instagram_Bridge::OPTION_KEY . '[' . $args['name'] . '][]' ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $value, true ) ); ?> />
+                    <?php echo esc_html( $label ); ?>
+                </label><br />
+            <?php endforeach; ?>
+            <?php if ( ! empty( $args['description'] ) ) : ?>
+                <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
+            <?php endif; ?>
+            <?php
+        }
+    }
+}
+
+if ( ! class_exists( 'Envira_Instagram_Bridge_Publisher' ) ) {
+    /**
+     * Handles gallery selection and API calls.
+     */
+    class Envira_Instagram_Bridge_Publisher {
+
+        /**
+         * Plugin instance.
+         *
+         * @var Envira_Instagram_Bridge
+         */
+        protected $plugin;
+
+        /**
+         * Constructor.
+         *
+         * @param Envira_Instagram_Bridge $plugin Plugin instance.
+         */
+        public function __construct( Envira_Instagram_Bridge $plugin ) {
+            $this->plugin = $plugin;
+        }
+
+        /**
+         * Publish a random gallery to Instagram.
+         *
+         * @return true|WP_Error
+         */
+        public function publish_random_gallery() {
+            $settings = $this->plugin->get_settings();
+
+            foreach ( [ 'app_id', 'app_secret', 'access_token', 'ig_user_id' ] as $key ) {
+                if ( empty( $settings[ $key ] ) ) {
+                    return new WP_Error( 'envira_instagram_bridge_missing_setting', sprintf( __( 'Chybí nastavení: %s', 'envira-instagram-bridge' ), $key ) );
+                }
+            }
+
+            $gallery = $this->get_random_gallery( (array) $settings['allowed_statuses'] );
+
+            if ( ! $gallery ) {
+                return new WP_Error( 'envira_instagram_bridge_no_gallery', __( 'Nebyly nalezeny žádné Envira galerie.', 'envira-instagram-bridge' ) );
+            }
+
+            $images = $this->get_gallery_images( $gallery, (int) $settings['image_count'] );
+
+            if ( empty( $images ) ) {
+                return new WP_Error( 'envira_instagram_bridge_no_images', __( 'Vybraná galerie neobsahuje dostatek obrázků.', 'envira-instagram-bridge' ) );
+            }
+
+            $containers = [];
+
+            foreach ( $images as $image ) {
+                $container = $this->create_media_container( $image['src'], $settings['access_token'], $settings['ig_user_id'] );
+
+                if ( is_wp_error( $container ) ) {
+                    return $container;
+                }
+
+                $containers[] = $container;
+            }
+
+            $caption  = $this->build_caption( $gallery, $settings['caption_template'] );
+            $carousel = $this->create_carousel( $containers, $caption, $settings['access_token'], $settings['ig_user_id'] );
+
+            if ( is_wp_error( $carousel ) ) {
+                return $carousel;
+            }
+
+            return $this->publish_media( $carousel, $settings['access_token'] );
+        }
+
+        /**
+         * Retrieve random Envira gallery.
+         *
+         * @param array $statuses Allowed statuses.
+         * @return WP_Post|null
+         */
+        protected function get_random_gallery( array $statuses ) {
+            $query = new WP_Query(
+                [
+                    'post_type'      => 'envira',
+                    'post_status'    => $statuses,
+                    'orderby'        => 'rand',
+                    'posts_per_page' => 1,
+                    'no_found_rows'  => true,
+                ]
+            );
+
+            return $query->have_posts() ? $query->posts[0] : null;
+        }
+
+        /**
+         * Retrieve Envira gallery images.
+         *
+         * @param WP_Post $gallery Gallery object.
+         * @param int     $count   Number of images to fetch.
+         * @return array
+         */
+        protected function get_gallery_images( WP_Post $gallery, $count ) {
+            $data = get_post_meta( $gallery->ID, '_eg_gallery_data', true );
+
+            if ( empty( $data['gallery'] ) || ! is_array( $data['gallery'] ) ) {
+                return [];
+            }
+
+            $images = array_values( $data['gallery'] );
+            shuffle( $images );
+
+            $images = array_slice( $images, 0, max( 1, $count ) );
+
+            return array_filter(
+                array_map(
+                    function ( $image ) {
+                        if ( empty( $image['src'] ) ) {
+                            return null;
+                        }
+
+                        return [
+                            'src'   => $image['src'],
+                            'title' => $image['title'] ?? '',
+                        ];
+                    },
+                    $images
+                )
+            );
+        }
+
+        /**
+         * Prepare Instagram caption.
+         *
+         * @param WP_Post $gallery Gallery object.
+         * @param string  $template Caption template.
+         * @return string
+         */
+        protected function build_caption( WP_Post $gallery, $template ) {
+            $replacements = [
+                '{gallery_title}' => get_the_title( $gallery ),
+                '{gallery_url}'   => get_permalink( $gallery ),
+            ];
+
+            return strtr( $template, $replacements );
+        }
+
+        /**
+         * Create media container for a single image.
+         *
+         * @param string $image_url Image URL.
+         * @param string $access_token Access token.
+         * @param string $ig_user_id Instagram user ID.
+         * @return string|WP_Error
+         */
+        protected function create_media_container( $image_url, $access_token, $ig_user_id ) {
+            $response = wp_remote_post(
+                sprintf( 'https://graph.facebook.com/v18.0/%s/media', rawurlencode( $ig_user_id ) ),
+                [
+                    'timeout' => 60,
+                    'body'    => [
+                        'image_url'         => $image_url,
+                        'is_carousel_item'  => 'true',
+                        'access_token'      => $access_token,
+                    ],
+                ]
+            );
+
+            if ( is_wp_error( $response ) ) {
+                return $response;
+            }
+
+            $data = json_decode( wp_remote_retrieve_body( $response ), true );
+
+            if ( empty( $data['id'] ) ) {
+                return new WP_Error( 'envira_instagram_bridge_api_error', __( 'Instagram API nevrátilo platné ID containeru.', 'envira-instagram-bridge' ) );
+            }
+
+            return $data['id'];
+        }
+
+        /**
+         * Create carousel container.
+         *
+         * @param array  $containers Container IDs.
+         * @param string $caption Caption text.
+         * @param string $access_token Access token.
+         * @param string $ig_user_id Instagram user ID.
+         * @return string|WP_Error
+         */
+        protected function create_carousel( array $containers, $caption, $access_token, $ig_user_id ) {
+            $response = wp_remote_post(
+                sprintf( 'https://graph.facebook.com/v18.0/%s/media', rawurlencode( $ig_user_id ) ),
+                [
+                    'timeout' => 60,
+                    'body'    => [
+                        'caption'      => $caption,
+                        'media_type'   => 'CAROUSEL',
+                        'children'     => implode( ',', $containers ),
+                        'access_token' => $access_token,
+                    ],
+                ]
+            );
+
+            if ( is_wp_error( $response ) ) {
+                return $response;
+            }
+
+            $data = json_decode( wp_remote_retrieve_body( $response ), true );
+
+            if ( empty( $data['id'] ) ) {
+                return new WP_Error( 'envira_instagram_bridge_api_error', __( 'Instagram API nevrátilo platné ID carouselu.', 'envira-instagram-bridge' ) );
+            }
+
+            return $data['id'];
+        }
+
+        /**
+         * Publish carousel.
+         *
+         * @param string $creation_id Carousel ID.
+         * @param string $access_token Access token.
+         * @return true|WP_Error
+         */
+        protected function publish_media( $creation_id, $access_token ) {
+            $response = wp_remote_post(
+                'https://graph.facebook.com/v18.0/' . rawurlencode( $creation_id ) . '/publish',
+                [
+                    'timeout' => 60,
+                    'body'    => [
+                        'access_token' => $access_token,
+                    ],
+                ]
+            );
+
+            if ( is_wp_error( $response ) ) {
+                return $response;
+            }
+
+            $data = json_decode( wp_remote_retrieve_body( $response ), true );
+
+            if ( empty( $data['id'] ) ) {
+                return new WP_Error( 'envira_instagram_bridge_api_error', __( 'Instagram API nepotvrdilo publikaci.', 'envira-instagram-bridge' ) );
+            }
+
+            return true;
+        }
+    }
+}
+
+Envira_Instagram_Bridge::get_instance();
 
EOF
)
