 (cd "$(git rev-parse --show-toplevel)" && git apply --3way <<'EOF' 
diff --git a/envira-instagram-bridge/envira-instagram-bridge.php b/envira-instagram-bridge/envira-instagram-bridge.php
new file mode 100644
index 0000000000000000000000000000000000000000..f559449dde8e084921a65f435db77d3fc68af214
--- /dev/null
+++ b/envira-instagram-bridge/envira-instagram-bridge.php
@@ -0,0 +1,32 @@
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
+if ( ! defined( 'ENVIRA_INSTAGRAM_BRIDGE_PATH' ) ) {
+    define( 'ENVIRA_INSTAGRAM_BRIDGE_PATH', plugin_dir_path( __FILE__ ) );
+}
+
+if ( ! defined( 'ENVIRA_INSTAGRAM_BRIDGE_URL' ) ) {
+    define( 'ENVIRA_INSTAGRAM_BRIDGE_URL', plugin_dir_url( __FILE__ ) );
+}
+
+require_once ENVIRA_INSTAGRAM_BRIDGE_PATH . 'includes/class-envira-instagram-bridge.php';
+require_once ENVIRA_INSTAGRAM_BRIDGE_PATH . 'includes/class-envira-instagram-bridge-admin.php';
+require_once ENVIRA_INSTAGRAM_BRIDGE_PATH . 'includes/class-envira-instagram-bridge-publisher.php';
+
+Envira_Instagram_Bridge::get_instance();
 
EOF
)
