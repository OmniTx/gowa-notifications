<?php
/**
 * GOWA GitHub Standalone Release Updater
 * Note: Only bundled in GitHub Releases; excluded from WordPress.org repository builds.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GOWA_GitHub_Updater {

    private $file;
    private $basename;
    private $active;
    private $username   = 'OmniTx';
    private $repository = 'gowa-notifications';
    private $github_response;

    public function __construct( $file ) {
        $this->file     = $file;
        $this->basename = plugin_basename( $this->file );
        $this->active   = is_plugin_active( $this->basename );

        add_filter( 'site_transient_update_plugins', array( $this, 'modify_transient' ), 10, 1 );
        add_filter( 'plugins_api', array( $this, 'plugin_popup' ), 10, 3 );
        add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );
    }

    private function get_repository_info() {
        if ( is_null( $this->github_response ) ) {
            $url = "https://api.github.com/repos/{$this->username}/{$this->repository}/releases/latest";
            $response = wp_remote_get( $url, array(
                'headers' => array( 'Accept' => 'application/vnd.github.v3+json' ),
                'timeout' => 10,
            ) );

            if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
                return false;
            }

            $this->github_response = json_decode( wp_remote_retrieve_body( $response ), true );
        }

        return $this->github_response;
    }

    public function modify_transient( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $repo_info = $this->get_repository_info();
        if ( ! $repo_info || empty( $repo_info['tag_name'] ) ) {
            return $transient;
        }

        $github_version  = ltrim( $repo_info['tag_name'], 'v' );
        $current_version = defined( 'GOWA_VERSION' ) ? GOWA_VERSION : '1.0.0';

        if ( version_compare( $github_version, $current_version, '>' ) ) {
            $download_link = '';
            if ( ! empty( $repo_info['assets'] ) ) {
                foreach ( $repo_info['assets'] as $asset ) {
                    if ( substr( $asset['name'], -4 ) === '.zip' ) {
                        $download_link = $asset['browser_download_url'];
                        break;
                    }
                }
            }
            if ( empty( $download_link ) && ! empty( $repo_info['zipball_url'] ) ) {
                $download_link = $repo_info['zipball_url'];
            }

            $plugin = array(
                'slug'        => dirname( $this->basename ),
                'new_version' => $github_version,
                'url'         => "https://github.com/{$this->username}/{$this->repository}",
                'package'     => $download_link,
            );

            $transient->response[ $this->basename ] = (object) $plugin;
        }

        return $transient;
    }

    public function plugin_popup( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== dirname( $this->basename ) ) {
            return $result;
        }

        $repo_info = $this->get_repository_info();
        if ( ! $repo_info ) {
            return $result;
        }

        $github_version = ltrim( $repo_info['tag_name'], 'v' );

        $plugin = array(
            'name'              => 'GOWA Notifications',
            'slug'              => dirname( $this->basename ),
            'version'           => $github_version,
            'author'            => '<a href="https://imran.mvp.bd">Imran Ahmed</a>',
            'author_profile'    => 'https://github.com/omnitx',
            'last_updated'      => $repo_info['published_at'],
            'homepage'          => "https://github.com/{$this->username}/{$this->repository}",
            'short_description' => 'Automated and custom notifications for WordPress and WooCommerce powered by self-hosted GOWA.',
            'sections'          => array(
                'Description' => 'Automated and custom notifications for WordPress and WooCommerce powered by self-hosted GOWA.',
                'Changelog'   => isset( $repo_info['body'] ) ? nl2br( esc_html( $repo_info['body'] ) ) : '',
            ),
            'download_link'     => ! empty( $repo_info['assets'][0]['browser_download_url'] ) ? $repo_info['assets'][0]['browser_download_url'] : $repo_info['zipball_url'],
        );

        return (object) $plugin;
    }

    public function after_install( $response, $hook_extra, $result ) {
        global $wp_filesystem;
        $install_directory = plugin_dir_path( $this->file );
        $wp_filesystem->move( $result['destination'], $install_directory );
        $result['destination'] = $install_directory;

        if ( $this->active ) {
            activate_plugin( $this->basename );
        }

        return $result;
    }
}

if ( is_admin() ) {
    new GOWA_GitHub_Updater( GOWA_PLUGIN_DIR . 'gowa-notifications.php' );
}