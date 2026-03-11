<?php
/**
 * WDRG_Executor - 削除実行クラス
 *
 * テーマ、プラグイン、uploads、追加フォルダの
 * 実際の削除処理を行う。すべての処理でログを記録し、
 * パス検証を行ったうえで削除する。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WDRG_Executor {

    /** @var WDRG_Logger */
    private $logger;

    /** @var bool dry-run モード */
    private $dry_run;

    /**
     * コンストラクタ
     *
     * @param WDRG_Logger $logger  ログインスタンス
     * @param bool        $dry_run dry-runモードかどうか
     */
    public function __construct( WDRG_Logger $logger, bool $dry_run = true ) {
        $this->logger  = $logger;
        $this->dry_run = $dry_run;
    }

    /**
     * テーマを削除する
     *
     * @param array $theme_slugs 削除対象のテーマスラッグ配列
     * @return array 処理結果
     */
    public function delete_themes( array $theme_slugs ): array {
        $results = array();
        $this->logger->info( 'テーマ削除処理を開始します。対象: ' . count( $theme_slugs ) . '件' );

        if ( empty( $theme_slugs ) ) {
            $this->logger->info( '削除対象のテーマはありません。' );
            return $results;
        }

        // 現在のテーマを確認
        $current_stylesheet = get_stylesheet();
        $current_template   = get_template();

        // 削除対象に現在のテーマが含まれる場合、標準テーマに切り替える
        if ( in_array( $current_stylesheet, $theme_slugs, true ) || in_array( $current_template, $theme_slugs, true ) ) {
            $switched = $this->switch_to_default_theme();
            if ( ! $switched ) {
                $this->logger->error( '標準テーマへの切り替えに失敗しました。テーマ削除を中断します。' );
                return $results;
            }
        }

        foreach ( $theme_slugs as $slug ) {
            $slug = sanitize_file_name( $slug );

            // 標準テーマは絶対に削除しない
            if ( WDRG_Safety::is_default_theme( $slug ) ) {
                $this->logger->skip( '標準テーマのため削除をスキップしました。', $slug );
                $results[] = array( 'slug' => $slug, 'status' => 'skipped', 'reason' => 'default_theme' );
                continue;
            }

            $theme_dir = get_theme_root() . '/' . $slug;

            // パス安全性チェック
            if ( ! WDRG_Safety::is_safe_path( $theme_dir ) ) {
                $this->logger->error( 'パス検証に失敗しました。', $theme_dir );
                $results[] = array( 'slug' => $slug, 'status' => 'error', 'reason' => 'path_validation_failed' );
                continue;
            }

            if ( $this->dry_run ) {
                $this->logger->info( '[DRY-RUN] テーマを削除します。', $theme_dir );
                $results[] = array( 'slug' => $slug, 'status' => 'dry_run' );
                continue;
            }

            // 実際の削除処理
            $deleted = $this->delete_directory_recursive( $theme_dir );
            if ( $deleted ) {
                $this->logger->success( 'テーマを削除しました。', $slug );
                $results[] = array( 'slug' => $slug, 'status' => 'deleted' );
            } else {
                $this->logger->error( 'テーマの削除に失敗しました。', $slug );
                $results[] = array( 'slug' => $slug, 'status' => 'error', 'reason' => 'delete_failed' );
            }
        }

        $this->logger->info( 'テーマ削除処理が完了しました。' );
        return $results;
    }

    /**
     * WordPress標準テーマに切り替える
     *
     * @return bool 成功したかどうか
     */
    private function switch_to_default_theme(): bool {
        // 新しい標準テーマから順に探す
        $default_themes = array_reverse( WDRG_Safety::DEFAULT_THEMES );

        foreach ( $default_themes as $theme_slug ) {
            $theme = wp_get_theme( $theme_slug );
            if ( $theme->exists() ) {
                if ( $this->dry_run ) {
                    $this->logger->info( '[DRY-RUN] 標準テーマに切り替えます。', $theme_slug );
                    return true;
                }

                switch_theme( $theme_slug );
                $this->logger->success( '標準テーマに切り替えました。', $theme_slug );
                return true;
            }
        }

        $this->logger->error( '利用可能な標準テーマが見つかりません。' );
        return false;
    }

    /**
     * プラグインを削除する
     *
     * @param array $plugin_files 削除対象のプラグインファイル配列（例: 'plugin-dir/plugin.php' または 'plugin.php'）
     * @return array 処理結果
     */
    public function delete_plugins( array $plugin_files ): array {
        $results = array();
        $this->logger->info( 'プラグイン削除処理を開始します。対象: ' . count( $plugin_files ) . '件' );

        if ( empty( $plugin_files ) ) {
            $this->logger->info( '削除対象のプラグインはありません。' );
            return $results;
        }

        // 有効なプラグインを先に停止
        $active_plugins = get_option( 'active_plugins', array() );
        $to_deactivate  = array_intersect( $plugin_files, $active_plugins );

        if ( ! empty( $to_deactivate ) ) {
            if ( $this->dry_run ) {
                $this->logger->info( '[DRY-RUN] ' . count( $to_deactivate ) . '件のプラグインを停止します。' );
            } else {
                deactivate_plugins( $to_deactivate, true );
                $this->logger->success( count( $to_deactivate ) . '件のプラグインを停止しました。' );
            }
        }

        foreach ( $plugin_files as $plugin_file ) {
            $plugin_file = sanitize_text_field( $plugin_file );

            // 自分自身は削除しない
            if ( WDRG_Safety::is_self_plugin( $plugin_file ) ) {
                $this->logger->skip( 'このプラグイン自身のため削除をスキップしました。', $plugin_file );
                $results[] = array( 'file' => $plugin_file, 'status' => 'skipped', 'reason' => 'self_plugin' );
                continue;
            }

            // プラグインのパスを特定
            $parts      = explode( '/', $plugin_file );
            $is_dir     = ( count( $parts ) > 1 );
            $target_path = $is_dir
                ? WP_PLUGIN_DIR . '/' . $parts[0]
                : WP_PLUGIN_DIR . '/' . $plugin_file;

            // パス安全性チェック
            if ( ! WDRG_Safety::is_safe_path( $target_path ) ) {
                $this->logger->error( 'パス検証に失敗しました。', $target_path );
                $results[] = array( 'file' => $plugin_file, 'status' => 'error', 'reason' => 'path_validation_failed' );
                continue;
            }

            if ( $this->dry_run ) {
                $this->logger->info( '[DRY-RUN] プラグインを削除します。', $target_path );
                $results[] = array( 'file' => $plugin_file, 'status' => 'dry_run' );
                continue;
            }

            // 実際の削除処理
            if ( $is_dir ) {
                $deleted = $this->delete_directory_recursive( $target_path );
            } else {
                $deleted = $this->delete_single_file( $target_path );
            }

            if ( $deleted ) {
                $this->logger->success( 'プラグインを削除しました。', $plugin_file );
                $results[] = array( 'file' => $plugin_file, 'status' => 'deleted' );
            } else {
                $this->logger->error( 'プラグインの削除に失敗しました。', $plugin_file );
                $results[] = array( 'file' => $plugin_file, 'status' => 'error', 'reason' => 'delete_failed' );
            }
        }

        $this->logger->info( 'プラグイン削除処理が完了しました。' );
        return $results;
    }

    /**
     * uploads ディレクトリの中身を削除する
     *
     * @param array $items 削除対象のアイテム名配列（uploads直下のファイル・フォルダ名）
     * @return array 処理結果
     */
    public function delete_uploads( array $items ): array {
        $results    = array();
        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'];

        $this->logger->info( 'uploads 削除処理を開始します。対象: ' . count( $items ) . '件' );

        if ( ! is_dir( $base_dir ) ) {
            $this->logger->error( 'uploads ディレクトリが存在しません。', $base_dir );
            return $results;
        }

        foreach ( $items as $item_name ) {
            $item_name = sanitize_file_name( $item_name );
            $path      = $base_dir . '/' . $item_name;

            // 保護ファイルチェック
            if ( ! WDRG_Safety::is_not_protected_file( $path ) ) {
                $this->logger->skip( '保護対象ファイルのため削除をスキップしました。', $path );
                $results[] = array( 'name' => $item_name, 'status' => 'skipped', 'reason' => 'protected' );
                continue;
            }

            // index.php を保護
            if ( $item_name === 'index.php' ) {
                $this->logger->skip( '保護対象ファイル(index.php)のため削除をスキップしました。', $path );
                $results[] = array( 'name' => $item_name, 'status' => 'skipped', 'reason' => 'protected' );
                continue;
            }

            // パス安全性チェック
            if ( ! WDRG_Safety::is_safe_path( $path ) ) {
                $this->logger->error( 'パス検証に失敗しました。', $path );
                $results[] = array( 'name' => $item_name, 'status' => 'error', 'reason' => 'path_validation_failed' );
                continue;
            }

            // シンボリックリンクは拒否
            if ( is_link( $path ) ) {
                $this->logger->skip( 'シンボリックリンクのため削除をスキップしました。', $path );
                $results[] = array( 'name' => $item_name, 'status' => 'skipped', 'reason' => 'symlink' );
                continue;
            }

            if ( $this->dry_run ) {
                $type = is_dir( $path ) ? 'ディレクトリ' : 'ファイル';
                $this->logger->info( '[DRY-RUN] ' . $type . 'を削除します。', $path );
                $results[] = array( 'name' => $item_name, 'status' => 'dry_run' );
                continue;
            }

            // 実際の削除
            if ( is_dir( $path ) ) {
                $deleted = $this->delete_directory_recursive( $path );
            } else {
                $deleted = $this->delete_single_file( $path );
            }

            if ( $deleted ) {
                $this->logger->success( 'uploads アイテムを削除しました。', $item_name );
                $results[] = array( 'name' => $item_name, 'status' => 'deleted' );
            } else {
                $this->logger->error( 'uploads アイテムの削除に失敗しました。', $item_name );
                $results[] = array( 'name' => $item_name, 'status' => 'error', 'reason' => 'delete_failed' );
            }
        }

        $this->logger->info( 'uploads 削除処理が完了しました。' );
        return $results;
    }

    /**
     * 追加フォルダを削除する
     *
     * @param array $dir_names 削除対象のディレクトリ名配列（wp-content直下のフォルダ名）
     * @return array 処理結果
     */
    public function delete_extra_dirs( array $dir_names ): array {
        $results = array();
        $this->logger->info( '追加フォルダ削除処理を開始します。対象: ' . count( $dir_names ) . '件' );

        foreach ( $dir_names as $dir_name ) {
            $dir_name = sanitize_file_name( $dir_name );

            // ホワイトリストチェック
            if ( ! WDRG_Safety::is_allowed_extra_dir( $dir_name ) ) {
                $this->logger->error( 'ホワイトリストに含まれないフォルダです。', $dir_name );
                $results[] = array( 'name' => $dir_name, 'status' => 'error', 'reason' => 'not_whitelisted' );
                continue;
            }

            $path = WP_CONTENT_DIR . '/' . $dir_name;

            if ( ! is_dir( $path ) ) {
                $this->logger->skip( 'フォルダが存在しません。', $path );
                $results[] = array( 'name' => $dir_name, 'status' => 'skipped', 'reason' => 'not_found' );
                continue;
            }

            // シンボリックリンクは拒否
            if ( is_link( $path ) ) {
                $this->logger->skip( 'シンボリックリンクのため削除をスキップしました。', $path );
                $results[] = array( 'name' => $dir_name, 'status' => 'skipped', 'reason' => 'symlink' );
                continue;
            }

            // パス安全性チェック
            if ( ! WDRG_Safety::is_safe_path( $path ) ) {
                $this->logger->error( 'パス検証に失敗しました。', $path );
                $results[] = array( 'name' => $dir_name, 'status' => 'error', 'reason' => 'path_validation_failed' );
                continue;
            }

            if ( $this->dry_run ) {
                $this->logger->info( '[DRY-RUN] フォルダを削除します。', $path );
                $results[] = array( 'name' => $dir_name, 'status' => 'dry_run' );
                continue;
            }

            $deleted = $this->delete_directory_recursive( $path );
            if ( $deleted ) {
                $this->logger->success( '追加フォルダを削除しました。', $dir_name );
                $results[] = array( 'name' => $dir_name, 'status' => 'deleted' );
            } else {
                $this->logger->error( '追加フォルダの削除に失敗しました。', $dir_name );
                $results[] = array( 'name' => $dir_name, 'status' => 'error', 'reason' => 'delete_failed' );
            }
        }

        $this->logger->info( '追加フォルダ削除処理が完了しました。' );
        return $results;
    }

    /**
     * ディレクトリを再帰的に削除する
     * WP_Filesystem を使用し、失敗した場合は直接削除にフォールバック
     *
     * @param string $path 削除対象ディレクトリパス
     * @return bool
     */
    private function delete_directory_recursive( string $path ): bool {
        // 最終的なパス検証
        $real_path = realpath( $path );
        if ( false === $real_path ) {
            $this->logger->error( 'realpath の解決に失敗しました。', $path );
            return false;
        }

        // wp-content 配下であることを再確認
        $wp_content_real = realpath( WP_CONTENT_DIR );
        if ( false === $wp_content_real || strpos( $real_path, $wp_content_real . DIRECTORY_SEPARATOR ) !== 0 ) {
            $this->logger->error( 'wp-content 配下ではないパスへの削除は拒否されました。', $real_path );
            return false;
        }

        // シンボリックリンクは削除しない
        if ( is_link( $path ) ) {
            $this->logger->skip( 'シンボリックリンクの削除はスキップしました。', $path );
            return false;
        }

        // WP_Filesystem を初期化
        global $wp_filesystem;
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();

        if ( $wp_filesystem && $wp_filesystem->is_dir( $real_path ) ) {
            $result = $wp_filesystem->delete( $real_path, true );
            if ( $result ) {
                return true;
            }
            $this->logger->warning( 'WP_Filesystem での削除に失敗しました。直接削除を試行します。', $real_path );
        }

        // フォールバック: PHP の再帰削除
        return $this->recursive_rmdir( $real_path );
    }

    /**
     * PHP で再帰的にディレクトリを削除する（フォールバック）
     *
     * @param string $dir 削除対象ディレクトリパス（realpath済み）
     * @return bool
     */
    private function recursive_rmdir( string $dir ): bool {
        if ( ! is_dir( $dir ) ) {
            return false;
        }

        // 安全装置: wp-content 配下であることを再確認
        $wp_content_real = realpath( WP_CONTENT_DIR );
        if ( false === $wp_content_real || strpos( $dir, $wp_content_real . DIRECTORY_SEPARATOR ) !== 0 ) {
            return false;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ( $iterator as $item ) {
                $item_path = $item->getPathname();

                // シンボリックリンクはスキップ
                if ( is_link( $item_path ) ) {
                    $this->logger->skip( 'シンボリックリンクをスキップしました。', $item_path );
                    continue;
                }

                if ( $item->isDir() ) {
                    if ( ! @rmdir( $item_path ) ) {
                        $this->logger->error( 'ディレクトリの削除に失敗しました。', $item_path );
                    }
                } else {
                    if ( ! @unlink( $item_path ) ) {
                        $this->logger->error( 'ファイルの削除に失敗しました。', $item_path );
                    }
                }
            }

            // 最後にルートディレクトリを削除
            return @rmdir( $dir );
        } catch ( Exception $e ) {
            $this->logger->error( '再帰削除中に例外が発生しました: ' . $e->getMessage(), $dir );
            return false;
        }
    }

    /**
     * 単一ファイルを削除する
     *
     * @param string $file_path ファイルパス
     * @return bool
     */
    private function delete_single_file( string $file_path ): bool {
        $real_path = realpath( $file_path );
        if ( false === $real_path ) {
            $this->logger->error( 'ファイルの realpath 解決に失敗しました。', $file_path );
            return false;
        }

        // wp-content 配下であることを確認
        $wp_content_real = realpath( WP_CONTENT_DIR );
        if ( false === $wp_content_real || strpos( $real_path, $wp_content_real . DIRECTORY_SEPARATOR ) !== 0 ) {
            $this->logger->error( 'wp-content 配下ではないファイルの削除は拒否されました。', $real_path );
            return false;
        }

        // 保護ファイルチェック
        if ( ! WDRG_Safety::is_not_protected_file( $real_path ) ) {
            $this->logger->skip( '保護対象ファイルの削除はスキップしました。', $real_path );
            return false;
        }

        // シンボリックリンクは削除しない
        if ( is_link( $file_path ) ) {
            $this->logger->skip( 'シンボリックリンクの削除はスキップしました。', $file_path );
            return false;
        }

        if ( @unlink( $real_path ) ) {
            return true;
        }

        // WP_Filesystem を試行
        global $wp_filesystem;
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();

        if ( $wp_filesystem ) {
            return $wp_filesystem->delete( $real_path );
        }

        return false;
    }
}
