<?php

declare(strict_types=1);

/**
 * Japanese (ja) translation catalog for the Phlix console client.
 *
 * This file is loaded lazily by SugarCraft\Core\I18n\T on first lookup.
 * Each key is dot-separated: '<group>.<name>' where <group> is a logical
 * screen or feature grouping.
 *
 * Key set and placeholder tokens ({name}) must stay identical to en.php —
 * enforced by tests/I18n/LocaleCatalogsTest.php and scripts/check-i18n-catalogs.php.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

return [
    // ---- Auth (session expiry + sign-in/register failures — every surface) --
    'auth.session_expired' => 'セッションの有効期限が切れました。もう一度ログインしてください。',
    'auth.register_taken' => 'このユーザー名はすでに使用されています。別の名前を選んでください。',
    'auth.register_pending' => 'アカウントは承認待ちです。管理者によって承認されるとサインインできます。',
    'auth.register_disabled' => 'このサーバーでは現在、登録が無効になっています。',
    'auth.register_weak_password' => 'パスワードが弱すぎます。より強いパスワードを選んでください。',
    'auth.register_failed' => '登録に失敗しました。もう一度お試しください。',
    'auth.signin_failed' => 'サインインできませんでした。もう一度お試しください。',
    'auth.logout_revoke_failed' => 'サーバー側でサインアウトできませんでした。このデバイスからはサインアウト済みです。',

    // ---- Errors (shared failure surface — ExceptionSentences) ------
    'errors.network' => 'サーバーに接続できませんでした。URL と接続を確認してください。',
    'errors.unexpected' => '問題が発生しました。詳細はログを確認してください。',

    // ---- RecommendationsScreen ----------------------------------------
    'recommendations.title' => 'おすすめ',
    'recommendations.hint' => 'q  戻る      ↑↓  移動      ⏎  開く      x  非表示',
    'recommendations.load_failed' => 'おすすめを読み込めませんでした。',
    'recommendations.loading' => 'おすすめを読み込み中…',
    'recommendations.empty' => "まだおすすめはありません。\n  視聴を始めると、あなた向けの提案が表示されます！",
    'recommendations.dismiss_failed' => 'おすすめを非表示にできませんでした。',
    'recommendations.crumb' => 'おすすめ',

    // ---- DetailScreen -------------------------------------------------
    'detail.load_failed' => 'このタイトルを読み込めませんでした。',
    'detail.similar_load_failed' => '関連作品を読み込めませんでした。',
    'detail.missing_episodes_load_failed' => '欠落エピソードを読み込めませんでした。',
    'detail.children_load_failed' => 'このコンテンツを読み込めませんでした。',
    'detail.rating_save_failed' => '評価を保存できませんでした：',
    'detail.play_notice' => '▶  このタイトルには再生可能なソースがありません。',
    'detail.actions_hint' => '▶  p  再生      Esc  戻る',
    'detail.hint' => '↑↓  あらすじをスクロール      p  再生      s  シャッフル      C  キャスト      r  評価      F  お気に入り      w  視聴済み      l  高評価      j  低評価      d  ダウンロード      u  字幕      Esc  戻る',
    'detail.container_hint' => '↑↓←→  移動      ⏎  開く      s  シャッフル      Esc  戻る',
    'detail.error_hint' => 'r  再試行      Esc  戻る',
    'detail.loading_hint' => 'Esc  戻る',
    'detail.loading' => '読み込み中…',
    'detail.no_synopsis' => 'あらすじはありません。',
    'detail.more_like_this' => '関連作品',
    'detail.similar_navigate_hint' => '←→  移動      ⏎  開く',
    'detail.cast_label' => 'キャスト',
    'detail.directed_by' => '監督：',
    'detail.more_cast' => '  他{count}件',
    'detail.season' => 'シーズン',
    'detail.season_plural' => 'シーズン',
    'detail.episode' => 'エピソード',
    'detail.episode_plural' => 'エピソード',
    'detail.item' => '項目',
    'detail.item_plural' => '項目',
    'detail.missing_episodes_one' => '⚠  欠落エピソード {count} 話',
    'detail.missing_episodes_many' => '⚠  欠落エピソード {count} 話',
    'detail.loading_content' => '読み込み中…',

    // ---- Player -------------------------------------------------------
    'player.hint_main' => 'Space ⏯  ←→ ±10s  [ ] 再生速度  m モード',
    'player.hint_captions_off' => '  c 字幕',
    'player.hint_captions_on' => '  c 字幕✓',
    'player.hint_quality' => '  v 画質',
    'player.hint_next' => '  n 次へ',
    'player.hint_sleep' => '  z ⏰ ',
    'player.hint_back' => '  q 戻る',

    // ---- FilterBar ----------------------------------------------------
    'filter.search_placeholder' => '(入力して絞り込み)',
    'filter.search_label' => '検索：',
    'filter.sort_label' => '並び替え：',
    'filter.sort_name' => '名前',
    'filter.sort_year' => '年',
    'filter.sort_rating' => '評価',
    'filter.sort_date_added' => '追加日',
    'filter.sort_runtime' => '上映時間',
    'filter.sort_genre' => 'ジャンル',
    'filter.sort_artist' => 'アーティスト',
    'filter.order_label' => '順序：',
    'filter.order_asc' => '昇順',
    'filter.order_desc' => '降順',
    'filter.genre_label' => 'ジャンル：',

    // ---- SearchScreen ----------------------------------------------
    'search.no_results_for' => '{query} の結果はありません',
    'search.results_one' => '{count} 件の結果',
    'search.results_many' => '{count} 件の結果',


    // ---- LibraryScreen -----------------------------------------------
    'library.no_items' => 'アイテムはありません',
    'library.items_one' => '{count} 個のアイテム',
    'library.items_many' => '{count} 個のアイテム',


    // ---- SyncPlay errors ----------------------------------------------
    'syncplay.not_authenticated' => 'ウォッチグループを使う前にサインインしてください。',
    'syncplay.not_in_group' => '現在ウォッチグループに参加していません。',
    'syncplay.not_host' => 'この操作はグループのホストだけが行えます。',
    'syncplay.unknown_message' => 'サーバーがそのリクエストを認識できませんでした。',
    'syncplay.handler_error' => 'サーバーで問題が発生しました。',
    'syncplay.protocol_version_mismatch' => 'このアプリはサーバーのバージョンと互換性がありません。',
    'syncplay.invalid_new_host' => '要求された新しいホストは有効ではありません。',
    'syncplay.member_not_found' => 'そのメンバーはグループ内に見つかりませんでした。',
    'syncplay.same_host' => 'そのメンバーはすでにグループのホストです。',
    'syncplay.create_failed' => 'ウォッチグループを作成できませんでした。',
    'syncplay.join_failed' => 'ウォッチグループに参加できませんでした。',
    'syncplay.leave_failed' => 'ウォッチグループから退出できませんでした。',
    'syncplay.group_limit_reached' => 'ホストのウォッチグループが上限に達しました。',
    'syncplay.group_not_found' => 'そのウォッチグループは存在しません。',
    'syncplay.invalid_password' => 'パスワードが正しくありません。',
    'syncplay.group_full' => 'そのウォッチグループは満員です。',
    'syncplay.queue_limit_exceeded' => 'そのウォッチグループの再生キューが満杯です。',
    'syncplay.unknown_error' => '同期エラーが発生しました。',

    // ---- SyncPlay modal (group noun = this catalog's glossary term) --
    'syncplay.modal_public_groups' => '公開ウォッチグループ：',
    'syncplay.modal_create_new' => '+ 新しいウォッチグループを作成',
    'syncplay.modal_create_title' => 'ウォッチグループを作成',
    'syncplay.modal_joining' => 'ウォッチグループに参加中…',

    'browse.loading' => 'ライブラリを読み込んでいます…',
    'browse.no_libraries' => 'まだライブラリがありません。',
    'browse.no_libraries_admin' => ': を押してコマンドパレットを開き、Admin → Libraries から追加してください。',
    'browse.no_libraries_member' => '管理者にライブラリの追加を依頼してください。',
    'browse.hint_refresh_quit' => 'R  更新      q  終了',

    // ---- Command palette (App::staticActions) ---------------------------
    'palette.search'     => '検索',
    'palette.home'       => 'ホーム',
    'palette.settings'   => '設定',
    'palette.keys'       => 'キーボードショートカット',
    'palette.stats'      => '統計',
    'palette.watch_history'  => '視聴履歴',
    'palette.favorites'  => 'お気に入り',
    'palette.photos'     => '写真',
    'palette.music_artists'  => 'ミュージックアーティスト',
    'palette.playlists'  => 'プレイリスト',
    'palette.collections'  => 'コレクション',
    'palette.servers'    => 'サーバー',
    'palette.shared_with_me'  => '自分と共有',
    'palette.invite_links'  => '招待リンク',
    'palette.federation_shares'  => 'フェデレーション共有',
    'palette.show_metrics'  => 'メトリクスを表示',
    'palette.hide_metrics'  => 'メトリクスを隠す',
    'palette.admin'      => '管理',
    'palette.resume_playback'  => '再生を再開',
    'palette.pause_playback'  => '再生を一時停止',
    'palette.stop_playback'  => '再生を停止',
    'palette.sign_out'   => 'サインアウト',
    'palette.quit'       => '終了',
    'palette.go_to'      => '{name} へ移動',
];
