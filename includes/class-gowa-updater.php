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
    private $repository = 'notify-with-gowa';
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
        $sections       = $this->get_sections( $repo_info );

        $plugin = array(
            'name'              => 'Notify with GOWA',
            'slug'              => dirname( $this->basename ),
            'version'           => $github_version,
            'author'            => '<a href="https://imran.mvp.bd">Imran Ahmed</a>',
            'author_profile'    => 'https://github.com/omnitx',
            'last_updated'      => $repo_info['published_at'],
            'homepage'          => "https://github.com/{$this->username}/{$this->repository}",
            'short_description' => 'Automated and custom notifications for WordPress and WooCommerce powered by self-hosted GOWA.',
            'sections'          => $sections,
            'download_link'     => ! empty( $repo_info['assets'][0]['browser_download_url'] ) ? $repo_info['assets'][0]['browser_download_url'] : $repo_info['zipball_url'],
        );

        return (object) $plugin;
    }

    /**
     * Parse and format sections from readme.txt for the WordPress details popup
     */
    private function get_sections( $repo_info ) {
        $sections = array(
            'description'  => '',
            'installation' => '',
            'changelog'    => '',
        );

        $readme_file = dirname( $this->file ) . '/readme.txt';
        if ( file_exists( $readme_file ) ) {
            $content = file_get_contents( $readme_file );
            if ( preg_match( '/== Description ==(.*?)(== [A-Za-z0-9 ]+ ==|$)/s', $content, $matches ) ) {
                $sections['description'] = $this->format_readme_markdown( trim( $matches ) );
            }
            if ( preg_match( '/== Installation ==(.*?)(== [A-Za-z0-9 ]+ ==|$)/s', $content, $matches ) ) {
                $sections['installation'] = $this->format_readme_markdown( trim( $matches ) );
            }
            if ( preg_match( '/== Changelog ==(.*?)(== [A-Za-z0-9 ]+ ==|$)/s', $content, $matches ) ) {
                $sections['changelog'] = $this->format_readme_markdown( trim( $matches ) );
            }
        }

        // Add latest GitHub release notes to the top of changelog if available
        if ( ! empty( $repo_info['body'] ) ) {
            $sections['changelog'] = $this->format_readme_markdown( $repo_info['body'] ) . ( ! empty( $sections['changelog'] ) ? '<hr>' . $sections['changelog'] : '' );
        }

        if ( empty( $sections['description'] ) ) {
            $sections['description'] = '<p>Automated and custom notifications for WordPress and WooCommerce powered by self-hosted GOWA (Go WhatsApp Web Multi-Device) REST API gateway.</p>';
        }

        return $sections;
    }

    /**
     * Simple Markdown converter to format readme.txt into clean HTML
     */
    private function format_readme_markdown( $text ) {
        // Headers
        $text = preg_replace( '/^### (.*)$/m', '<h4>$1</h4>', $text );
        $text = preg_replace( '/^## (.*)$/m', '<h3>$1</h3>', $text );
        $text = preg_replace( '/^= (.*) =$/m', '<h3>$1</h3>', $text );

        // Formatting
        $text = preg_replace( '/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text );
        $text = preg_replace( '/\*(.*?)\*/', '<em>$1</em>', $text );
        $text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );

        // Links
        $text = preg_replace( '/\[(.*?)\]\((.*?)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $text );

        $lines  = explode( "\n", $text );
        $output = '';
        $in_ul  = false;
        $in_ol  = false;

        foreach ( $lines as $line ) {
            $trimmed = trim( $line );
            if ( empty( $trimmed ) ) {
                if ( $in_ul ) { $output .= "</ul>\n"; $in_ul = false; }
                if ( $in_ol ) { $output .= "</ol>\n"; $in_ol = false; }
                continue;
            }

            if ( strpos( $trimmed, '* ' ) === 0 || strpos( $trimmed, '- ' ) === 0 ) {
                if ( ! $in_ul ) { $output .= "<ul>\n"; $in_ul = true; }
                $output .= '<li>' . substr( $trimmed, 2 ) . "</li>\n";
            } elseif ( preg_match( '/^[0-9]+\.\s+(.*)$/', $trimmed, $m ) ) {
                if ( ! $in_ol ) { $output .= "<ol>\n"; $in_ol = true; }
                $output .= '<li>' . $m . "</li>\n";
            } else {
                if ( $in_ul ) { $output .= "</ul>\n"; $in_ul = false; }
                if ( $in_ol ) { $output .= "</ol>\n"; $in_ol = false; }
                if ( strpos( $trimmed, '<h' ) === 0 || strpos( $trimmed, '<hr' ) === 0 ) {
                    $output .= $trimmed . "\n";
                } else {
                    $output .= '<p>' . $trimmed . "</p>\n";
                }
            }
        }

        if ( $in_ul ) { $output .= "</ul>\n"; }
        if ( $in_ol ) { $output .= "</ol>\n"; }

        return $output;
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
    new GOWA_GitHub_Updater( GOWA_PLUGIN_DIR . 'notify-with-gowa.php' );
}