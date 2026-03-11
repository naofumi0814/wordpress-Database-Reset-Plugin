<?php
/**
 * WDRG_Safety - 安全装置・検証クラス
 *
 * パス検証、足し算チャレンジ、nonce管理、
 * 確認テキスト検証などの安全機能を提供する。
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WDRG_Safety {

    /** @var string nonce アクション名 */
    const NONCE_ACTION = 'wdrg_execute_reset';

    /** @var string nonce フィールド名 */
    const NONCE_FIELD = 'wdrg_nonce';

    /** @var string 足し算チャレンジのトランジェント接頭辞 */
    const CHALLENGE_TRANSIENT_PREFIX = 'wdrg_challenge_';

    /** @var string 確認テキスト（英語） */
    const CONFIRM_TEXT_EN = 'DELETE';

    /** @var string 確認テキスト（日本語） */
    const CONFIRM_TEXT_JA = '完全に削除することを理解しました';

    /**
     * WordPress標準テーマのホワイトリスト
     * Twenty で始まるシリーズをすべて含む
     *
     * @var array
     */
    const DEFAULT_THEMES = array(
        'twentyten',
        'twentyeleven',
        'twentytwelve',
        'twentythirteen',
        'twentyfourteen',
        'twentyfifteen',
        'twentysixteen',
        'twentyseventeen',
        'twentynineteen',
        'twentytwenty',
        'twentytwentyone',
        'twentytwentytwo',
        'twentytwentythree',
        'twentytwentyfour',
        'twentytwentyfive',
        'twentytwentysix',
    );

    /**
     * 追加削除可能フォルダのホワイトリスト
     * wp-content 直下のフォルダ名のみ許可
     *
     * @var array
     */
    const EXTRA_DIRS_WHITELIST = array(
        'mu-plugins',
        'cache',
        'upgrade',
        'languages',
    );

    /**
     * 削除禁止ファイル
     *
     * @var array
     */
    const PROTECTED_FILES = array(
        'wp-config.php',
        '.env',
        '.user.ini',
        'php.ini',
        '.htaccess',
        'web.config',
    );

    /**
     * 削除禁止ディレクトリ（ABSPATH直下）
     *
     * @var array
     */
    const PROTECTED_DIRS = array(
        'wp-admin',
        'wp-includes',
    );

    /**
     * 現在のユーザーが管理者権限を持っているか確認
     *
     * @return bool
     */
    public static function current_user_can_reset(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * nonce フィールドを出力
     */
    public static function nonce_field(): void {
        wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
    }

    /**
     * nonce を検証
     *
     * @return bool
     */
    public static function verify_nonce(): bool {
        $nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
        if ( empty( $nonce ) ) {
            return false;
        }
        return (bool) wp_verify_nonce( $nonce, self::NONCE_ACTION );
    }

    /**
     * AJAX用 nonce を検証
     *
     * @return bool
     */
    public static function verify_ajax_nonce(): bool {
        $nonce = '';
        if ( isset( $_POST[ self::NONCE_FIELD ] ) ) {
            $nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) );
        } elseif ( isset( $_GET[ self::NONCE_FIELD ] ) ) {
            $nonce = sanitize_text_field( wp_unslash( $_GET[ self::NONCE_FIELD ] ) );
        }
        if ( empty( $nonce ) ) {
            return false;
        }
        return (bool) wp_verify_nonce( $nonce, self::NONCE_ACTION );
    }

    /**
     * 足し算チャレンジを生成し、トランジェントに保存
     *
     * @return array ['num1' => int, 'num2' => int, 'token' => string]
     */
    public static function generate_challenge(): array {
        $num1  = wp_rand( 10, 99 );
        $num2  = wp_rand( 1, 50 );
        $token = wp_generate_password( 32, false );

        // 正解をトランジェントに保存（10分間有効）
        set_transient(
            self::CHALLENGE_TRANSIENT_PREFIX . $token,
            $num1 + $num2,
            600
        );

        return array(
            'num1'  => $num1,
            'num2'  => $num2,
            'token' => $token,
        );
    }

    /**
     * 足し算チャレンジの回答を検証
     *
     * @param string $token  チャレンジトークン
     * @param int    $answer ユーザーの回答
     * @return bool
     */
    public static function verify_challenge( string $token, int $answer ): bool {
        $token = sanitize_text_field( $token );
        if ( empty( $token ) ) {
            return false;
        }

        $correct = get_transient( self::CHALLENGE_TRANSIENT_PREFIX . $token );
        if ( false === $correct ) {
            return false;
        }

        // 使い回し防止: 検証後にトランジェントを削除
        delete_transient( self::CHALLENGE_TRANSIENT_PREFIX . $token );

        return (int) $correct === $answer;
    }

    /**
     * 確認テキストを検証
     *
     * @param string $input ユーザーが入力したテキスト
     * @return bool
     */
    public static function verify_confirm_text( string $input ): bool {
        $input = trim( $input );
        return ( $input === self::CONFIRM_TEXT_EN || $input === self::CONFIRM_TEXT_JA );
    }

    /**
     * パスが安全な削除対象かどうかを検証
     * wp-content 配下であること、シンボリックリンクでないこと、
     * ディレクトリトラバーサルがないことを確認
     *
     * @param string $path          検証するパス
     * @param bool   $allow_abspath ABSPATH直下も許可するか（通常はfalse）
     * @return bool
     */
    public static function is_safe_path( string $path, bool $allow_abspath = false ): bool {
        // 空パスは拒否
        if ( empty( $path ) ) {
            return false;
        }

        // realpath で正規化（存在しないパスはfalseを返す）
        $real_path = realpath( $path );
        if ( false === $real_path ) {
            return false;
        }

        // シンボリックリンクは拒否
        if ( is_link( $path ) ) {
            return false;
        }

        // wp-content のパスを正規化
        $wp_content_real = realpath( WP_CONTENT_DIR );
        if ( false === $wp_content_real ) {
            return false;
        }

        // wp-content 配下であることを確認
        if ( strpos( $real_path, $wp_content_real . DIRECTORY_SEPARATOR ) === 0 ) {
            return true;
        }

        // wp-content 自体は削除不可
        if ( $real_path === $wp_content_real ) {
            return false;
        }

        // ABSPATH 直下の許可チェック（DB処理など特殊ケース用）
        if ( $allow_abspath ) {
            $abspath_real = realpath( ABSPATH );
            if ( false !== $abspath_real && strpos( $real_path, $abspath_real . DIRECTORY_SEPARATOR ) === 0 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * 指定パスが保護対象ファイルでないことを確認
     *
     * @param string $path ファイルパス
     * @return bool true なら安全（保護対象でない）
     */
    public static function is_not_protected_file( string $path ): bool {
        $basename = basename( $path );
        return ! in_array( $basename, self::PROTECTED_FILES, true );
    }

    /**
     * テーマがWordPress標準テーマかどうかを判定
     *
     * @param string $theme_slug テーマのスラッグ（ディレクトリ名）
     * @return bool
     */
    public static function is_default_theme( string $theme_slug ): bool {
        return in_array( $theme_slug, self::DEFAULT_THEMES, true );
    }

    /**
     * テーマがこのプラグイン自身かどうかを判定（テーマには当てはまらないが安全装置として）
     *
     * @param string $slug スラッグ
     * @return bool
     */
    public static function is_self_plugin( string $slug ): bool {
        // プラグインファイルのベースネームと比較
        $self_basename = WDRG_PLUGIN_BASENAME;
        // ディレクトリ名だけでの比較も行う
        $self_dir = WDRG_SELF_DIR;

        return ( $slug === $self_basename
            || $slug === $self_dir
            || strpos( $slug, $self_dir . '/' ) === 0 );
    }

    /**
     * 追加フォルダがホワイトリストに含まれるか確認
     *
     * @param string $dir_name wp-content直下のディレクトリ名
     * @return bool
     */
    public static function is_allowed_extra_dir( string $dir_name ): bool {
        return in_array( $dir_name, self::EXTRA_DIRS_WHITELIST, true );
    }

    /**
     * リクエストが連打でないか確認（トランジェントベース）
     *
     * @return bool true なら実行OK
     */
    public static function check_rate_limit(): bool {
        $user_id = get_current_user_id();
        $key     = 'wdrg_rate_' . $user_id;
        if ( get_transient( $key ) ) {
            return false;
        }
        // 5秒間のロック
        set_transient( $key, 1, 5 );
        return true;
    }

    /**
     * 全検証を一括実行（POST送信時）
     *
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validate_execution_request(): array {
        $errors = array();

        // 権限チェック
        if ( ! self::current_user_can_reset() ) {
            $errors[] = '管理者権限がありません。';
        }

        // nonce チェック
        if ( ! self::verify_nonce() ) {
            $errors[] = 'セキュリティトークンが無効です。ページを再読み込みしてください。';
        }

        // マルチサイトチェック
        if ( is_multisite() ) {
            $errors[] = 'マルチサイト環境では実行できません。';
        }

        // 足し算チャレンジ検証
        $challenge_token  = isset( $_POST['wdrg_challenge_token'] ) ? sanitize_text_field( wp_unslash( $_POST['wdrg_challenge_token'] ) ) : '';
        $challenge_answer = isset( $_POST['wdrg_challenge_answer'] ) ? intval( $_POST['wdrg_challenge_answer'] ) : 0;
        if ( ! self::verify_challenge( $challenge_token, $challenge_answer ) ) {
            $errors[] = '確認問題の回答が正しくないか、有効期限が切れています。';
        }

        // 確認テキスト検証
        $confirm_text = isset( $_POST['wdrg_confirm_text'] ) ? sanitize_text_field( wp_unslash( $_POST['wdrg_confirm_text'] ) ) : '';
        if ( ! self::verify_confirm_text( $confirm_text ) ) {
            $errors[] = '確認テキストが正しくありません。「DELETE」または「完全に削除することを理解しました」と入力してください。';
        }

        // 連打チェック
        if ( ! self::check_rate_limit() ) {
            $errors[] = '連続実行が検出されました。数秒後にもう一度お試しください。';
        }

        return array(
            'valid'  => empty( $errors ),
            'errors' => $errors,
        );
    }
}
