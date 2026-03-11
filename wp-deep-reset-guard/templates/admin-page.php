<?php
/**
 * 管理画面テンプレート
 *
 * 変数は WDRG_Core::render_admin_page() から渡される:
 * $site_info, $themes, $plugins, $uploads, $extra_dirs, $db_tables, $challenge
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap wdrg-wrap">
    <h1>WP Deep Reset Guard - サイト初期化</h1>

    <!-- 警告バナー -->
    <div class="wdrg-warning-banner">
        <h2>⚠ 重大な警告</h2>
        <p>このツールは、サイトのテーマ・プラグイン・アップロードファイル・データベースを<strong>完全に削除</strong>します。</p>
        <p>削除されたデータは<strong>復元できません</strong>。実行前に必ずバックアップを取得してください。</p>
        <p>FTPからも見えなくなるよう、サーバー上のファイル・フォルダを実際に削除します。</p>
    </div>

    <!-- サイト情報 -->
    <div class="wdrg-section wdrg-site-info">
        <h2>サイト情報</h2>
        <table class="widefat">
            <tbody>
                <tr><th>サイトURL</th><td><?php echo esc_html( $site_info['site_url'] ); ?></td></tr>
                <tr><th>WordPress</th><td><?php echo esc_html( $site_info['wp_version'] ); ?></td></tr>
                <tr><th>PHP</th><td><?php echo esc_html( $site_info['php_version'] ); ?></td></tr>
                <tr><th>現在のテーマ</th><td><?php echo esc_html( $site_info['current_theme'] ); ?> (<?php echo esc_html( $site_info['current_theme_slug'] ); ?>)</td></tr>
                <tr><th>有効プラグイン数</th><td><?php echo esc_html( $site_info['active_plugins'] ); ?>個</td></tr>
                <tr><th>DBプレフィックス</th><td><?php echo esc_html( $site_info['db_prefix'] ); ?></td></tr>
            </tbody>
        </table>
    </div>

    <!-- 操作モード選択 -->
    <div class="wdrg-section wdrg-mode-section">
        <h2>操作モード</h2>
        <label class="wdrg-mode-label">
            <input type="radio" name="wdrg_mode" value="dry_run" checked="checked" />
            <strong>Dry-Run（テスト実行）</strong> - 削除せず、何が削除されるかだけを確認します
        </label>
        <label class="wdrg-mode-label">
            <input type="radio" name="wdrg_mode" value="execute" />
            <strong>本実行</strong> - 選択した項目を実際に削除します（取り消し不可）
        </label>
    </div>

    <!-- A. テーマ削除 -->
    <div class="wdrg-section" id="wdrg-themes-section">
        <h2>A. テーマ削除</h2>
        <p class="description">WordPress標準テーマ以外を削除対象として表示します。標準テーマは自動的に除外されます。</p>
        <?php if ( empty( $themes ) ) : ?>
            <p class="wdrg-empty">削除対象のテーマはありません。</p>
        <?php else : ?>
            <table class="widefat wdrg-item-table">
                <thead>
                    <tr>
                        <th class="check-column"><input type="checkbox" class="wdrg-check-all" data-group="themes" /></th>
                        <th>テーマ名</th>
                        <th>スラッグ</th>
                        <th>バージョン</th>
                        <th>サイズ</th>
                        <th>状態</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $themes as $theme ) : ?>
                        <tr>
                            <td><input type="checkbox" class="wdrg-item-check" data-group="themes" value="<?php echo esc_attr( $theme['slug'] ); ?>" checked="checked" /></td>
                            <td><?php echo esc_html( $theme['name'] ); ?></td>
                            <td><code><?php echo esc_html( $theme['slug'] ); ?></code></td>
                            <td><?php echo esc_html( $theme['version'] ); ?></td>
                            <td><?php echo esc_html( $theme['size_human'] ); ?></td>
                            <td>
                                <?php if ( $theme['is_active'] ) : ?>
                                    <span class="wdrg-badge wdrg-badge-active">有効</span>
                                    <span class="wdrg-note">※ 削除前に標準テーマへ自動切替します</span>
                                <?php else : ?>
                                    <span class="wdrg-badge wdrg-badge-inactive">無効</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- B. プラグイン削除 -->
    <div class="wdrg-section" id="wdrg-plugins-section">
        <h2>B. プラグイン削除</h2>
        <p class="description">このプラグイン自身以外のプラグインを削除対象として表示します。フォルダごと完全削除されます。</p>
        <?php if ( empty( $plugins ) ) : ?>
            <p class="wdrg-empty">削除対象のプラグインはありません。</p>
        <?php else : ?>
            <table class="widefat wdrg-item-table">
                <thead>
                    <tr>
                        <th class="check-column"><input type="checkbox" class="wdrg-check-all" data-group="plugins" /></th>
                        <th>プラグイン名</th>
                        <th>ファイル</th>
                        <th>バージョン</th>
                        <th>サイズ</th>
                        <th>状態</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $plugins as $plugin ) : ?>
                        <tr>
                            <td><input type="checkbox" class="wdrg-item-check" data-group="plugins" value="<?php echo esc_attr( $plugin['file'] ); ?>" checked="checked" /></td>
                            <td><?php echo esc_html( $plugin['name'] ); ?></td>
                            <td><code><?php echo esc_html( $plugin['file'] ); ?></code></td>
                            <td><?php echo esc_html( $plugin['version'] ); ?></td>
                            <td><?php echo esc_html( $plugin['size_human'] ); ?></td>
                            <td>
                                <?php if ( $plugin['is_active'] ) : ?>
                                    <span class="wdrg-badge wdrg-badge-active">有効</span>
                                    <span class="wdrg-note">※ 削除前に自動停止します</span>
                                <?php else : ?>
                                    <span class="wdrg-badge wdrg-badge-inactive">無効</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- C. uploads 削除 -->
    <div class="wdrg-section" id="wdrg-uploads-section">
        <h2>C. uploads 削除</h2>
        <p class="description">wp-content/uploads/ 配下のファイルとフォルダを削除します。年月フォルダも含まれます。</p>
        <?php if ( ! $uploads['exists'] ) : ?>
            <p class="wdrg-empty">uploads ディレクトリが存在しません。</p>
        <?php elseif ( empty( $uploads['items'] ) ) : ?>
            <p class="wdrg-empty">削除対象のファイル・フォルダはありません。</p>
        <?php else : ?>
            <div class="wdrg-uploads-summary">
                <p>
                    <strong>合計:</strong>
                    ファイル <?php echo esc_html( number_format( $uploads['file_count'] ) ); ?>件 /
                    フォルダ <?php echo esc_html( number_format( $uploads['dir_count'] ) ); ?>件 /
                    総サイズ <?php echo esc_html( $uploads['size_human'] ); ?>
                </p>
            </div>
            <table class="widefat wdrg-item-table">
                <thead>
                    <tr>
                        <th class="check-column"><input type="checkbox" class="wdrg-check-all" data-group="uploads" /></th>
                        <th>名前</th>
                        <th>種類</th>
                        <th>サイズ</th>
                        <th>内容</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $uploads['items'] as $item ) : ?>
                        <tr>
                            <td><input type="checkbox" class="wdrg-item-check" data-group="uploads" value="<?php echo esc_attr( $item['name'] ); ?>" checked="checked" /></td>
                            <td><code><?php echo esc_html( $item['name'] ); ?></code></td>
                            <td><?php echo $item['type'] === 'directory' ? 'フォルダ' : 'ファイル'; ?></td>
                            <td><?php echo esc_html( $item['size_human'] ); ?></td>
                            <td>
                                <?php if ( $item['type'] === 'directory' ) : ?>
                                    ファイル <?php echo esc_html( number_format( $item['file_count'] ) ); ?>件 / サブフォルダ <?php echo esc_html( number_format( $item['dir_count'] ) ); ?>件
                                <?php else : ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- D. 追加フォルダ削除 -->
    <div class="wdrg-section" id="wdrg-extra-section">
        <h2>D. 追加フォルダ削除</h2>
        <p class="description">wp-content 配下の補助フォルダです。<strong>初期状態では未選択</strong>です。必要に応じてチェックしてください。</p>
        <?php if ( empty( $extra_dirs ) ) : ?>
            <p class="wdrg-empty">対象となる追加フォルダはありません。</p>
        <?php else : ?>
            <table class="widefat wdrg-item-table">
                <thead>
                    <tr>
                        <th class="check-column"><input type="checkbox" class="wdrg-check-all" data-group="extra_dirs" /></th>
                        <th>フォルダ名</th>
                        <th>サイズ</th>
                        <th>内容</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $extra_dirs as $dir ) : ?>
                        <tr>
                            <td><input type="checkbox" class="wdrg-item-check" data-group="extra_dirs" value="<?php echo esc_attr( $dir['name'] ); ?>" /></td>
                            <td><code><?php echo esc_html( $dir['name'] ); ?></code></td>
                            <td><?php echo esc_html( $dir['size_human'] ); ?></td>
                            <td>ファイル <?php echo esc_html( number_format( $dir['file_count'] ) ); ?>件 / サブフォルダ <?php echo esc_html( number_format( $dir['dir_count'] ) ); ?>件</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- E. データベース初期化 -->
    <div class="wdrg-section" id="wdrg-database-section">
        <h2>E. データベース初期化</h2>
        <p class="description">WordPressテーブルプレフィックス「<?php echo esc_html( $site_info['db_prefix'] ); ?>」に一致するテーブルが対象です。<strong>初期状態では未選択</strong>です。</p>

        <div class="wdrg-db-warning">
            <p><strong>⚠ データベース操作は特に危険です。</strong></p>
            <p>TRUNCATE: テーブル構造は残してデータだけ削除（WordPress が動作可能な状態を維持）</p>
            <p>DROP: テーブルごと完全削除（WordPress の再インストールが必要になります）</p>
        </div>

        <div class="wdrg-db-method">
            <label><input type="radio" name="wdrg_db_method" value="truncate" checked="checked" /> TRUNCATE TABLE（データだけ削除）<strong>— 推奨</strong></label>
            <label><input type="radio" name="wdrg_db_method" value="drop" /> DROP TABLE（テーブルごと削除）</label>
        </div>

        <?php if ( empty( $db_tables ) ) : ?>
            <p class="wdrg-empty">対象テーブルが見つかりません。</p>
        <?php else : ?>
            <table class="widefat wdrg-item-table">
                <thead>
                    <tr>
                        <th class="check-column"><input type="checkbox" class="wdrg-check-all" data-group="database" /></th>
                        <th>テーブル名</th>
                        <th>行数</th>
                        <th>サイズ</th>
                        <th>エンジン</th>
                        <th>注意</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $db_tables as $table ) : ?>
                        <?php
                            $is_protected = $table['is_user_table'] || $table['is_options_table'];
                            $default_checked = ! $is_protected;
                        ?>
                        <tr class="<?php echo $table['is_user_table'] ? 'wdrg-row-danger' : ''; ?>">
                            <td><input type="checkbox" class="wdrg-item-check" data-group="database" value="<?php echo esc_attr( $table['name'] ); ?>" <?php echo $default_checked ? 'checked="checked"' : ''; ?> /></td>
                            <td><code><?php echo esc_html( $table['name'] ); ?></code></td>
                            <td><?php echo esc_html( number_format( $table['rows'] ) ); ?></td>
                            <td><?php echo esc_html( $table['size_human'] ); ?></td>
                            <td><?php echo esc_html( $table['engine'] ); ?></td>
                            <td>
                                <?php if ( $table['is_user_table'] ) : ?>
                                    <span class="wdrg-badge wdrg-badge-danger">⚠ ユーザーテーブル - 削除するとログイン不能になります</span>
                                <?php elseif ( $table['is_options_table'] ) : ?>
                                    <span class="wdrg-badge wdrg-badge-danger">⚠ 設定テーブル - 初期化後に基本設定を再挿入します</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- 安全装置セクション -->
    <div class="wdrg-section wdrg-safety-section" id="wdrg-safety-section" style="display:none;">
        <h2>安全確認</h2>

        <!-- 足し算チャレンジ -->
        <div class="wdrg-challenge" id="wdrg-challenge-area">
            <h3>確認問題</h3>
            <p>以下の計算問題に正解してください：</p>
            <div class="wdrg-challenge-question">
                <span id="wdrg-challenge-display">
                    <?php echo esc_html( $challenge['num1'] ); ?> + <?php echo esc_html( $challenge['num2'] ); ?> = ?
                </span>
            </div>
            <input type="hidden" id="wdrg-challenge-token" value="<?php echo esc_attr( $challenge['token'] ); ?>" />
            <input type="number" id="wdrg-challenge-answer" class="regular-text" placeholder="回答を入力" autocomplete="off" />
            <button type="button" id="wdrg-verify-challenge" class="button">回答を確認</button>
            <span id="wdrg-challenge-result" class="wdrg-challenge-result"></span>
        </div>

        <!-- 確認テキスト入力 -->
        <div class="wdrg-confirm-text" id="wdrg-confirm-text-area" style="display:none;">
            <h3>最終確認</h3>
            <p>以下のどちらかのテキストを正確に入力してください：</p>
            <ul>
                <li><code>DELETE</code></li>
                <li><code>完全に削除することを理解しました</code></li>
            </ul>
            <input type="text" id="wdrg-confirm-text-input" class="regular-text" placeholder="確認テキストを入力" autocomplete="off" />
        </div>
    </div>

    <!-- 実行ボタン -->
    <div class="wdrg-section wdrg-action-section">
        <button type="button" id="wdrg-btn-dry-run" class="button button-secondary button-hero">
            Dry-Run 実行（テスト）
        </button>
        <button type="button" id="wdrg-btn-execute" class="button button-primary button-hero wdrg-btn-danger" disabled="disabled">
            本実行（削除開始）
        </button>
    </div>

    <!-- 進捗・結果表示 -->
    <div class="wdrg-section wdrg-result-section" id="wdrg-result-section" style="display:none;">
        <h2>実行結果</h2>

        <!-- プログレスバー -->
        <div class="wdrg-progress" id="wdrg-progress-area" style="display:none;">
            <div class="wdrg-progress-bar">
                <div class="wdrg-progress-fill" id="wdrg-progress-fill" style="width:0%;">0%</div>
            </div>
            <p id="wdrg-progress-text">処理中...</p>
        </div>

        <!-- サマリー -->
        <div id="wdrg-summary" style="display:none;">
            <table class="widefat">
                <tbody>
                    <tr><th>成功</th><td id="wdrg-summary-success">0</td></tr>
                    <tr><th>失敗</th><td id="wdrg-summary-fail">0</td></tr>
                    <tr><th>スキップ</th><td id="wdrg-summary-skip">0</td></tr>
                </tbody>
            </table>
        </div>

        <!-- ログ表示 -->
        <div class="wdrg-log-area">
            <h3>処理ログ</h3>
            <div id="wdrg-log-output" class="wdrg-log-output"></div>
        </div>
    </div>
</div>
