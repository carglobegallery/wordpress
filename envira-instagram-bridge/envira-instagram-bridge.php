 (cd "$(git rev-parse --show-toplevel)" && git apply --3way <<'EOF' 
diff --git a/envira-instagram-bridge/includes/class-envira-instagram-bridge-admin.php b/envira-instagram-bridge/includes/class-envira-instagram-bridge-admin.php
new file mode 100644
index 0000000000000000000000000000000000000000..8cd54ef0ad7317b72bd730b6b2d8bd8e96647efb
--- /dev/null
+++ b/envira-instagram-bridge/includes/class-envira-instagram-bridge-admin.php
@@ -0,0 +1,411 @@
+<?php
+/**
+ * Admin functionality.
+ */
+
+if ( ! defined( 'ABSPATH' ) ) {
+    exit;
+}
+
+class Envira_Instagram_Bridge_Admin {
+
+    /**
+     * Plugin instance.
+     *
+     * @var Envira_Instagram_Bridge
+     */
+    protected $plugin;
+
+    /**
+     * Constructor.
+     *
+     * @param Envira_Instagram_Bridge $plugin Plugin instance.
+     */
+    public function __construct( Envira_Instagram_Bridge $plugin ) {
+        $this->plugin = $plugin;
+    }
+
+    /**
+     * Initialise admin hooks.
+     */
+    public function init() {
+        add_action( 'admin_menu', [ $this, 'register_settings_page' ] );
+        add_action( 'admin_init', [ $this, 'register_settings' ] );
+        add_action( 'admin_post_envira_instagram_bridge_publish_now', [ $this, 'handle_manual_publish' ] );
+        add_action( 'admin_notices', [ $this, 'maybe_show_setup_notice' ] );
+    }
+
+    /**
+     * Register settings page.
+     */
+    public function register_settings_page() {
+        add_options_page(
+            __( 'Envira Instagram Bridge', 'envira-instagram-bridge' ),
+            __( 'Envira Instagram Bridge', 'envira-instagram-bridge' ),
+            'manage_options',
+            'envira-instagram-bridge',
+            [ $this, 'render_settings_page' ]
+        );
+    }
+
+    /**
+     * Register settings fields.
+     */
+    public function register_settings() {
+        register_setting( 'envira_instagram_bridge', Envira_Instagram_Bridge::OPTION_KEY, [ $this, 'sanitize_settings' ] );
+
+        add_settings_section(
+            'envira_instagram_bridge_api',
+            __( 'Instagram API', 'envira-instagram-bridge' ),
+            function () {
+                esc_html_e( 'Vyplňte údaje z vaší Facebook aplikace a Instagram Business účtu.', 'envira-instagram-bridge' );
+            },
+            'envira-instagram-bridge'
+        );
+
+        add_settings_field(
+            'envira_instagram_bridge_app_id',
+            __( 'Facebook App ID', 'envira-instagram-bridge' ),
+            [ $this, 'render_text_field' ],
+            'envira-instagram-bridge',
+            'envira_instagram_bridge_api',
+            [
+                'label_for'   => 'envira_instagram_bridge_app_id',
+                'name'        => 'app_id',
+                'description' => __( 'Identifikátor vaší Facebook aplikace.', 'envira-instagram-bridge' ),
+            ]
+        );
+
+        add_settings_field(
+            'envira_instagram_bridge_app_secret',
+            __( 'Facebook App Secret', 'envira-instagram-bridge' ),
+            [ $this, 'render_text_field' ],
+            'envira-instagram-bridge',
+            'envira_instagram_bridge_api',
+            [
+                'label_for'   => 'envira_instagram_bridge_app_secret',
+                'name'        => 'app_secret',
+                'description' => __( 'Tajný klíč aplikace. Uchovává se pouze ve WordPress databázi.', 'envira-instagram-bridge' ),
+            ]
+        );
+
+        add_settings_field(
+            'envira_instagram_bridge_access_token',
+            __( 'Access token', 'envira-instagram-bridge' ),
+            [ $this, 'render_textarea_field' ],
+            'envira-instagram-bridge',
+            'envira_instagram_bridge_api',
+            [
+                'label_for'   => 'envira_instagram_bridge_access_token',
+                'name'        => 'access_token',
+                'description' => __( 'Dlouhodobý Instagram access token. Plugin se pokusí token prodloužit, pokud zadáte krátkodobý token.', 'envira-instagram-bridge' ),
+            ]
+        );
+
+        add_settings_field(
+            'envira_instagram_bridge_user_id',
+            __( 'Instagram User ID', 'envira-instagram-bridge' ),
+            [ $this, 'render_text_field' ],
+            'envira-instagram-bridge',
+            'envira_instagram_bridge_api',
+            [
+                'label_for'   => 'envira_instagram_bridge_user_id',
+                'name'        => 'ig_user_id',
+                'description' => __( 'Numerické ID Instagram Business/Creator účtu.', 'envira-instagram-bridge' ),
+            ]
+        );
+
+        add_settings_section(
+            'envira_instagram_bridge_behavior',
+            __( 'Chování publikace', 'envira-instagram-bridge' ),
+            function () {
+                esc_html_e( 'Nastavte, jak často a co se má publikovat.', 'envira-instagram-bridge' );
+            },
+            'envira-instagram-bridge'
+        );
+
+        add_settings_field(
+            'envira_instagram_bridge_caption_template',
+            __( 'Šablona popisku', 'envira-instagram-bridge' ),
+            [ $this, 'render_textarea_field' ],
+            'envira-instagram-bridge',
+            'envira_instagram_bridge_behavior',
+            [
+                'label_for'   => 'envira_instagram_bridge_caption_template',
+                'name'        => 'caption_template',
+                'description' => __( 'Dostupné zástupné symboly: {gallery_title}, {gallery_url}.', 'envira-instagram-bridge' ),
+            ]
+        );
+
+        add_settings_field(
+            'envira_instagram_bridge_image_count',
+            __( 'Počet obrázků', 'envira-instagram-bridge' ),
+            [ $this, 'render_number_field' ],
+            'envira-instagram-bridge',
+            'envira_instagram_bridge_behavior',
+            [
+                'label_for'   => 'envira_instagram_bridge_image_count',
+                'name'        => 'image_count',
+                'min'         => 1,
+                'max'         => 10,
+                'description' => __( 'Počet snímků v carouselu (2–10 podle limitu Instagramu).', 'envira-instagram-bridge' ),
+            ]
+        );
+
+        add_settings_field(
+            'envira_instagram_bridge_schedule',
+            __( 'Frekvence', 'envira-instagram-bridge' ),
+            [ $this, 'render_schedule_field' ],
+            'envira-instagram-bridge',
+            'envira_instagram_bridge_behavior',
+            [
+                'label_for'   => 'envira_instagram_bridge_schedule',
+                'name'        => 'schedule',
+                'description' => __( 'Výběr frekvence WP-Cron úlohy.', 'envira-instagram-bridge' ),
+            ]
+        );
+
+        add_settings_field(
+            'envira_instagram_bridge_statuses',
+            __( 'Stavy galerií', 'envira-instagram-bridge' ),
+            [ $this, 'render_status_checkboxes' ],
+            'envira-instagram-bridge',
+            'envira_instagram_bridge_behavior',
+            [
+                'label_for'   => 'envira_instagram_bridge_statuses',
+                'name'        => 'allowed_statuses',
+                'description' => __( 'Které stavy Envira galerií mohou být publikovány.', 'envira-instagram-bridge' ),
+            ]
+        );
+    }
+
+    /**
+     * Sanitize callback used by register_setting.
+     *
+     * @param array $settings Raw settings.
+     * @return array
+     */
+    public function sanitize_settings( $settings ) {
+        $this->plugin->update_settings( (array) $settings );
+
+        return $this->plugin->get_settings();
+    }
+
+    /**
+     * Render settings page.
+     */
+    public function render_settings_page() {
+        if ( ! current_user_can( 'manage_options' ) ) {
+            return;
+        }
+
+        $settings = $this->plugin->get_settings();
+        ?>
+        <div class="wrap">
+            <h1><?php esc_html_e( 'Envira Instagram Bridge', 'envira-instagram-bridge' ); ?></h1>
+            <p><?php esc_html_e( 'Automatizujte publikaci Envira galerií na Instagram jako carousel.', 'envira-instagram-bridge' ); ?></p>
+            <form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
+                <?php
+                settings_fields( 'envira_instagram_bridge' );
+                do_settings_sections( 'envira-instagram-bridge' );
+                submit_button();
+                ?>
+            </form>
+            <hr />
+            <h2><?php esc_html_e( 'Ručně spustit publikaci', 'envira-instagram-bridge' ); ?></h2>
+            <p><?php esc_html_e( 'Pro otestování konfigurace můžete ručně spustit publikaci náhodné galerie.', 'envira-instagram-bridge' ); ?></p>
+            <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
+                <?php wp_nonce_field( 'envira_instagram_bridge_publish_now' ); ?>
+                <input type="hidden" name="action" value="envira_instagram_bridge_publish_now" />
+                <?php submit_button( __( 'Publikovat teď', 'envira-instagram-bridge' ), 'secondary' ); ?>
+            </form>
+            <p class="description">
+                <?php esc_html_e( 'Poslední spuštění:', 'envira-instagram-bridge' ); ?>
+                <?php
+                $last_run = get_option( 'envira_instagram_bridge_last_run' );
+                if ( $last_run ) {
+                    echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_run ) );
+                } else {
+                    esc_html_e( 'dosud neproběhlo', 'envira-instagram-bridge' );
+                }
+                ?>
+            </p>
+        </div>
+        <?php
+    }
+
+    /**
+     * Render a text field.
+     *
+     * @param array $args Arguments.
+     */
+    public function render_text_field( $args ) {
+        $settings = $this->plugin->get_settings();
+        $name     = $args['name'];
+        $value    = $settings[ $name ] ?? '';
+        ?>
+        <input type="text" id="<?php echo esc_attr( $args['label_for'] ); ?>" name="<?php echo esc_attr( Envira_Instagram_Bridge::OPTION_KEY . "[{$name}]" ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
+        <?php if ( ! empty( $args['description'] ) ) : ?>
+            <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
+        <?php endif; ?>
+        <?php
+    }
+
+    /**
+     * Render textarea field.
+     *
+     * @param array $args Arguments.
+     */
+    public function render_textarea_field( $args ) {
+        $settings = $this->plugin->get_settings();
+        $name     = $args['name'];
+        $value    = $settings[ $name ] ?? '';
+        ?>
+        <textarea id="<?php echo esc_attr( $args['label_for'] ); ?>" name="<?php echo esc_attr( Envira_Instagram_Bridge::OPTION_KEY . "[{$name}]" ); ?>" class="large-text" rows="4"><?php echo esc_textarea( $value ); ?></textarea>
+        <?php if ( ! empty( $args['description'] ) ) : ?>
+            <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
+        <?php endif; ?>
+        <?php
+    }
+
+    /**
+     * Render number field.
+     *
+     * @param array $args Arguments.
+     */
+    public function render_number_field( $args ) {
+        $settings = $this->plugin->get_settings();
+        $name     = $args['name'];
+        $value    = absint( $settings[ $name ] ?? 5 );
+        ?>
+        <input type="number" id="<?php echo esc_attr( $args['label_for'] ); ?>" name="<?php echo esc_attr( Envira_Instagram_Bridge::OPTION_KEY . "[{$name}]" ); ?>" value="<?php echo esc_attr( $value ); ?>" min="<?php echo esc_attr( $args['min'] ); ?>" max="<?php echo esc_attr( $args['max'] ); ?>" />
+        <?php if ( ! empty( $args['description'] ) ) : ?>
+            <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
+        <?php endif; ?>
+        <?php
+    }
+
+    /**
+     * Render schedule select.
+     *
+     * @param array $args Arguments.
+     */
+    public function render_schedule_field( $args ) {
+        $settings   = $this->plugin->get_settings();
+        $name       = $args['name'];
+        $value      = $settings[ $name ] ?? 'daily';
+        $schedules  = wp_get_schedules();
+        ?>
+        <select id="<?php echo esc_attr( $args['label_for'] ); ?>" name="<?php echo esc_attr( Envira_Instagram_Bridge::OPTION_KEY . "[{$name}]" ); ?>">
+            <?php foreach ( $schedules as $key => $schedule ) : ?>
+                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>><?php echo esc_html( $schedule['display'] ); ?></option>
+            <?php endforeach; ?>
+        </select>
+        <?php if ( ! empty( $args['description'] ) ) : ?>
+            <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
+        <?php endif; ?>
+        <?php
+    }
+
+    /**
+     * Render checkboxes for allowed statuses.
+     *
+     * @param array $args Arguments.
+     */
+    public function render_status_checkboxes( $args ) {
+        $settings    = $this->plugin->get_settings();
+        $name        = $args['name'];
+        $values      = $settings[ $name ] ?? [];
+        $all_statuses = [
+            'publish' => __( 'Publikované', 'envira-instagram-bridge' ),
+            'draft'   => __( 'Koncepty', 'envira-instagram-bridge' ),
+            'pending' => __( 'Čekající na schválení', 'envira-instagram-bridge' ),
+            'private' => __( 'Soukromé', 'envira-instagram-bridge' ),
+        ];
+        ?>
+        <?php foreach ( $all_statuses as $status => $label ) : ?>
+            <label>
+                <input type="checkbox" name="<?php echo esc_attr( Envira_Instagram_Bridge::OPTION_KEY . "[{$name}][]" ); ?>" value="<?php echo esc_attr( $status ); ?>" <?php checked( in_array( $status, $values, true ) ); ?> />
+                <?php echo esc_html( $label ); ?>
+            </label><br />
+        <?php endforeach; ?>
+        <?php if ( ! empty( $args['description'] ) ) : ?>
+            <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
+        <?php endif; ?>
+        <?php
+    }
+
+    /**
+     * Handle manual publish button.
+     */
+    public function handle_manual_publish() {
+        if ( ! current_user_can( 'manage_options' ) ) {
+            wp_die( esc_html__( 'Nemáte oprávnění.', 'envira-instagram-bridge' ) );
+        }
+
+        check_admin_referer( 'envira_instagram_bridge_publish_now' );
+
+        $result = $this->plugin->get_publisher()->publish_random_gallery();
+
+        if ( is_wp_error( $result ) ) {
+            $redirect = add_query_arg(
+                [
+                    'page'    => 'envira-instagram-bridge',
+                    'status'  => 'error',
+                    'message' => rawurlencode( $result->get_error_message() ),
+                ],
+                admin_url( 'options-general.php' )
+            );
+        } else {
+            update_option( 'envira_instagram_bridge_last_run', time() );
+            $redirect = add_query_arg(
+                [
+                    'page'   => 'envira-instagram-bridge',
+                    'status' => 'success',
+                ],
+                admin_url( 'options-general.php' )
+            );
+        }
+
+        wp_safe_redirect( $redirect );
+        exit;
+    }
+
+    /**
+     * Show notice if configuration is incomplete.
+     */
+    public function maybe_show_setup_notice() {
+        if ( ! current_user_can( 'manage_options' ) ) {
+            return;
+        }
+
+        $screen = get_current_screen();
+
+        if ( ! $screen || 'settings_page_envira-instagram-bridge' !== $screen->id ) {
+            $settings = $this->plugin->get_settings();
+
+            if ( empty( $settings['access_token'] ) || empty( $settings['ig_user_id'] ) ) {
+                printf(
+                    '<div class="notice notice-warning"><p>%s</p></div>',
+                    esc_html__( 'Envira Instagram Bridge není plně nastaven – doplňte access token a Instagram User ID.', 'envira-instagram-bridge' )
+                );
+            }
+        }
+
+        if ( isset( $_GET['status'] ) && 'settings_page_envira-instagram-bridge' === $screen->id ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
+            $message = '';
+            if ( 'success' === $_GET['status'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
+                $message = __( 'Publikace proběhla úspěšně.', 'envira-instagram-bridge' );
+                $class   = 'notice notice-success';
+            } elseif ( 'error' === $_GET['status'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
+                $error   = isset( $_GET['message'] ) ? wp_unslash( $_GET['message'] ) : __( 'Publikace selhala.', 'envira-instagram-bridge' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
+                $message = sprintf( __( 'Publikace selhala: %s', 'envira-instagram-bridge' ), $error );
+                $class   = 'notice notice-error';
+            }
+
+            if ( $message ) {
+                printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
+            }
+        }
+    }
+}
 
EOF
)
