<?php

declare(strict_types=1);

/**
 * Spanish (es) translation catalog for the Phlix console client.
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
    'auth.session_expired' => 'Tu sesión expiró. Inicia sesión de nuevo.',
    'auth.register_taken' => 'Ese nombre de usuario ya está en uso. Elige uno distinto.',
    'auth.register_pending' => 'Tu cuenta está pendiente de aprobación. Podrás iniciar sesión cuando un administrador la apruebe.',
    'auth.register_disabled' => 'El registro está desactivado actualmente en este servidor.',
    'auth.register_weak_password' => 'Tu contraseña es demasiado débil. Elige una más segura.',
    'auth.register_failed' => 'No se pudo completar el registro. Inténtalo de nuevo.',
    'auth.signin_failed' => 'No se pudo iniciar sesión. Inténtalo de nuevo.',
    'auth.logout_revoke_failed' => 'No se pudo cerrar la sesión en el servidor. En este dispositivo ya estás desconectado.',

    // ---- Errors (shared failure surface — ExceptionSentences) ------
    'errors.network' => 'No se pudo contactar con el servidor. Comprueba la URL y tu conexión.',
    'errors.unexpected' => 'Algo ha ido mal. Consulta el registro para más detalles.',

    // ---- RecommendationsScreen ----------------------------------------
    'recommendations.title' => 'Para ti',
    'recommendations.hint' => 'q  atrás      ↑↓  navegar      ⏎  abrir      x  descartar',
    'recommendations.load_failed' => 'No se pudieron cargar las recomendaciones.',
    'recommendations.loading' => 'Cargando recomendaciones…',
    'recommendations.empty' => "Aún no hay recomendaciones.\n  ¡Empieza a ver para recibir sugerencias personalizadas!",
    'recommendations.dismiss_failed' => 'No se pudo descartar la recomendación.',
    'recommendations.crumb' => 'Para ti',

    // ---- DetailScreen -------------------------------------------------
    'detail.load_failed' => 'No se pudo cargar este título.',
    'detail.similar_load_failed' => 'No se pudieron cargar los títulos similares.',
    'detail.missing_episodes_load_failed' => 'No se pudieron cargar los episodios que faltan.',
    'detail.children_load_failed' => 'No se pudo cargar este contenido.',
    'detail.rating_save_failed' => 'No se pudo guardar la valoración: ',
    'detail.play_notice' => '▶  Este título no tiene una fuente reproducible.',
    'detail.actions_hint' => '▶  p  Reproducir      Esc  Atrás',
    'detail.hint' => '↑↓  mover sinopsis      p  reproducir      s  aleatorio      C  reparto      r  valorar      F  favorito      w  visto      l  me gusta      j  no me gusta      d  descargar      u  subtítulos      Esc  atrás',
    'detail.container_hint' => '↑↓←→  mover      ⏎  abrir      s  aleatorio      Esc  atrás',
    'detail.error_hint' => 'r  reintentar      Esc  atrás',
    'detail.loading_hint' => 'Esc  atrás',
    'detail.loading' => 'Cargando…',
    'detail.no_synopsis' => 'Sinopsis no disponible.',
    'detail.more_like_this' => 'Títulos similares',
    'detail.similar_navigate_hint' => '←→  navegar      ⏎  abrir',
    'detail.cast_label' => 'Reparto',
    'detail.directed_by' => 'Dirigido por ',
    'detail.more_cast' => '  +{count} más',
    'detail.season' => 'temporada',
    'detail.season_plural' => 'temporadas',
    'detail.episode' => 'episodio',
    'detail.episode_plural' => 'episodios',
    'detail.item' => 'elemento',
    'detail.item_plural' => 'elementos',
    'detail.missing_episodes_one' => '⚠  {count} episodio que falta',
    'detail.missing_episodes_many' => '⚠  {count} episodios que faltan',
    'detail.loading_content' => 'Cargando…',

    // ---- Player -------------------------------------------------------
    'player.hint_main' => 'Space ⏯  ←→ ±10s  [ ] velocidad  m modo',
    'player.hint_captions_off' => '  c ST',
    'player.hint_captions_on' => '  c ST✓',
    'player.hint_quality' => '  v calidad',
    'player.hint_next' => '  n siguiente',
    'player.hint_sleep' => '  z ⏰ ',
    'player.hint_back' => '  q atrás',

    // ---- FilterBar ----------------------------------------------------
    'filter.search_placeholder' => '(escribe para filtrar)',
    'filter.search_label' => 'Buscar: ',
    'filter.sort_label' => 'Ordenar: ',
    'filter.sort_name' => 'nombre',
    'filter.sort_year' => 'año',
    'filter.sort_rating' => 'valoración',
    'filter.sort_date_added' => 'fecha de adición',
    'filter.sort_runtime' => 'duración',
    'filter.sort_genre' => 'género',
    'filter.sort_artist' => 'artista',
    'filter.order_label' => 'Orden: ',
    'filter.order_asc' => 'asc',
    'filter.order_desc' => 'desc',
    'filter.genre_label' => 'Géneros: ',

    // ---- SearchScreen ----------------------------------------------
    'search.no_results_for' => 'Sin resultados para "{query}"',
    'search.results_one' => '{count} resultado',
    'search.results_many' => '{count} resultados',


    // ---- LibraryScreen -----------------------------------------------
    'library.no_items' => 'Sin elementos',
    'library.items_one' => '{count} elemento',
    'library.items_many' => '{count} elementos',


    // ---- SyncPlay errors ----------------------------------------------
    'syncplay.not_authenticated' => 'Inicia sesión antes de usar los grupos de visionado.',
    'syncplay.not_in_group' => 'No estás en un grupo de visionado.',
    'syncplay.not_host' => 'Solo el anfitrión del grupo puede hacer eso.',
    'syncplay.unknown_message' => 'El servidor no reconoció la solicitud.',
    'syncplay.handler_error' => 'Algo salió mal en el servidor.',
    'syncplay.protocol_version_mismatch' => 'Esta aplicación no es compatible con la versión del servidor.',
    'syncplay.invalid_new_host' => 'El nuevo anfitrión solicitado no es válido.',
    'syncplay.member_not_found' => 'Ese miembro no se encontró en el grupo.',
    'syncplay.same_host' => 'Ese miembro ya es el anfitrión del grupo.',
    'syncplay.create_failed' => 'No se pudo crear el grupo de visionado.',
    'syncplay.join_failed' => 'No se pudo unir al grupo de visionado.',
    'syncplay.leave_failed' => 'No se pudo salir del grupo de visionado.',
    'syncplay.group_limit_reached' => 'El anfitrión alcanzó el límite de grupos de visionado.',
    'syncplay.group_not_found' => 'Ese grupo de visionado ya no existe.',
    'syncplay.invalid_password' => 'La contraseña no es correcta.',
    'syncplay.group_full' => 'Ese grupo de visionado está lleno.',
    'syncplay.queue_limit_exceeded' => 'La cola de reproducción de ese grupo de visionado está llena.',
    'syncplay.unknown_error' => 'Ocurrió un error de sincronización.',
    'browse.loading' => 'Cargando tus bibliotecas…',
    'browse.no_libraries' => 'Aún no hay bibliotecas.',
    'browse.no_libraries_admin' => 'Pulse : para abrir la paleta de comandos y añada una desde Admin → Libraries.',
    'browse.no_libraries_member' => 'Pida a un administrador que añada una.',
    'browse.hint_refresh_quit' => 'R  recargar      q  salir',

    // ---- Command palette (App::staticActions) ---------------------------
    'palette.search'     => 'Buscar',
    'palette.home'       => 'Inicio',
    'palette.settings'   => 'Ajustes',
    'palette.stats'      => 'Estadísticas',
    'palette.watch_history'  => 'Historial de reproducción',
    'palette.favorites'  => 'Favoritos',
    'palette.photos'     => 'Fotos',
    'palette.music_artists'  => 'Artistas de música',
    'palette.playlists'  => 'Listas de reproducción',
    'palette.collections'  => 'Colecciones',
    'palette.servers'    => 'Servidores',
    'palette.shared_with_me'  => 'Compartido conmigo',
    'palette.invite_links'  => 'Enlaces de invitación',
    'palette.federation_shares'  => 'Compartidos de la federación',
    'palette.show_metrics'  => 'Mostrar métricas',
    'palette.hide_metrics'  => 'Ocultar métricas',
    'palette.admin'      => 'Administración',
    'palette.resume_playback'  => 'Reanudar reproducción',
    'palette.pause_playback'  => 'Pausar reproducción',
    'palette.stop_playback'  => 'Detener reproducción',
    'palette.sign_out'   => 'Cerrar sesión',
    'palette.quit'       => 'Salir',
    'palette.go_to'      => 'Ir a {name}',
];
