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
    // ---- RecommendationsScreen ----------------------------------------
    'recommendations.title' => 'おすすめ',
    'recommendations.hint' => 'Q: 戻る  ↑↓: 移動  Enter: 開く  X: 非表示',
    'recommendations.session_expired' => 'セッションの有効期限が切れました。もう一度ログインしてください。',
    'recommendations.load_failed' => 'おすすめを読み込めませんでした。',
    'recommendations.loading' => 'おすすめを読み込み中…',
    'recommendations.empty' => "まだおすすめはありません。\n  視聴を始めると、あなた向けの提案が表示されます！",
    'recommendations.dismiss_failed' => 'おすすめを非表示にできませんでした。',
    'recommendations.crumb' => 'おすすめ',

    // ---- DetailScreen -------------------------------------------------
    'detail.session_expired' => 'セッションの有効期限が切れました。もう一度ログインしてください。',
    'detail.load_failed' => 'このタイトルを読み込めませんでした。',
    'detail.similar_load_failed' => '関連作品を読み込めませんでした。',
    'detail.missing_episodes_load_failed' => '欠落エピソードを読み込めませんでした。',
    'detail.children_load_failed' => 'このコンテンツを読み込めませんでした。',
    'detail.rating_save_failed' => '評価を保存できませんでした: ',
    'detail.play_notice' => '▶  このタイトルには再生可能なソースがありません。',
    'detail.actions_hint' => '▶  p  再生        Esc  戻る',
    'detail.hint' => '↑↓  あらすじをスクロール      p  再生      s  シャッフル      C  キャスト      r  評価      F  お気に入り      w  視聴済み      l  高評価      j  低評価      d  ダウンロード      Esc  戻る',
    'detail.container_hint' => '↑↓←→  移動      ⏎  開く      s  シャッフル      Esc  戻る',
    'detail.loading_hint' => 'Esc  戻る',
    'detail.loading' => '読み込み中…',
    'detail.no_synopsis' => 'あらすじはありません。',
    'detail.more_like_this' => '関連作品',
    'detail.similar_navigate_hint' => '←→ 移動  ⏎ 開く',
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

    // ---- FilterBar ----------------------------------------------------
    'filter.search_placeholder' => '(入力して絞り込み)',
    'filter.search_label' => '検索: ',
    'filter.sort_label' => '並び替え: ',
    'filter.order_label' => '順序: ',
    'filter.order_asc' => '昇順',
    'filter.order_desc' => '降順',

    // ---- SyncPlay errors ----------------------------------------------
    'syncplay.not_authenticated' => 'ウォッチパーティを使う前にサインインしてください。',
    'syncplay.not_in_group' => '現在グループに参加していません。',
    'syncplay.not_host' => 'この操作はグループのホストだけが行えます。',
    'syncplay.unknown_message' => 'サーバーが認識できないリクエストを拒否しました。',
    'syncplay.handler_error' => '同期サーバーで問題が発生しました。',
    'syncplay.protocol_version_mismatch' => 'このアプリはサーバーのバージョンと互換性がありません。',
    'syncplay.invalid_new_host' => '要求された新しいホストは有効ではありません。',
    'syncplay.member_not_found' => 'そのメンバーはグループ内に見つかりませんでした。',
    'syncplay.same_host' => 'そのメンバーはすでにグループのホストです。',
    'syncplay.create_failed' => 'ウォッチグループを作成できませんでした。',
    'syncplay.join_failed' => 'ウォッチグループに参加できませんでした。',
    'syncplay.leave_failed' => 'ウォッチグループから退出できませんでした。',
    'syncplay.unknown_error' => '同期エラーが発生しました。',
];
