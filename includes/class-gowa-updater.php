<?php
/**
 * GOWA GitHub Updater
 *
 * Checks a public GitHub repo's Releases for a newer version and wires the
 * result into WordPress's native "Update available" UI on the Plugins page,
 * including the "View version details" popup and one-click update.
 *
 * Release requirements on the GitHub side:
 *  - Tag the release "vX.Y.Z" (a leading "v" is optional, both are accepted).
 *  - The plugin's own header "Version:" must be bumped to match X.Y.Z before tagging.
 *  - Publish it as a GitHub Release (not just a tag) so it has release notes.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GOWA_GitHub_Updater {

    private $file;
    private $basename;
    private $slug;               // e.g. gowa-notifications
    private $github_repo;        // e.g. OmniTx/gowa-notifications
    private $current_version;
    private $plugin_data;
    private $github_response;    // cached decoded API response for this request

    const CACHE_KEY  = 'gowa_github_update_check';
    const CACHE_HOURS = 6;

    public function __construct( $file, $github_repo, $current_version ) {
        $this->file             = $file;
        $this->basename         = plugin_basename( $file );
        $this->slug             = dirname( $this->basename );
        $this->github_repo      = $github_repo;
        $this->current_version  = $current_version;

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_popup' ), 10, 3 );
        add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );
        add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
        add_filter( 'plugin_row_meta', array( $this, 'add_check_now_link' ), 10, 2 );
        add_action( 'admin_init', array( $this, 'maybe_manual_check' ) );
    }

    /**
     * Fetch latest release info from GitHub, cached via transient to respect
     * GitHub's unauthenticated rate limit (60 req/hour per IP).
     */
    private function get_repository_info() {
        if ( null !== $this->github_response ) {
            return $this->github_response;
        }

        $cached = get_transient( self::CACHE_KEY );
        if ( false !== $cached ) {
            $this->github_response = $cached;
            return $this->github_response;
        }

        $url = sprintf( 'https://api.github.com/repos/%s/releases/latest', $this->github_repo );

        $response = wp_remote_get( $url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
            ),
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            // Cache a short-lived "empty" result too, so a failed check doesn't
            // hammer the GitHub API on every admin page load.
            set_transient( self::CACHE_KEY, array(), HOUR_IN_SECONDS );
            $this->github_response = array();
            return $this->github_response;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
            set_transient( self::CACHE_KEY, array(), HOUR_IN_SECONDS );
            $this->github_response = array();
            return $this->github_response;
        }

        set_transient( self::CACHE_KEY, $data, self::CACHE_HOURS * HOUR_IN_SECONDS );
        $this->github_response = $data;
        return $this->github_response;
    }

    private function get_latest_version() {
        $info = $this->get_repository_info();
        if ( empty( $info['tag_name'] ) ) {
            return false;
        }
        return ltrim( $info['tag_name'], 'vV' );
    }

    /**
     * Prefer the CI-built release asset (e.g. gowa-notifications.zip,
     * uploaded by release.yml with the correct folder structure already inside)
     * over GitHub's raw zipball, which includes .github/, .git metadata via the
     * commit snapshot, and extracts into a hash-suffixed folder name.
     */
    private function get_download_url() {
        $info = $this->get_repository_info();

        if ( ! empty( $info['assets'] ) && is_array( $info['assets'] ) ) {
            foreach ( $info['assets'] as $asset ) {
                if ( ! empty( $asset['browser_download_url'] ) && preg_match( '/\.zip$/i', $asset['name'] ) ) {
                    return $asset['browser_download_url'];
                }
            }
        }

        // Fallback: no built asset attached to this release, use GitHub's auto zipball.
        return ! empty( $info['zipball_url'] ) ? $info['zipball_url'] : '';
    }

    /**
     * Hook: pre_set_site_transient_update_plugins
     * Injects our plugin into the update transient when a newer GitHub release exists.
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $latest = $this->get_latest_version();
        if ( ! $latest || ! version_compare( $latest, $this->current_version, '>' ) ) {
            return $transient;
        }

        $zip_url = $this->get_download_url();
        if ( ! $zip_url ) {
            return $transient;
        }

        $plugin_data = $this->get_plugin_data();

        $item = new stdClass();
        $item->slug         = $this->slug;
        $item->plugin       = $this->basename;
        $item->new_version  = $latest;
        $item->url          = ! empty( $plugin_data['PluginURI'] ) ? $plugin_data['PluginURI'] : '';
        $item->package       = $zip_url;
        $item->tested        = get_bloginfo( 'version' );
        $item->icons         = array();
        $item->banners       = array();
        $item->banners_rss   = '';
        $item->requires_php  = ! empty( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '';

        $transient->response[ $this->basename ] = $item;

        return $transient;
    }

    /**
     * Hook: plugins_api
     * Powers the "View version X.Y.Z details" popup on the Plugins page.
     */
    public function plugin_popup( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
            return $result;
        }

        $info   = $this->get_repository_info();
        $latest = $this->get_latest_version();
        if ( ! $latest ) {
            return $result;
        }

        $plugin_data = $this->get_plugin_data();

        $popup = new stdClass();
        $popup->name           = $plugin_data['Name'];
        $popup->slug           = $this->slug;
        $popup->version        = $latest;
        $popup->author         = ! empty( $plugin_data['Author'] ) ? $plugin_data['Author'] : '';
        $popup->homepage       = ! empty( $plugin_data['PluginURI'] ) ? $plugin_data['PluginURI'] : '';
        $popup->requires       = ! empty( $plugin_data['RequiresWP'] ) ? $plugin_data['RequiresWP'] : '';
        $popup->requires_php   = ! empty( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '';
        $popup->download_link  = $this->get_download_url();
        $popup->last_updated   = ! empty( $info['published_at'] ) ? $info['published_at'] : '';
        $popup->sections       = array(
            'description' => ! empty( $plugin_data['Description'] ) ? $plugin_data['Description'] : '',
            'changelog'   => ! empty( $info['body'] ) ? wp_kses_post( nl2br( $info['body'] ) ) : 'See the GitHub release for details.',
        );

        return $popup;
    }

    /**
     * Hook: upgrader_source_selection
     * GitHub's zipball extracts to a folder like "OmniTx-gowa-notifications-abc1234".
     * Rename it back to the plugin's real slug so WordPress can find it after install.
     */
    public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
        global $wp_filesystem;

        if ( ! is_object( $upgrader ) || empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
            return $source;
        }

        $corrected_source = trailingslashit( $remote_source ) . $this->slug . '/';

        if ( untrailingslashit( $source ) !== untrailingslashit( $corrected_source ) ) {
            if ( $wp_filesystem->move( $source, $corrected_source ) ) {
                return $corrected_source;
            }
        }

        return $source;
    }

    /**
     * Hook: upgrader_post_install
     * Reactivate the plugin after WordPress replaces the files (update process deactivates it).
     */
    public function after_install( $response, $hook_extra, $result ) {
        if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
            return $result;
        }

        $was_active = is_plugin_active( $this->basename );

        global $wp_filesystem;
        $plugin_dir = WP_PLUGIN_DIR . '/' . $this->slug;
        $wp_filesystem->move( $result['destination'], $plugin_dir );
        $result['destination'] = $plugin_dir;

        if ( $was_active ) {
            activate_plugin( $this->basename );
        }

        delete_transient( self::CACHE_KEY );

        return $result;
    }

    /**
     * Adds a manual "Check for updates" link under the plugin's row on the Plugins page.
     */
    public function add_check_now_link( $links, $plugin_file ) {
        if ( $plugin_file !== $this->basename ) {
            return $links;
        }

        $url = wp_nonce_url(
            add_query_arg( array( 'gowa_check_update' => 1 ), admin_url( 'plugins.php' ) ),
            'gowa_check_update'
        );

        $links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'gowa-whatsapp' ) . '</a>';
        return $links;
    }

    /**
     * Handles the manual "Check for updates" link: clears the cache and redirects
     * back so WordPress re-evaluates the update transient immediately.
     */
    public function maybe_manual_check() {
        if ( empty( $_GET['gowa_check_update'] ) || ! current_user_can( 'update_plugins' ) ) {
            return;
        }
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'gowa_check_update' ) ) {
            return;
        }

        delete_transient( self::CACHE_KEY );
        delete_site_transient( 'update_plugins' );
        wp_safe_redirect( admin_url( 'plugins.php?gowa_update_checked=1' ) );
        exit;
    }

    private function get_plugin_data() {
        if ( null === $this->plugin_data ) {
            if ( ! function_exists( 'get_plugin_data' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $this->plugin_data = get_plugin_data( $this->file );
        }
        return $this->plugin_data;
    }
}
