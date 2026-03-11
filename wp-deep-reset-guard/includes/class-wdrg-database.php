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
                $this->logger->info( '[DRY-RUN] ' . $action . ' TABLE を実行します。', $table_name );
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

        $this->logger->info( 'データベース初期化処理が完了しました。' );
        return $results;
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
