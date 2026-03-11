<?php
/**
 * Plugin Name: WP Deep Reset Guard
 * Plugin URI:  https://example.com/wp-deep-reset-guard
 * Description: 管理者専用のサイト初期化・完全クリーンアッププラグイン。テーマ・プラグイン・uploads・DBを安全に削除し、WordPress標準テーマだけを残した初期状態に戻します。
 * Version:     1.0.0
 * Author:      Site Admin
 * Author URI:  https://example.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-deep-reset-guard
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 *
 * ======================================================
 *  警告: このプラグインはサイトのデータを完全に削除します。
 *  管理者が自分のサイトを初期化するためだけに使用してください。
 * ======================================================
 */

// 直接アクセス禁止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// マルチサイトでは動作禁止
if ( is_multisite() ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>WP Deep Reset Guard</strong>: マルチサイト環境では動作しません。プラグインを無効化してください。</p></div>';
    } );
    return;
}

// プラグイン定数
define( 'WDRG_VERSION', '1.0.0' );
define( 'WDRG_PLUGIN_FILE', __FILE__ );
define( 'WDRG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WDRG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WDRG_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// このプラグイン自身のディレクトリ名を定数化（自己削除防止用）
define( 'WDRG_SELF_DIR', basename( __DIR__ ) );

// クラスファイル読み込み
require_once WDRG_PLUGIN_DIR . 'includes/class-wdrg-logger.php';
require_once WDRG_PLUGIN_DIR . 'includes/class-wdrg-safety.php';
require_once WDRG_PLUGIN_DIR . 'includes/class-wdrg-scanner.php';
require_once WDRG_PLUGIN_DIR . 'includes/class-wdrg-executor.php';
require_once WDRG_PLUGIN_DIR . 'includes/class-wdrg-database.php';
require_once WDRG_PLUGIN_DIR . 'includes/class-wdrg-core.php';

// プラグイン初期化
add_action( 'plugins_loaded', array( 'WDRG_Core', 'get_instance' ) );
