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
    // ---- RecommendationsScreen ----------------------------------------
    'recommendations.title' => 'Para ti',
    'recommendations.hint' => 'Q: Atrás  ↑↓: Navegar  Intro: Abrir  X: Descartar',
    'recommendations.session_expired' => 'Tu sesión expiró. Inicia sesión de nuevo.',
    'recommendations.load_failed' => 'No se pudieron cargar las recomendaciones.',
    'recommendations.loading' => 'Cargando recomendaciones…',
    'recommendations.empty' => "Aún no hay recomendaciones.\n  ¡Empieza a ver para recibir sugerencias personalizadas!",
    'recommendations.dismiss_failed' => 'No se pudo descartar la recomendación.',
    'recommendations.crumb' => 'Para ti',

    // ---- DetailScreen -------------------------------------------------
    'detail.session_expired' => 'Tu sesión expiró. Inicia sesión de nuevo.',
    'detail.load_failed' => 'No se pudo cargar este título.',
    'detail.similar_load_failed' => 'No se pudieron cargar los títulos similares.',
    'detail.missing_episodes_load_failed' => 'No se pudieron cargar los episodios que faltan.',
    'detail.children_load_failed' => 'No se pudo cargar este contenido.',
    'detail.rating_save_failed' => 'Error al guardar la valoración: ',
    'detail.play_notice' => '▶  Este título no tiene una fuente reproducible.',
    'detail.actions_hint' => '▶  p  Reproducir  Esc  Atrás',
    'detail.hint' => '↑↓  mover sinopsis      p  reproducir      s  aleatorio      C  reparto      r  valorar      F  favorito      w  visto      l  me gusta      j  no me gusta      d  descargar      Esc  atrás',
    'detail.container_hint' => '↑↓←→  mover      ⏎  abrir      s  aleatorio      Esc  atrás',
    'detail.loading_hint' => 'Esc  atrás',
    'detail.loading' => 'Cargando…',
    'detail.no_synopsis' => 'Sinopsis no disponible.',
    'detail.more_like_this' => 'Títulos similares',
    'detail.similar_navigate_hint' => '←→ navegar  ⏎ abrir',
    'detail.cast_label' => 'Reparto',
    'detail.directed_by' => 'Dirigido por ',
    'detail.more_cast' => '  +{count} más',
    'detail.season' => 'temporada',
    'detail.season_plural' => 'temporadas',
    'detail.episode' => 'episodio',
    'detail.episode_plural' => 'episodios',
    'detail.item' => 'elemento',
    'detail.item_plural' => 'elementos',
    'detail.missing_episodes_one' => '⚠  {count} episodio sin ver',
    'detail.missing_episodes_many' => '⚠  {count} episodios sin ver',
    'detail.loading_content' => 'Cargando…',

    // ---- FilterBar ----------------------------------------------------
    'filter.search_placeholder' => '(escribe para filtrar)',
    'filter.search_label' => 'Buscar: ',
    'filter.sort_label' => 'Ordenar: ',
    'filter.order_label' => 'Orden: ',
    'filter.order_asc' => 'asc',
    'filter.order_desc' => 'desc',

    // ---- SyncPlay errors ----------------------------------------------
    'syncplay.not_authenticated' => 'Inicia sesión antes de usar las salas de visionado.',
    'syncplay.not_in_group' => 'No estás en un grupo de visionado.',
    'syncplay.not_host' => 'Solo el anfitrión del grupo puede hacer eso.',
    'syncplay.unknown_message' => 'El servidor rechazó una solicitud no reconocida.',
    'syncplay.handler_error' => 'Algo salió mal en el servidor de sincronización.',
    'syncplay.protocol_version_mismatch' => 'Esta aplicación no es compatible con la versión del servidor.',
    'syncplay.invalid_new_host' => 'El nuevo anfitrión solicitado no es válido.',
    'syncplay.member_not_found' => 'Ese miembro no se encontró en el grupo.',
    'syncplay.same_host' => 'Ese miembro ya es el anfitrión del grupo.',
    'syncplay.create_failed' => 'No se pudo crear el grupo de visionado.',
    'syncplay.join_failed' => 'No se pudo unir al grupo de visionado.',
    'syncplay.leave_failed' => 'No se pudo salir del grupo de visionado.',
    'syncplay.group_limit_reached' => 'El anfitrión alcanzó el límite de salas de visionado.',
    'syncplay.group_not_found' => 'Ese grupo de visionado ya no existe.',
    'syncplay.invalid_password' => 'La contraseña no es correcta.',
    'syncplay.group_full' => 'Ese grupo de visionado está lleno.',
    'syncplay.unknown_error' => 'Ocurrió un error de sincronización.',
];
