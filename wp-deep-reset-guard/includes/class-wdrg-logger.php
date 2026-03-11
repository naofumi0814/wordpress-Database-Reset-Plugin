<?php
/**
 * WDRG_Logger - ログ管理クラス
 *
 * 削除処理の結果をファイルとメモリに記録する。
 * ログファイルは wp-content/wdrg-logs/ に保存される。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WDRG_Logger {

    /** @var string ログディレクトリのパス */
    private $log_dir;

    /** @var string 現在のログファイルパス */
    private $log_file;

    /** @var array メモリ上のログエントリ */
    private $entries = array();

    /** @var int 成功件数 */
    private $success_count = 0;

    /** @var int 失敗件数 */
    private $fail_count = 0;

    /** @var int スキップ件数 */
    private $skip_count = 0;

    /**
     * コンストラクタ
     */
    public function __construct() {
        $this->log_dir = WP_CONTENT_DIR . '/wdrg-logs';
        $this->ensure_log_dir();
        $this->log_file = $this->log_dir . '/wdrg-' . gmdate( 'Y-m-d-His' ) . '.log';
    }

    /**
     * ログディレクトリを作成する
     */
    private function ensure_log_dir(): void {
        if ( ! is_dir( $this->log_dir ) ) {
            wp_mkdir_p( $this->log_dir );
        }

        // .htaccess でログファイルへの直接アクセスを防止
        $htaccess = $this->log_dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Deny from all\n" );
        }

        // index.php でディレクトリ一覧を防止
        $index = $this->log_dir . '/index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, "<?php\n// Silence is golden.\n" );
        }
    }

    /**
     * 情報ログを記録
     *
     * @param string $message ログメッセージ
     * @param string $target  対象パスやテーブル名
     */
    public function info( string $message, string $target = '' ): void {
        $this->add_entry( 'INFO', $message, $target );
    }

    /**
     * 成功ログを記録
     *
     * @param string $message ログメッセージ
     * @param string $target  対象パスやテーブル名
     */
    public function success( string $message, string $target = '' ): void {
        $this->success_count++;
        $this->add_entry( 'SUCCESS', $message, $target );
    }

    /**
     * エラーログを記録
     *
     * @param string $message ログメッセージ
     * @param string $target  対象パスやテーブル名
     */
    public function error( string $message, string $target = '' ): void {
        $this->fail_count++;
        $this->add_entry( 'ERROR', $message, $target );
    }

    /**
     * 警告ログを記録
     *
     * @param string $message ログメッセージ
     * @param string $target  対象パスやテーブル名
     */
    public function warning( string $message, string $target = '' ): void {
        $this->add_entry( 'WARNING', $message, $target );
    }

    /**
     * スキップログを記録
     *
     * @param string $message ログメッセージ
     * @param string $target  対象パスやテーブル名
     */
    public function skip( string $message, string $target = '' ): void {
        $this->skip_count++;
        $this->add_entry( 'SKIP', $message, $target );
    }

    /**
     * ログエントリを追加
     *
     * @param string $level   ログレベル
     * @param string $message ログメッセージ
     * @param string $target  対象
     */
    private function add_entry( string $level, string $message, string $target ): void {
        $entry = array(
            'time'    => current_time( 'Y-m-d H:i:s' ),
            'level'   => $level,
            'message' => $message,
            'target'  => $target,
        );

        $this->entries[] = $entry;

        // ファイルにも書き出し
        $line = sprintf(
            "[%s] [%s] %s %s\n",
            $entry['time'],
            $level,
            $message,
            $target !== '' ? '| Target: ' . $target : ''
        );

        // ファイル書き込みに失敗してもプラグインの処理は続行
        @file_put_contents( $this->log_file, $line, FILE_APPEND | LOCK_EX );
    }

    /**
     * すべてのログエントリを取得
     *
     * @return array
     */
    public function get_entries(): array {
        return $this->entries;
    }

    /**
     * サマリーを取得
     *
     * @return array
     */
    public function get_summary(): array {
        return array(
            'success_count' => $this->success_count,
            'fail_count'    => $this->fail_count,
            'skip_count'    => $this->skip_count,
            'total'         => count( $this->entries ),
            'log_file'      => $this->log_file,
        );
    }

    /**
     * ログファイルパスを取得
     *
     * @return string
     */
    public function get_log_file(): string {
        return $this->log_file;
    }
}
