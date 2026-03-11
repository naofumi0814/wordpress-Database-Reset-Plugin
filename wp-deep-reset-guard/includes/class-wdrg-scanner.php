<?php
/**
 * WDRG_Scanner - スキャン処理クラス
 *
 * テーマ、プラグイン、uploads、追加フォルダ、データベーステーブルを
 * スキャンし、削除対象の一覧を生成する。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WDRG_Scanner {

    /**
     * テーマをスキャンして削除対象を取得
     *
     * @return array 削除対象テーマの一覧
     */
    public static function scan_themes(): array {
        $all_themes   = wp_get_themes();
        $current      = get_stylesheet();
        $parent       = get_template();
        $targets      = array();

        foreach ( $all_themes as $slug => $theme ) {
            $is_default = WDRG_Safety::is_default_theme( $slug );
            $is_active  = ( $slug === $current || $slug === $parent );

            if ( $is_default ) {
                continue; // 標準テーマは削除対象に含めない
            }

            $theme_dir  = $theme->get_stylesheet_directory();
            $dir_size   = self::get_directory_size( $theme_dir );

            $targets[] = array(
                'slug'       => $slug,
                'name'       => $theme->get( 'Name' ),
                'version'    => $theme->get( 'Version' ),
                'path'       => $theme_dir,
                'is_active'  => $is_active,
                'is_default' => false,
                'size'       => $dir_size,
                'size_human' => size_format( $dir_size ),
            );
        }

        return $targets;
    }

    /**
     * プラグインをスキャンして削除対象を取得
     *
     * @return array 削除対象プラグインの一覧
     */
    public static function scan_plugins(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins    = get_plugins();
        $active_plugins = get_option( 'active_plugins', array() );
        $targets        = array();

        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            // 自分自身は除外
            if ( WDRG_Safety::is_self_plugin( $plugin_file ) ) {
                continue;
            }

            $is_active = in_array( $plugin_file, $active_plugins, true );

            // プラグインのディレクトリまたはファイルパスを取得
            $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
            $parts       = explode( '/', $plugin_file );
            $is_dir_type = ( count( $parts ) > 1 );

            if ( $is_dir_type ) {
                // ディレクトリ型プラグイン
                $plugin_dir = WP_PLUGIN_DIR . '/' . $parts[0];
                $dir_size   = self::get_directory_size( $plugin_dir );
            } else {
                // 単一ファイル型プラグイン
                $plugin_dir = $plugin_path;
                $dir_size   = file_exists( $plugin_path ) ? (int) filesize( $plugin_path ) : 0;
            }

            $targets[] = array(
                'file'       => $plugin_file,
                'name'       => $plugin_data['Name'],
                'version'    => $plugin_data['Version'],
                'path'       => $is_dir_type ? $plugin_dir : $plugin_path,
                'is_dir'     => $is_dir_type,
                'is_active'  => $is_active,
                'size'       => $dir_size,
                'size_human' => size_format( $dir_size ),
            );
        }

        return $targets;
    }

    /**
     * uploads ディレクトリをスキャン
     *
     * @return array スキャン結果
     */
    public static function scan_uploads(): array {
        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'];

        if ( ! is_dir( $base_dir ) ) {
            return array(
                'exists'       => false,
                'path'         => $base_dir,
                'file_count'   => 0,
                'dir_count'    => 0,
                'total_size'   => 0,
                'size_human'   => '0 B',
                'items'        => array(),
            );
        }

        $file_count = 0;
        $dir_count  = 0;
        $total_size = 0;
        $items      = array();

        // uploads 直下の項目を列挙
        $iterator = new DirectoryIterator( $base_dir );
        foreach ( $iterator as $item ) {
            if ( $item->isDot() ) {
                continue;
            }

            $name     = $item->getFilename();
            $path     = $item->getPathname();
            $is_link  = $item->isLink();

            // シンボリックリンクはスキップ（削除対象にしない）
            if ( $is_link ) {
                continue;
            }

            // 保護ファイルはスキップ
            if ( $item->isFile() && ! WDRG_Safety::is_not_protected_file( $path ) ) {
                continue;
            }

            // index.php は保護対象として残す
            if ( $item->isFile() && $name === 'index.php' ) {
                continue;
            }

            if ( $item->isDir() ) {
                $dir_size = self::get_directory_size( $path );
                $sub_info = self::count_files_recursive( $path );
                $dir_count++;

                $items[] = array(
                    'name'       => $name,
                    'path'       => $path,
                    'type'       => 'directory',
                    'size'       => $dir_size,
                    'size_human' => size_format( $dir_size ),
                    'file_count' => $sub_info['files'],
                    'dir_count'  => $sub_info['dirs'],
                );
                $total_size += $dir_size;
                $file_count += $sub_info['files'];
            } else {
                $fsize = (int) $item->getSize();
                $file_count++;
                $total_size += $fsize;

                $items[] = array(
                    'name'       => $name,
                    'path'       => $path,
                    'type'       => 'file',
                    'size'       => $fsize,
                    'size_human' => size_format( $fsize ),
                    'file_count' => 1,
                    'dir_count'  => 0,
                );
            }
        }

        return array(
            'exists'       => true,
            'path'         => $base_dir,
            'file_count'   => $file_count,
            'dir_count'    => $dir_count,
            'total_size'   => $total_size,
            'size_human'   => size_format( $total_size ),
            'items'        => $items,
        );
    }

    /**
     * 追加フォルダをスキャン
     *
     * @return array 削除候補フォルダの一覧
     */
    public static function scan_extra_dirs(): array {
        $targets = array();

        foreach ( WDRG_Safety::EXTRA_DIRS_WHITELIST as $dir_name ) {
            $path = WP_CONTENT_DIR . '/' . $dir_name;
            if ( ! is_dir( $path ) || is_link( $path ) ) {
                continue;
            }

            $dir_size = self::get_directory_size( $path );
            $sub_info = self::count_files_recursive( $path );

            $targets[] = array(
                'name'       => $dir_name,
                'path'       => $path,
                'size'       => $dir_size,
                'size_human' => size_format( $dir_size ),
                'file_count' => $sub_info['files'],
                'dir_count'  => $sub_info['dirs'],
            );
        }

        return $targets;
    }

    /**
     * データベーステーブルをスキャン
     *
     * @return array テーブル一覧
     */
    public static function scan_database(): array {
        global $wpdb;

        $prefix = $wpdb->prefix;
        $tables = $wpdb->get_results(
            $wpdb->prepare(
                'SHOW TABLE STATUS FROM `' . DB_NAME . '` LIKE %s',
                $wpdb->esc_like( $prefix ) . '%'
            ),
            ARRAY_A
        );

        if ( ! is_array( $tables ) ) {
            return array();
        }

        $result = array();
        // ユーザー関連テーブルを特定
        $user_tables = array(
            $prefix . 'users',
            $prefix . 'usermeta',
        );

        // 設定テーブルを特定
        $options_table = $prefix . 'options';

        foreach ( $tables as $table ) {
            $table_name = $table['Name'];

            // テーブル名がプレフィックスで始まることを厳密確認
            if ( strpos( $table_name, $prefix ) !== 0 ) {
                continue;
            }

            $is_user_table    = in_array( $table_name, $user_tables, true );
            $is_options_table = ( $table_name === $options_table );
            $rows             = isset( $table['Rows'] ) ? (int) $table['Rows'] : 0;
            $data_length      = isset( $table['Data_length'] ) ? (int) $table['Data_length'] : 0;
            $index_length     = isset( $table['Index_length'] ) ? (int) $table['Index_length'] : 0;
            $total_size       = $data_length + $index_length;

            $result[] = array(
                'name'             => $table_name,
                'rows'             => $rows,
                'size'             => $total_size,
                'size_human'       => size_format( $total_size ),
                'engine'           => $table['Engine'] ?? '',
                'is_user_table'    => $is_user_table,
                'is_options_table' => $is_options_table,
            );
        }

        return $result;
    }

    /**
     * サイト概要情報を取得
     *
     * @return array
     */
    public static function get_site_info(): array {
        $current_theme  = wp_get_theme();
        $active_plugins = get_option( 'active_plugins', array() );

        return array(
            'site_url'         => get_site_url(),
            'wp_version'       => get_bloginfo( 'version' ),
            'php_version'      => PHP_VERSION,
            'current_theme'    => $current_theme->get( 'Name' ),
            'current_theme_slug' => get_stylesheet(),
            'active_plugins'   => count( $active_plugins ),
            'is_multisite'     => is_multisite(),
            'db_prefix'        => $GLOBALS['wpdb']->prefix,
            'wp_content_dir'   => WP_CONTENT_DIR,
            'abspath'          => ABSPATH,
        );
    }

    /**
     * ディレクトリの合計サイズを再帰的に計算
     *
     * @param string $path ディレクトリパス
     * @return int バイト数
     */
    public static function get_directory_size( string $path ): int {
        if ( ! is_dir( $path ) || is_link( $path ) ) {
            return 0;
        }

        $size = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ( $iterator as $file ) {
                if ( $file->isFile() && ! $file->isLink() ) {
                    $size += (int) $file->getSize();
                }
            }
        } catch ( Exception $e ) {
            // エラー時は0を返す
        }

        return $size;
    }

    /**
     * ディレクトリ内のファイル数・サブディレクトリ数を再帰的にカウント
     *
     * @param string $path ディレクトリパス
     * @return array ['files' => int, 'dirs' => int]
     */
    private static function count_files_recursive( string $path ): array {
        $files = 0;
        $dirs  = 0;

        if ( ! is_dir( $path ) || is_link( $path ) ) {
            return array( 'files' => 0, 'dirs' => 0 );
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ( $iterator as $item ) {
                if ( $item->isLink() ) {
                    continue;
                }
                if ( $item->isDir() ) {
                    $dirs++;
                } else {
                    $files++;
                }
            }
        } catch ( Exception $e ) {
            // エラー時は0を返す
        }

        return array( 'files' => $files, 'dirs' => $dirs );
    }
}
