<?php
/**
 * WDRG_Database - データベース初期化クラス
 *
 * WordPressテーブルプレフィックスに一致するテーブルの
 * DROP/TRUNCATE処理を安全に行う。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WDRG_Database {

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
     * 指定テーブルを DROP する
     *
     * @param array  $table_names  削除対象テーブル名の配列
     * @param string $method       'drop' または 'truncate'
     * @return array 処理結果
     */
    public function reset_tables( array $table_names, string $method = 'drop' ): array {
        global $wpdb;

        $results = array();
        $prefix  = $wpdb->prefix;

        $this->logger->info(
            'データベース初期化処理を開始します。方式: ' . strtoupper( $method ) . ' / 対象: ' . count( $table_names ) . 'テーブル'
        );

        if ( empty( $table_names ) ) {
            $this->logger->info( '削除対象のテーブルはありません。' );
            return $results;
        }

        foreach ( $table_names as $table_name ) {
            $table_name = sanitize_text_field( $table_name );

            // テーブル名がプレフィックスで始まることを厳密確認
            if ( strpos( $table_name, $prefix ) !== 0 ) {
                $this->logger->error( 'テーブルプレフィックスが一致しません。外部テーブルへの操作は拒否されました。', $table_name );
                $results[] = array(
                    'table'  => $table_name,
                    'status' => 'error',
                    'reason' => 'prefix_mismatch',
                );
                continue;
            }

            // テーブル名に不正な文字がないか検証（英数字、アンダースコア、プレフィックスに含まれうるハイフンのみ）
            if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table_name ) ) {
                $this->logger->error( 'テーブル名に不正な文字が含まれています。', $table_name );
                $results[] = array(
                    'table'  => $table_name,
                    'status' => 'error',
                    'reason' => 'invalid_name',
                );
                continue;
            }

            // テーブルが実在するか確認
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
                    DB_NAME,
                    $table_name
                )
            );

            if ( ! $exists ) {
                $this->logger->skip( 'テーブルが存在しません。', $table_name );
                $results[] = array(
                    'table'  => $table_name,
                    'status' => 'skipped',
                    'reason' => 'not_found',
                );
                continue;
            }

            // ユーザーテーブルの場合は追加警告
            $user_tables = array( $prefix . 'users', $prefix . 'usermeta' );
            if ( in_array( $table_name, $user_tables, true ) ) {
                $this->logger->warning( '⚠ ユーザーテーブルが削除対象に含まれています。ログイン不能になる可能性があります。', $table_name );
            }

            if ( $this->dry_run ) {
                $action = ( $method === 'truncate' ) ? 'TRUNCATE' : 'DROP';
                $this->logger->success( '[DRY-RUN] ' . $action . ' TABLE を実行対象です。', $table_name );
                $results[] = array(
                    'table'  => $table_name,
                    'status' => 'dry_run',
                    'method' => $method,
                );
                continue;
            }

            // 実際のDB操作
            $success = false;
            if ( $method === 'truncate' ) {
                $success = $this->truncate_table( $table_name );
            } else {
                $success = $this->drop_table( $table_name );
            }

            if ( $success ) {
                $this->logger->success( strtoupper( $method ) . ' TABLE を実行しました。', $table_name );
                $results[] = array(
                    'table'  => $table_name,
                    'status' => 'deleted',
                    'method' => $method,
                );
            } else {
                $this->logger->error( strtoupper( $method ) . ' TABLE に失敗しました。', $table_name );
                $results[] = array(
                    'table'  => $table_name,
                    'status' => 'error',
                    'reason' => 'query_failed',
                    'method' => $method,
                );
            }
        }

        // TRUNCATE の場合、wp_options を初期化していたら基本設定を再挿入
        if ( $method === 'truncate' && in_array( $prefix . 'options', $table_names, true ) && ! $this->dry_run ) {
            $this->reseed_options( $prefix );
        }

        $this->logger->info( 'データベース初期化処理が完了しました。' );
        return $results;
    }

    /**
     * wp_options に WordPress が動作するための最低限の設定を再挿入する
     *
     * @param string $prefix テーブルプレフィックス
     */
    private function reseed_options( string $prefix ): void {
        global $wpdb;

        $site_url = defined( 'WP_SITEURL' ) ? WP_SITEURL : '';
        $home_url = defined( 'WP_HOME' ) ? WP_HOME : $site_url;

        // wp-config.php で定義されていない場合はリクエストURLから推測
        if ( empty( $site_url ) ) {
            $scheme   = is_ssl() ? 'https' : 'http';
            $host     = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : 'localhost';
            $site_url = $scheme . '://' . $host;
            $home_url = $site_url;
        }

        $options_table = $prefix . 'options';

        $defaults = array(
            'siteurl'                 => $site_url,
            'home'                    => $home_url,
            'blogname'                => 'サイト名',
            'blogdescription'         => 'Just another WordPress site',
            'users_can_register'      => '0',
            'admin_email'             => '',
            'start_of_week'           => '1',
            'use_balanceTags'         => '0',
            'use_smilies'             => '1',
            'require_name_email'      => '1',
            'comments_notify'         => '1',
            'posts_per_rss'           => '10',
            'rss_use_excerpt'         => '0',
            'mailserver_url'          => 'mail.example.com',
            'mailserver_login'        => 'login@example.com',
            'mailserver_pass'         => 'password',
            'mailserver_port'         => '110',
            'default_category'        => '1',
            'default_comment_status'  => 'open',
            'default_ping_status'     => 'open',
            'default_pingback_flag'   => '1',
            'posts_per_page'          => '10',
            'date_format'             => 'Y年n月j日',
            'time_format'             => 'H:i',
            'links_updated_date_format' => 'Y年n月j日 H:i',
            'comment_moderation'      => '0',
            'moderation_notify'       => '1',
            'permalink_structure'     => '/%postname%/',
            'rewrite_rules'           => '',
            'template'                => 'twentytwentyfive',
            'stylesheet'              => 'twentytwentyfive',
            'active_plugins'          => serialize( array() ),
            'widget_block'            => serialize( array() ),
            'sidebars_widgets'        => serialize( array() ),
            'WPLANG'                  => '',
            'db_version'              => $GLOBALS['wp_db_version'] ?? '',
            'initial_db_version'      => $GLOBALS['wp_db_version'] ?? '',
            'wp_user_roles'           => '',
        );

        // 現在のログインユーザーのメールアドレスを使用
        $current_user = wp_get_current_user();
        if ( $current_user && $current_user->user_email ) {
            $defaults['admin_email'] = $current_user->user_email;
        }

        // 利用可能な標準テーマを検出
        $default_themes = array_reverse( WDRG_Safety::DEFAULT_THEMES );
        foreach ( $default_themes as $theme_slug ) {
            $theme = wp_get_theme( $theme_slug );
            if ( $theme->exists() ) {
                $defaults['template']   = $theme_slug;
                $defaults['stylesheet'] = $theme_slug;
                break;
            }
        }

        $inserted = 0;
        foreach ( $defaults as $name => $value ) {
            $result = $wpdb->insert(
                $options_table,
                array(
                    'option_name'  => $name,
                    'option_value' => $value,
                    'autoload'     => 'yes',
                ),
                array( '%s', '%s', '%s' )
            );
            if ( false !== $result ) {
                $inserted++;
            }
        }

        $this->logger->success(
            'wp_options に基本設定を再挿入しました（' . $inserted . '/' . count( $defaults ) . '件）。',
            $options_table
        );
    }

    /**
     * テーブルを DROP する
     *
     * @param string $table_name テーブル名（検証済み）
     * @return bool
     */
    private function drop_table( string $table_name ): bool {
        global $wpdb;

        // テーブル名はプレフィックスチェック・正規表現チェック済みのため直接使用
        // wpdb::prepare はテーブル名のプレースホルダをサポートしないため
        // 安全確認済みのテーブル名を直接 SQL に埋め込む
        $sql    = 'DROP TABLE IF EXISTS `' . $table_name . '`';
        $result = $wpdb->query( $sql );

        if ( false === $result ) {
            $this->logger->error( 'DROP TABLE クエリエラー: ' . $wpdb->last_error, $table_name );
            return false;
        }

        return true;
    }

    /**
     * テーブルを TRUNCATE する
     *
     * @param string $table_name テーブル名（検証済み）
     * @return bool
     */
    private function truncate_table( string $table_name ): bool {
        global $wpdb;

        $sql    = 'TRUNCATE TABLE `' . $table_name . '`';
        $result = $wpdb->query( $sql );

        if ( false === $result ) {
            $this->logger->error( 'TRUNCATE TABLE クエリエラー: ' . $wpdb->last_error, $table_name );
            return false;
        }

        return true;
    }
}
