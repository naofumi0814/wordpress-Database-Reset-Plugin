/**
 * WP Deep Reset Guard - 管理画面 JavaScript
 *
 * AJAX バッチ処理、足し算チャレンジ検証、
 * 進捗表示、ログ出力を管理する。
 */
(function ($) {
    'use strict';

    /** 足し算チャレンジが正解済みか */
    var challengeVerified = false;

    /** 処理中フラグ（連打防止） */
    var isProcessing = false;

    /** 現在の実行が dry-run かどうか */
    var currentDryRun = true;

    /** 処理ステップ一覧 */
    var steps = ['themes', 'plugins', 'uploads', 'extra_dirs', 'database'];

    /** 現在のステップインデックス */
    var currentStepIndex = 0;

    /** 全体の結果集約 */
    var totalResults = {
        success: 0,
        fail: 0,
        skip: 0
    };

    /**
     * 初期化
     */
    $(document).ready(function () {
        initCheckAllHandlers();
        initModeHandlers();
        initChallengeHandler();
        initExecuteHandlers();
    });

    /**
     * 「全選択」チェックボックスの制御
     */
    function initCheckAllHandlers() {
        // 「全選択」チェックボックス — click イベントで確実に拾う
        $(document).on('click change', '.wdrg-check-all', function (e) {
            if (e.type === 'click' && e.handled) return;
            e.handled = true;
            var group = $(this).data('group');
            var checked = $(this).is(':checked');
            $('.wdrg-item-check[data-group="' + group + '"]').prop('checked', checked).trigger('change.wdrg');
        });

        // 個別チェック変更時に全選択の状態を更新
        $(document).on('change change.wdrg', '.wdrg-item-check', function () {
            var group = $(this).data('group');
            var allChecks = $('.wdrg-item-check[data-group="' + group + '"]');
            var allChecked = allChecks.length === allChecks.filter(':checked').length;
            $('.wdrg-check-all[data-group="' + group + '"]').prop('checked', allChecked);
        });

        // 初期表示時に全選択チェックボックスの状態を同期
        $('.wdrg-check-all').each(function () {
            var group = $(this).data('group');
            var allChecks = $('.wdrg-item-check[data-group="' + group + '"]');
            if (allChecks.length === 0) return;
            var allChecked = allChecks.length === allChecks.filter(':checked').length;
            $(this).prop('checked', allChecked);
        });
    }

    /**
     * 操作モード切替ハンドラ
     */
    function initModeHandlers() {
        $('input[name="wdrg_mode"]').on('change', function () {
            var mode = $(this).val();
            if (mode === 'execute') {
                $('#wdrg-safety-section').slideDown();
            } else {
                $('#wdrg-safety-section').slideUp();
                $('#wdrg-btn-execute').prop('disabled', true);
            }
        });
    }

    /**
     * 足し算チャレンジの検証ハンドラ
     */
    function initChallengeHandler() {
        $('#wdrg-verify-challenge').on('click', function () {
            var answer = parseInt($('#wdrg-challenge-answer').val(), 10);
            var expected = parseInt(wdrgData.challengeNum1, 10) + parseInt(wdrgData.challengeNum2, 10);
            var $result = $('#wdrg-challenge-result');

            if (isNaN(answer)) {
                $result.text('数値を入力してください。').removeClass('success').addClass('error');
                return;
            }

            if (answer === expected) {
                challengeVerified = true;
                $result.text('正解です！').removeClass('error').addClass('success');
                $('#wdrg-confirm-text-area').slideDown();
                $(this).prop('disabled', true);
                $('#wdrg-challenge-answer').prop('disabled', true);
                checkExecuteReady();
            } else {
                $result.text('不正解です。もう一度お試しください。').removeClass('success').addClass('error');
            }
        });

        // Enter キーでも検証
        $('#wdrg-challenge-answer').on('keypress', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                $('#wdrg-verify-challenge').click();
            }
        });

        // 確認テキスト入力時に実行ボタンの有効化を判定
        $('#wdrg-confirm-text-input').on('input', function () {
            checkExecuteReady();
        });
    }

    /**
     * 本実行ボタンの有効/無効を判定
     */
    function checkExecuteReady() {
        var mode = $('input[name="wdrg_mode"]:checked').val();
        if (mode !== 'execute') {
            $('#wdrg-btn-execute').prop('disabled', true);
            return;
        }

        if (!challengeVerified) {
            $('#wdrg-btn-execute').prop('disabled', true);
            return;
        }

        var confirmText = $.trim($('#wdrg-confirm-text-input').val());
        if (confirmText === wdrgData.confirmTextEn || confirmText === wdrgData.confirmTextJa) {
            $('#wdrg-btn-execute').prop('disabled', false);
        } else {
            $('#wdrg-btn-execute').prop('disabled', true);
        }
    }

    /**
     * 実行ボタンハンドラ
     */
    function initExecuteHandlers() {
        // Dry-Run ボタン
        $('#wdrg-btn-dry-run').on('click', function () {
            if (isProcessing) {
                return;
            }
            startExecution(true);
        });

        // 本実行ボタン
        $('#wdrg-btn-execute').on('click', function () {
            if (isProcessing) {
                return;
            }

            // 最終確認ダイアログ
            var msg = '本当に実行しますか？\n\n' +
                '選択された項目がサーバーから完全に削除されます。\n' +
                'この操作は取り消しできません。\n\n' +
                '「OK」を押すと削除を開始します。';

            if (!confirm(msg)) {
                return;
            }

            startExecution(false);
        });
    }

    /**
     * 実行を開始する
     *
     * @param {boolean} dryRun dry-runモードかどうか
     */
    function startExecution(dryRun) {
        isProcessing = true;
        currentDryRun = dryRun;
        currentStepIndex = 0;
        totalResults = { success: 0, fail: 0, skip: 0 };

        // UIをリセット
        $('#wdrg-result-section').show();
        $('#wdrg-progress-area').show();
        $('#wdrg-summary').hide();
        $('#wdrg-log-output').empty();
        $('#wdrg-btn-dry-run, #wdrg-btn-execute').prop('disabled', true);

        // 選択されたステップだけ処理
        var activeSteps = getActiveSteps();
        if (activeSteps.length === 0) {
            appendLog('WARNING', '削除対象が選択されていません。', '');
            finishExecution();
            return;
        }

        appendLog('INFO', (dryRun ? '[DRY-RUN] ' : '') + '処理を開始します...', '');
        processNextStep(activeSteps, 0, dryRun);
    }

    /**
     * 選択されているステップを取得
     *
     * @return {Array} アクティブなステップ情報の配列
     */
    function getActiveSteps() {
        var activeSteps = [];

        steps.forEach(function (stepName) {
            var items = getCheckedItems(stepName);
            if (items.length > 0) {
                activeSteps.push({ name: stepName, items: items });
            }
        });

        return activeSteps;
    }

    /**
     * 指定グループのチェック済みアイテムを取得
     *
     * @param {string} group グループ名
     * @return {Array} チェック済みアイテムの値配列
     */
    function getCheckedItems(group) {
        var items = [];
        $('.wdrg-item-check[data-group="' + group + '"]:checked').each(function () {
            items.push($(this).val());
        });
        return items;
    }

    /**
     * 次のステップを処理する
     *
     * @param {Array}   activeSteps アクティブなステップ配列
     * @param {number}  index       現在のインデックス
     * @param {boolean} dryRun      dry-runモード
     */
    function processNextStep(activeSteps, index, dryRun) {
        if (index >= activeSteps.length) {
            finishExecution();
            return;
        }

        var step = activeSteps[index];
        var progress = Math.round(((index) / activeSteps.length) * 100);
        updateProgress(progress, getStepLabel(step.name) + ' を処理中...');

        appendLog('INFO', getStepLabel(step.name) + ' の処理を開始します...', '');

        var postData = {
            action: 'wdrg_execute_batch',
            wdrg_nonce: wdrgData.nonce,
            dry_run: dryRun ? '1' : '0',
            step: step.name,
            items: step.items
        };

        // 本実行時は認証情報を追加
        if (!dryRun) {
            postData.challenge_token = wdrgData.challengeToken;
            postData.challenge_answer = $('#wdrg-challenge-answer').val();
            postData.confirm_text = $('#wdrg-confirm-text-input').val();
            postData.is_first_step = (index === 0) ? '1' : '0';
        }

        // DB操作時はメソッドを追加
        if (step.name === 'database') {
            postData.db_method = $('input[name="wdrg_db_method"]:checked').val() || 'drop';
        }

        $.ajax({
            url: wdrgData.ajaxUrl,
            type: 'POST',
            data: postData,
            timeout: 300000, // 5分タイムアウト
            success: function (response) {
                if (response.success && response.data) {
                    var data = response.data;

                    // ログを表示
                    if (data.log && data.log.length) {
                        data.log.forEach(function (entry) {
                            appendLog(entry.level, entry.message, entry.target);
                        });
                    }

                    // サマリーを加算
                    if (data.summary) {
                        totalResults.success += parseInt(data.summary.success_count, 10) || 0;
                        totalResults.fail += parseInt(data.summary.fail_count, 10) || 0;
                        totalResults.skip += parseInt(data.summary.skip_count, 10) || 0;
                    }
                } else {
                    var errMsg = (response.data && response.data.message) ? response.data.message : '不明なエラー';
                    appendLog('ERROR', 'ステップ失敗: ' + errMsg, step.name);
                }

                // 次のステップへ
                processNextStep(activeSteps, index + 1, dryRun);
            },
            error: function (xhr, status, error) {
                appendLog('ERROR', 'AJAX エラー: ' + status + ' - ' + error, step.name);
                // エラーが起きても次のステップに進む
                processNextStep(activeSteps, index + 1, dryRun);
            }
        });
    }

    /**
     * 実行を完了する
     */
    function finishExecution() {
        isProcessing = false;
        updateProgress(100, '処理が完了しました。');

        // サマリーを表示
        $('#wdrg-summary').show();
        $('#wdrg-summary-success').text(totalResults.success + ' 件');
        $('#wdrg-summary-fail').text(totalResults.fail + ' 件');
        $('#wdrg-summary-skip').text(totalResults.skip + ' 件');

        // ボタンを再有効化
        $('#wdrg-btn-dry-run').prop('disabled', false);
        // 本実行ボタンは安全のため無効のまま（ページ再読み込みが必要）

        appendLog('INFO', '全処理が完了しました。', '');

        // 本実行時はリダイレクト案内を表示
        if (!currentDryRun && totalResults.success > 0) {
            var adminUrl = wdrgData.ajaxUrl.replace('/admin-ajax.php', '/');
            var $notice = $('<div class="wdrg-reset-complete-notice">' +
                '<h3>リセットが完了しました</h3>' +
                '<p>サイトが初期状態にリセットされました。</p>' +
                '<p><a href="' + adminUrl + '" class="button button-primary">管理画面トップへ移動</a> ' +
                '<a href="' + adminUrl + 'install.php" class="button">WordPress 再インストール</a></p>' +
                '</div>');
            $('#wdrg-summary').after($notice);
        }

        // ログ末尾にスクロール
        var $logOutput = $('#wdrg-log-output');
        $logOutput.scrollTop($logOutput[0].scrollHeight);
    }

    /**
     * プログレスバーを更新する
     *
     * @param {number} percent  進捗率（0-100）
     * @param {string} text     表示テキスト
     */
    function updateProgress(percent, text) {
        $('#wdrg-progress-fill').css('width', percent + '%').text(percent + '%');
        $('#wdrg-progress-text').text(text);
    }

    /**
     * ログエントリを追加する
     *
     * @param {string} level   ログレベル
     * @param {string} message メッセージ
     * @param {string} target  対象
     */
    function appendLog(level, message, target) {
        var now = new Date();
        var time = now.getFullYear() + '-' +
            pad(now.getMonth() + 1) + '-' +
            pad(now.getDate()) + ' ' +
            pad(now.getHours()) + ':' +
            pad(now.getMinutes()) + ':' +
            pad(now.getSeconds());

        var html = '<div class="log-entry">' +
            '<span class="log-time">[' + escapeHtml(time) + ']</span> ' +
            '<span class="log-level-' + escapeHtml(level) + '">[' + escapeHtml(level) + ']</span> ' +
            escapeHtml(message);

        if (target) {
            html += ' <span style="color:#8c8f94;">| ' + escapeHtml(target) + '</span>';
        }

        html += '</div>';

        var $logOutput = $('#wdrg-log-output');
        $logOutput.append(html);
        $logOutput.scrollTop($logOutput[0].scrollHeight);
    }

    /**
     * ステップ名を日本語ラベルに変換
     *
     * @param {string} step ステップ名
     * @return {string} 日本語ラベル
     */
    function getStepLabel(step) {
        var labels = {
            'themes': 'テーマ削除',
            'plugins': 'プラグイン削除',
            'uploads': 'uploads 削除',
            'extra_dirs': '追加フォルダ削除',
            'database': 'データベース初期化'
        };
        return labels[step] || step;
    }

    /**
     * 数値を2桁でゼロ埋めする
     *
     * @param {number} n 数値
     * @return {string}
     */
    function pad(n) {
        return n < 10 ? '0' + n : '' + n;
    }

    /**
     * HTML エスケープ
     *
     * @param {string} str 文字列
     * @return {string}
     */
    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
