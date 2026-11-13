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
    // ---- Auth (session expiry + sign-in/register failures — every surface) --
    'auth.session_expired' => 'Sua sessão expirou. Entre novamente.',
    'auth.register_taken' => 'Esse nome de usuário já está em uso. Escolha outro.',
    'auth.register_pending' => 'Sua conta está pendente de aprovação. Você poderá entrar quando um administrador aprová-la.',
    'auth.register_disabled' => 'O cadastro está desativado no momento neste servidor.',
    'auth.register_weak_password' => 'Sua senha é muito fraca. Escolha uma mais forte.',
    'auth.register_failed' => 'Falha no cadastro. Tente novamente.',
    'auth.signin_failed' => 'Não foi possível entrar. Tente novamente.',
    'auth.logout_revoke_failed' => 'Não foi possível sair no servidor. Você saiu deste dispositivo.',

    // ---- Errors (shared failure surface — ExceptionSentences) ------
    'errors.network' => 'Não foi possível contatar o servidor. Verifique a URL e a sua conexão.',
    'errors.unexpected' => 'Algo deu errado. Consulte o log para mais detalhes.',

    // ---- RecommendationsScreen ----------------------------------------
    'recommendations.title' => 'Para você',
    'recommendations.hint' => 'q  voltar      ↑↓  navegar      ⏎  abrir      x  dispensar',
    'recommendations.error_hint' => 'r  tentar de novo      q  voltar',
    'recommendations.load_failed' => 'Não foi possível carregar as recomendações.',
    'recommendations.loading' => 'Carregando recomendações…',
    'recommendations.empty' => "Ainda não há recomendações.\n  Comece a assistir para receber sugestões personalizadas!",
    'recommendations.dismiss_failed' => 'Não foi possível dispensar a recomendação.',
    'recommendations.crumb' => 'Para você',

    // ---- DetailScreen -------------------------------------------------
    'detail.load_failed' => 'Não foi possível carregar este título.',
    'detail.similar_load_failed' => 'Não foi possível carregar títulos semelhantes.',
    'detail.missing_episodes_load_failed' => 'Não foi possível carregar os episódios faltantes.',
    'detail.children_load_failed' => 'Não foi possível carregar este conteúdo.',
    'detail.rating_save_failed' => 'Não foi possível salvar a avaliação: ',
    'detail.play_notice' => '▶  Este título não tem uma fonte reproduzível.',
    'detail.actions_hint' => '▶  p  Reproduzir      Esc  Voltar',
    'detail.hint' => '↑↓  rolar sinopse      p  reproduzir      s  aleatório      C  elenco      r  avaliar      F  favorito      w  assistido      l  curtir      j  não curtir      d  baixar      u  legendas      Esc  voltar',
    'detail.container_hint' => '↑↓←→  mover      ⏎  abrir      s  aleatório      Esc  voltar',
    'detail.error_hint' => 'r  tentar novamente      Esc  voltar',
    'detail.loading_hint' => 'Esc  voltar',
    'detail.loading' => 'Carregando…',
    'detail.no_synopsis' => 'Nenhuma sinopse disponível.',
    'detail.more_like_this' => 'Títulos semelhantes',
    'detail.similar_navigate_hint' => '←→  navegar      ⏎  abrir',
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

    // ---- Player -------------------------------------------------------
    'player.hint_main' => 'Space ⏯  ←→ ±10s  [ ] velocidade  m modo',
    'player.hint_captions_off' => '  c LE',
    'player.hint_captions_on' => '  c LE✓',
    'player.hint_quality' => '  v qualidade',
    'player.hint_next' => '  n próximo',
    'player.hint_sleep' => '  z ⏰ ',
    'player.hint_back' => '  q voltar',

    // ---- FilterBar ----------------------------------------------------
    'filter.search_placeholder' => '(digite para filtrar)',
    'filter.search_label' => 'Buscar: ',
    'filter.sort_label' => 'Classificar: ',
    'filter.sort_name' => 'nome',
    'filter.sort_year' => 'ano',
    'filter.sort_rating' => 'avaliação',
    'filter.sort_date_added' => 'data de adição',
    'filter.sort_runtime' => 'duração',
    'filter.sort_genre' => 'gênero',
    'filter.sort_artist' => 'artista',
    'filter.order_label' => 'Ordem: ',
    'filter.order_asc' => 'asc',
    'filter.order_desc' => 'desc',
    'filter.genre_label' => 'Gêneros: ',

    // ---- SearchScreen ----------------------------------------------
    'search.no_results_for' => 'Nenhum resultado para "{query}"',
    'search.results_one' => '{count} resultado',
    'search.results_many' => '{count} resultados',


    // ---- LibraryScreen -----------------------------------------------
    'library.no_items' => 'Nenhum item',
    'library.items_one' => '{count} item',
    'library.items_many' => '{count} itens',


    // ---- SyncPlay errors ----------------------------------------------
    'syncplay.not_authenticated' => 'Entre na sua conta antes de usar os grupos de exibição.',
    'syncplay.not_in_group' => 'Você não está em um grupo de exibição.',
    'syncplay.not_host' => 'Somente o anfitrião do grupo pode fazer isso.',
    'syncplay.unknown_message' => 'O servidor não reconheceu a solicitação.',
    'syncplay.handler_error' => 'Algo deu errado no servidor.',
    'syncplay.protocol_version_mismatch' => 'Este aplicativo não é compatível com a versão do servidor.',
    'syncplay.invalid_new_host' => 'O novo anfitrião solicitado não é válido.',
    'syncplay.member_not_found' => 'Esse membro não foi encontrado no grupo.',
    'syncplay.same_host' => 'Esse membro já é o anfitrião do grupo.',
    'syncplay.create_failed' => 'Não foi possível criar o grupo de exibição.',
    'syncplay.join_failed' => 'Não foi possível entrar no grupo de exibição.',
    'syncplay.leave_failed' => 'Não foi possível sair do grupo de exibição.',
    'syncplay.group_limit_reached' => 'O anfitrião atingiu o limite de grupos de exibição.',
    'syncplay.group_not_found' => 'Esse grupo de exibição não existe mais.',
    'syncplay.invalid_password' => 'A senha não está correta.',
    'syncplay.group_full' => 'Esse grupo de exibição está cheio.',
    'syncplay.queue_limit_exceeded' => 'A fila de reprodução desse grupo de exibição está cheia.',
    'syncplay.unknown_error' => 'Ocorreu um erro de sincronização.',

    // ---- SyncPlay modal (group noun = this catalog's glossary term) --
    'syncplay.modal_public_groups' => 'Grupos de exibição públicos:',
    'syncplay.modal_create_new' => '+ Criar um grupo de exibição',
    'syncplay.modal_create_title' => 'Criar grupo de exibição',
    'syncplay.modal_joining' => 'Entrando no grupo de exibição…',

    // ---- BrowseScreen (empty-library banner + hints) -----------------
    'browse.loading' => 'Carregando suas bibliotecas…',
    'browse.no_libraries' => 'Nenhuma biblioteca ainda.',
    'browse.no_libraries_admin' => 'Pressione : para abrir a paleta de comandos e adicione uma em {admin} → {libraries}.',
    'browse.no_libraries_member' => 'Peça a um administrador para adicionar uma.',
    'browse.hint_refresh_quit' => 'R  recarregar      q  sair',

    // ---- Command palette (App::staticActions) ---------------------------
    'palette.search'     => 'Buscar',
    'palette.home'       => 'Início',
    'palette.settings'   => 'Configurações',
    'palette.keys'       => 'Atalhos de teclado',
    'palette.stats'      => 'Estatísticas',
    'palette.watch_history'  => 'Histórico de reprodução',
    'palette.favorites'  => 'Favoritos',
    'palette.photos'     => 'Fotos',
    'palette.music_artists'  => 'Artistas musicais',
    'palette.playlists'  => 'Listas de reprodução',
    'palette.collections'  => 'Coleções',
    'palette.servers'    => 'Servidores',
    'palette.shared_with_me'  => 'Compartilhado comigo',
    'palette.invite_links'  => 'Links de convite',
    'palette.federation_shares'  => 'Compartilhamentos da federação',
    'palette.show_metrics'  => 'Mostrar métricas',
    'palette.hide_metrics'  => 'Ocultar métricas',
    'palette.admin'      => 'Administração',
    // ---- Admin menu sections (AdminMenuScreen::SECTIONS) ----------------
    'adminmenu.dashboard' => 'Painel',
    'adminmenu.users' => 'Usuários',
    'adminmenu.settings' => 'Configurações do servidor',
    'adminmenu.transcoding' => 'Transcodificação',
    'adminmenu.plugins' => 'Plugins',
    'adminmenu.plugin_updates' => 'Atualizações de plugins',
    'adminmenu.libraries' => 'Bibliotecas',
    'adminmenu.logs' => 'Logs',
    'adminmenu.backup' => 'Backup',
    'adminmenu.dlna' => 'DLNA',
    'adminmenu.remote' => 'Acesso remoto',
    'adminmenu.live_tv' => 'TV ao vivo',
    'adminmenu.parental' => 'Controle parental',
    'adminmenu.metrics' => 'Métricas',
    'adminmenu.watch_history' => 'Histórico de reprodução',
    'adminmenu.duplicates' => 'Duplicatas',
    'adminmenu.webhooks' => 'Webhooks',
    'adminmenu.auth_providers' => 'Provedores de autenticação',
    'adminmenu.restart' => 'Reinício do servidor',
    'adminmenu.filesystem' => 'Sistema de arquivos',
    'palette.resume_playback'  => 'Retomar reprodução',
    'palette.pause_playback'  => 'Pausar reprodução',
    'palette.stop_playback'  => 'Parar reprodução',
    'palette.sign_out'   => 'Sair da conta',
    'palette.quit'       => 'Sair',
    'palette.go_to'      => 'Ir para {name}',
    // CON-S17: auth/onboarding chrome + secondary surfaces
    'chrome.too_small_title' => 'Terminal muito pequeno.',
    'chrome.too_small_size' => 'Disponível {cols}×{rows} — o Phlix precisa de pelo menos {min_cols}×{min_rows}.',
    'chrome.too_small_resize' => 'Redimensione a janela para continuar.',
    'auth.signin_heading' => 'Entre no Phlix.',
    'auth.signin_title' => 'Entrar',
    'auth.signin_progress' => 'Entrando…',
    'auth.signin_footer' => 'Tab  próximo      Enter  entrar      s  cadastrar      Esc  sair',
    'auth.signup_heading' => 'Crie sua conta do Phlix.',
    'auth.signup_title' => 'Cadastro',
    'auth.signup_progress' => 'Criando conta…',
    'auth.signup_footer' => 'Tab  próximo      Enter  cadastrar      Esc  voltar para a entrada',
    'auth.insecure_warning' => '⚠ Este servidor usa uma conexão insegura (http): sua senha e os tokens de sessão trafegam sem proteção.',
    'server.heading' => 'Conecte-se ao seu servidor Phlix.',
    'server.title' => 'Configuração',
    'server.footer' => 'Enter  conectar      Esc  sair',
    'server.field_title' => 'URL do servidor Phlix',
    'server.insecure_typed' => '⚠ http:// envia sua senha e os tokens de sessão sem criptografia. Use https:// a menos que seja uma rede local confiável.',
    'server.invalid_url' => 'Informe uma URL de servidor válida (ex.: https://phlix.example.com).',
    'settings.heading' => 'Configurações',
    'settings.logged_in_as' => 'Conectado como {name}',
    'settings.server_line' => 'Servidor: {url}',
    'settings.footer' => 'L  sair      S  servidores      Enter  salvar      Esc  cancelar',
    'syncplay.modal_none' => '(nenhum disponível)',
    'syncplay.modal_more' => '… e mais {count}',
    'syncplay.modal_hint_list' => '↑↓ selecionar  Enter confirmar  Esc cancelar',
    'syncplay.modal_name_label' => 'Nome:',
    'syncplay.modal_public' => 'Público',
    'syncplay.modal_private' => 'Privado',
    'syncplay.modal_hint_create' => 'Enter criar  ← voltar  digite para editar o nome',
    'syncplay.modal_hint_cancel' => 'Esc cancelar',
    'syncplay.modal_error_title' => 'Erro',
    'syncplay.modal_error_default' => 'Não foi possível concluir a ação.',
    'syncplay.modal_press_key' => 'Pressione qualquer tecla para continuar',
    'quality.auto' => 'Auto (recomendado)',
];
