<?php

declare(strict_types=1);

namespace Drupal\tabs_icons_ui\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;

/**
 * Loads icons for the icon-picker dialog, from two separate sources.
 *
 * - getIcons(): the bundled assets/icon-library.json - a static reference
 *   list shipped with the module (currently the full Feather Icons set).
 *   Parsed once and cached in cache.default under a fixed cache ID, since
 *   the file only ever changes when the module itself is updated - which
 *   already triggers a full cache clear, so no explicit invalidation logic
 *   is needed here.
 * - getCustomIcons(): the tabs_icons_ui.icons config object, managed
 *   through IconsForm - a site's own additions on top of the bundled set,
 *   exportable/portable via ordinary config sync. Config objects are
 *   already cached by Drupal's ConfigFactory itself, so no separate
 *   caching is added here.
 *
 * Both return the same {name, label, svg} shape so the icon-picker JS can
 * treat them uniformly, even though they're shown as two separate
 * sections in the dialog.
 */
class IconLibrary {

  /**
   * @var string
   */
  protected const CACHE_ID = 'tabs_icons_ui:icon_library';

  /**
   * @var array<int, array{name: string, label: string, svg: string}>|null
   */
  protected ?array $libraryIcons = NULL;

  /**
   * Name-indexed maps built once per request by resolveIcon(), so repeated
   * lookups (e.g. one per tab on a page with several mapped routes) don't
   * re-scan the source lists - a plain PHP array lookup, no cache backend
   * round-trip per key.
   *
   * @var array<string, array{name: string, label: string, svg: string}>|null
   */
  protected ?array $indexedCustomIcons = NULL;

  /**
   * @var array<string, array{name: string, label: string, svg: string}>|null
   */
  protected ?array $indexedLibraryIcons = NULL;

  /**
   * Construction of IconLibrary.
   */
  public function __construct(
    protected ModuleExtensionList $moduleExtensionList,
    protected ConfigFactoryInterface $configFactory,
    protected CacheBackendInterface $cache,
  ) {}

  /**
   * Returns the bundled icon library.
   *
   * Reads from cache.default first; only reads and parses the ~120KB JSON
   * file from disk on a genuine cache miss (first request after a cache
   * clear or module update). Cached permanently - Cache::PERMANENT is
   * safe here because the file's content only changes on module update,
   * and any update workflow (drush cr / drush updb) clears cache.default
   * along with everything else, so no explicit invalidation is needed.
   *
   * @return array<int, array{name: string, label: string, svg: string}>
   */
  public function getIcons(): array {
    if ($this->libraryIcons !== NULL) {
      return $this->libraryIcons;
    }

    if ($cached = $this->cache->get(self::CACHE_ID)) {
      return $this->libraryIcons = $cached->data;
    }

    $path = $this->moduleExtensionList->getPath('tabs_icons_ui') . '/assets/icon-library.json';

    $icons = [];
    if (is_file($path)) {
      $contents = file_get_contents($path);
      $decoded = $contents !== FALSE ? json_decode($contents, TRUE) : NULL;
      $icons = is_array($decoded) ? $decoded : [];
    }

    $this->cache->set(self::CACHE_ID, $icons, Cache::PERMANENT);

    return $this->libraryIcons = $icons;
  }

  /**
   * Returns the site's own custom icon definitions from config.
   *
   * @return array<int, array{name: string, label: string, svg: string}>
   */
  public function getCustomIcons(): array {
    return (array) ($this->configFactory->get('tabs_icons_ui.icons')->get('icons') ?? []);
  }

  /**
   * Resolves an icon by its key - custom icons take precedence over the
   * bundled library, since a site's own definition is more likely to be
   * an intentional override than a naming accident (blocked at save time
   * in IconsForm anyway, but this keeps the same precedence either way).
   *
   * Returns NULL when the key exists in neither source - the caller
   * (TabIconRenderer) treats that as "no icon", skipping the tab entirely
   * rather than rendering anything broken.
   *
   * Builds a name-indexed map from each source once per request (not per
   * call) - see the $indexed* properties above.
   *
   * @return array{name: string, label: string, svg: string}|null
   */
  public function resolveIcon(string $name): ?array {
    $this->indexedCustomIcons ??= array_column($this->getCustomIcons(), NULL, 'name');
    $this->indexedLibraryIcons ??= array_column($this->getIcons(), NULL, 'name');

    return $this->indexedCustomIcons[$name] ?? $this->indexedLibraryIcons[$name] ?? NULL;
  }

}
