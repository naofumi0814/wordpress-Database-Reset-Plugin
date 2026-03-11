<?php
/**
 * WDRG_Core - メインコントローラクラス
 *
 * プラグインの初期化、管理メニュー登録、
 * AJAX ハンドラ、フォーム送信処理を統括する。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WDRG_Core {

    /** @var WDRG_Core|null シングルトンインスタンス */
    private static $instance = null;

    /** @var string 管理画面のスラッグ */
    const MENU_SLUG = 'wdrg-reset';

    /** @var array|null 足し算チャレンジデータ（ページ内で1回だけ生成） */
    private $challenge = null;

    /**
     * シングルトンインスタンスを取得
     *
     * @return WDRG_Core
     */
    public static function get_instance(): WDRG_Core {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * コンストラクタ
     */
    private function __construct() {
        // 管理画面のみで動作
        if ( ! is_admin() ) {
            return;
        }

        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // AJAX バッチ処理ハンドラ
        add_action( 'wp_ajax_wdrg_execute_batch', array( $this, 'ajax_execute_batch' ) );
        add_action( 'wp_ajax_wdrg_scan', array( $this, 'ajax_scan' ) );
    }

    /**
     * 管理メニューを追加
     */
    public function add_admin_menu(): void {
        add_management_page(
            'WP Deep Reset Guard',
            '⚠ サイト初期化',
            'manage_options',
            self::MENU_SLUG,
            array( $this, 'render_admin_page' )
        );
    }

    /**
     * CSS/JS を読み込む
     *
     * @param string $hook 現在のページフック
     */
    public function enqueue_assets( string $hook ): void {
        // このプラグインのページ以外では読み込まない
        if ( 'tools_page_' . self::MENU_SLUG !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'wdrg-admin-style',
            WDRG_PLUGIN_URL . 'assets/admin-style.css',
            array(),
            WDRG_VERSION
        );

        wp_enqueue_script(
            'wdrg-admin-script',
            WDRG_PLUGIN_URL . 'assets/admin-script.js',
            array( 'jquery' ),
            WDRG_VERSION,
            true
        );

        // 足し算チャレンジを生成（1回だけ）
        if ( null === $this->challenge ) {
            $this->challenge = WDRG_Safety::generate_challenge();
        }
        $challenge = $this->challenge;

        wp_localize_script( 'wdrg-admin-script', 'wdrgData', array(
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( WDRG_Safety::NONCE_ACTION ),
            'challengeNum1'  => $challenge['num1'],
            'challengeNum2'  => $challenge['num2'],
            'challengeToken' => $challenge['token'],
            'confirmTextEn'  => WDRG_Safety::CONFIRM_TEXT_EN,
            'confirmTextJa'  => WDRG_Safety::CONFIRM_TEXT_JA,
        ) );
    }

    /**
     * 管理画面を描画
     */
    public function render_admin_page(): void {
        // 権限チェック
        if ( ! WDRG_Safety::current_user_can_reset() ) {
            wp_die( 'アクセスが拒否されました。管理者権限が必要です。' );
        }

        // マルチサイトチェック
        if ( is_multisite() ) {
            wp_die( 'マルチサイト環境では使用できません。' );
        }

        // スキャンデータを取得
        $site_info   = WDRG_Scanner::get_site_info();
        $themes      = WDRG_Scanner::scan_themes();
        $plugins     = WDRG_Scanner::scan_plugins();
        $uploads     = WDRG_Scanner::scan_uploads();
        $extra_dirs  = WDRG_Scanner::scan_extra_dirs();
        $db_tables   = WDRG_Scanner::scan_database();

        // 足し算チャレンジ（enqueue_assets で生成済みのものを使う）
        if ( null === $this->challenge ) {
            $this->challenge = WDRG_Safety::generate_challenge();
        }
        $challenge = $this->challenge;

        // テンプレート読み込み
        include WDRG_PLUGIN_DIR . 'templates/admin-page.php';
    }

    /**
     * AJAX: スキャン結果を返す
     */
    public function ajax_scan(): void {
        // 権限・nonceチェック
        if ( ! WDRG_Safety::current_user_can_reset() ) {
            wp_send_json_error( array( 'message' => 'アクセスが拒否されました。' ), 403 );
        }

        if ( ! WDRG_Safety::verify_ajax_nonce() ) {
            wp_send_json_error( array( 'message' => 'セキュリティトークンが無効です。' ), 403 );
        }

        $type = isset( $_POST['scan_type'] ) ? sanitize_text_field( wp_unslash( $_POST['scan_type'] ) ) : '';

        switch ( $type ) {
            case 'themes':
                $data = WDRG_Scanner::scan_themes();
                break;
            case 'plugins':
                $data = WDRG_Scanner::scan_plugins();
                break;
            case 'uploads':
                $data = WDRG_Scanner::scan_uploads();
                break;
            case 'extra_dirs':
                $data = WDRG_Scanner::scan_extra_dirs();
                break;
            case 'database':
                $data = WDRG_Scanner::scan_database();
                break;
            case 'all':
                $data = array(
                    'site_info'  => WDRG_Scanner::get_site_info(),
                    'themes'     => WDRG_Scanner::scan_themes(),
                    'plugins'    => WDRG_Scanner::scan_plugins(),
                    'uploads'    => WDRG_Scanner::scan_uploads(),
                    'extra_dirs' => WDRG_Scanner::scan_extra_dirs(),
                    'database'   => WDRG_Scanner::scan_database(),
                );
                break;
            default:
                wp_send_json_error( array( 'message' => '不明なスキャンタイプです。' ), 400 );
                return;
        }

        wp_send_json_success( $data );
    }

    /**
     * AJAX: バッチ削除を実行
     */
    public function ajax_execute_batch(): void {
        // 権限チェック
        if ( ! WDRG_Safety::current_user_can_reset() ) {
            wp_send_json_error( array( 'message' => 'アクセスが拒否されました。' ), 403 );
        }

        // nonce チェック
        if ( ! WDRG_Safety::verify_ajax_nonce() ) {
            wp_send_json_error( array( 'message' => 'セキュリティトークンが無効です。' ), 403 );
        }

        // マルチサイトチェック
        if ( is_multisite() ) {
            wp_send_json_error( array( 'message' => 'マルチサイト環境では実行できません。' ), 403 );
        }

        // パラメータ取得
        $dry_run = isset( $_POST['dry_run'] ) && $_POST['dry_run'] === '1';
        $step    = isset( $_POST['step'] ) ? sanitize_text_field( wp_unslash( $_POST['step'] ) ) : '';

        // 本実行時は追加検証
        if ( ! $dry_run ) {
            // 足し算チャレンジ検証
            $challenge_token  = isset( $_POST['challenge_token'] ) ? sanitize_text_field( wp_unslash( $_POST['challenge_token'] ) ) : '';
            $challenge_answer = isset( $_POST['challenge_answer'] ) ? intval( $_POST['challenge_answer'] ) : 0;

            // 最初のステップでのみチャレンジを検証（後続ステップではトークンが既に消費されている）
            $is_first_step = isset( $_POST['is_first_step'] ) && $_POST['is_first_step'] === '1';
            if ( $is_first_step ) {
                if ( ! WDRG_Safety::verify_challenge( $challenge_token, $challenge_answer ) ) {
                    wp_send_json_error( array( 'message' => '確認問題の回答が正しくないか、有効期限が切れています。' ), 400 );
                }
            }

            // 確認テキスト検証
            $confirm_text = isset( $_POST['confirm_text'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm_text'] ) ) : '';
            if ( ! WDRG_Safety::verify_confirm_text( $confirm_text ) ) {
                wp_send_json_error( array( 'message' => '確認テキストが正しくありません。' ), 400 );
            }

            // 連打チェック
            if ( ! WDRG_Safety::check_rate_limit() ) {
                wp_send_json_error( array( 'message' => '連続実行が検出されました。数秒後にもう一度お試しください。' ), 429 );
            }
        }

        // ログインスタンス生成
        $logger = new WDRG_Logger();

        // 実行
        $result = $this->execute_step( $step, $dry_run, $logger );

        // 結果を返す
        wp_send_json_success( array(
            'step'    => $step,
            'dry_run' => $dry_run,
            'result'  => $result,
            'log'     => $logger->get_entries(),
            'summary' => $logger->get_summary(),
        ) );
    }

    /**
     * 指定ステップの処理を実行
     *
     * @param string      $step    処理ステップ名
     * @param bool        $dry_run dry-runモード
     * @param WDRG_Logger $logger  ログインスタンス
     * @return array 処理結果
     */
    private function execute_step( string $step, bool $dry_run, WDRG_Logger $logger ): array {
        $result = array();

        // 対象アイテムをPOSTから取得
        $items = isset( $_POST['items'] ) && is_array( $_POST['items'] )
            ? array_map( 'sanitize_text_field', wp_unslash( $_POST['items'] ) )
            : array();

        switch ( $step ) {
            case 'themes':
                $executor = new WDRG_Executor( $logger, $dry_run );
                $result   = $executor->delete_themes( $items );
                break;

            case 'plugins':
                $executor = new WDRG_Executor( $logger, $dry_run );
                $result   = $executor->delete_plugins( $items );
                break;

            case 'uploads':
                $executor = new WDRG_Executor( $logger, $dry_run );
                $result   = $executor->delete_uploads( $items );
                break;

            case 'extra_dirs':
                $executor = new WDRG_Executor( $logger, $dry_run );
                $result   = $executor->delete_extra_dirs( $items );
                break;

            case 'database':
                $method    = isset( $_POST['db_method'] ) ? sanitize_text_field( wp_unslash( $_POST['db_method'] ) ) : 'drop';
                $db_handler = new WDRG_Database( $logger, $dry_run );
                $result    = $db_handler->reset_tables( $items, $method );
                break;

            default:
                $logger->error( '不明な処理ステップです。', $step );
                break;
        }

        return $result;
    }
}
