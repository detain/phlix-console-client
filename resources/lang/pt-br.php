<?php

declare(strict_types=1);

/**
 * Brazilian Portuguese (pt-BR) translation catalog for the Phlix console client.
 *
 * This file is loaded lazily by SugarCraft\Core\I18n\T on first lookup.
 * Each key is dot-separated: '<group>.<name>' where <group> is a logical
 * screen or feature grouping.
 *
 * FILE NAME NOTE: the vendor loader (SugarCraft\Core\I18n\T::normalize) maps
 * the environment locale pt_BR.UTF-8 to the lowercase-hyphen form "pt-br",
 * so this catalog is pt-br.php — a file literally named pt_BR.php would
 * never be loaded. See src/I18n/Locale.php for the resolution table.
 *
 * Key set and placeholder tokens ({name}) must stay identical to en.php —
 * enforced by tests/I18n/LocaleCatalogsTest.php and scripts/check-i18n-catalogs.php.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

return [
    // ---- RecommendationsScreen ----------------------------------------
    'recommendations.title' => 'Para você',
    'recommendations.hint' => 'Q: Voltar  ↑↓: Navegar  Enter: Abrir  X: Dispensar',
    'recommendations.session_expired' => 'Sua sessão expirou. Entre novamente.',
    'recommendations.load_failed' => 'Não foi possível carregar as recomendações.',
    'recommendations.loading' => 'Carregando recomendações…',
    'recommendations.empty' => "Ainda não há recomendações.\n  Comece a assistir para receber sugestões personalizadas!",
    'recommendations.dismiss_failed' => 'Não foi possível dispensar a recomendação.',
    'recommendations.crumb' => 'Para você',

    // ---- DetailScreen -------------------------------------------------
    'detail.session_expired' => 'Sua sessão expirou. Entre novamente.',
    'detail.load_failed' => 'Não foi possível carregar este título.',
    'detail.similar_load_failed' => 'Não foi possível carregar títulos semelhantes.',
    'detail.missing_episodes_load_failed' => 'Não foi possível carregar os episódios faltantes.',
    'detail.children_load_failed' => 'Não foi possível carregar este conteúdo.',
    'detail.rating_save_failed' => 'Falha ao salvar a avaliação: ',
    'detail.play_notice' => '▶  Este título não tem uma fonte reproduzível.',
    'detail.actions_hint' => '▶  p  Reproduzir  Esc  Voltar',
    'detail.hint' => '↑↓  rolar sinopse      p  reproduzir      s  aleatório      C  elenco      r  avaliar      F  favorito      w  assistido      l  curtir      j  não curtir      d  baixar      Esc  voltar',
    'detail.container_hint' => '↑↓←→  mover      ⏎  abrir      s  aleatório      Esc  voltar',
    'detail.loading_hint' => 'Esc  voltar',
    'detail.loading' => 'Carregando…',
    'detail.no_synopsis' => 'Nenhuma sinopse disponível.',
    'detail.more_like_this' => 'Títulos semelhantes',
    'detail.similar_navigate_hint' => '←→ navegar  ⏎ abrir',
    'detail.cast_label' => 'Elenco',
    'detail.directed_by' => 'Direção de ',
    'detail.more_cast' => '  +{count} mais',
    'detail.season' => 'temporada',
    'detail.season_plural' => 'temporadas',
    'detail.episode' => 'episódio',
    'detail.episode_plural' => 'episódios',
    'detail.item' => 'item',
    'detail.item_plural' => 'itens',
    'detail.missing_episodes_one' => '⚠  {count} episódio faltante',
    'detail.missing_episodes_many' => '⚠  {count} episódios faltantes',
    'detail.loading_content' => 'Carregando…',

    // ---- FilterBar ----------------------------------------------------
    'filter.search_placeholder' => '(digite para filtrar)',
    'filter.search_label' => 'Buscar: ',
    'filter.sort_label' => 'Classificar: ',
    'filter.order_label' => 'Ordem: ',
    'filter.order_asc' => 'asc',
    'filter.order_desc' => 'desc',

    // ---- SyncPlay errors ----------------------------------------------
    'syncplay.not_authenticated' => 'Entre na sua conta antes de usar as sessões sincronizadas.',
    'syncplay.not_in_group' => 'Você não está em um grupo de exibição.',
    'syncplay.not_host' => 'Somente o anfitrião do grupo pode fazer isso.',
    'syncplay.unknown_message' => 'O servidor rejeitou uma solicitação não reconhecida.',
    'syncplay.handler_error' => 'Algo deu errado no servidor de sincronização.',
    'syncplay.protocol_version_mismatch' => 'Este aplicativo não é compatível com a versão do servidor.',
    'syncplay.invalid_new_host' => 'O novo anfitrião solicitado não é válido.',
    'syncplay.member_not_found' => 'Esse membro não foi encontrado no grupo.',
    'syncplay.same_host' => 'Esse membro já é o anfitrião do grupo.',
    'syncplay.create_failed' => 'Não foi possível criar o grupo de exibição.',
    'syncplay.join_failed' => 'Não foi possível entrar no grupo de exibição.',
    'syncplay.leave_failed' => 'Não foi possível sair do grupo de exibição.',
    'syncplay.unknown_error' => 'Ocorreu um erro de sincronização.',
];
