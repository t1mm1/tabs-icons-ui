<?php

declare(strict_types=1);

namespace Drupal\tabs_icons_ui\Service;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\tabs_icons_ui\Constants;

/**
 * Replaces a local task link's title with an icon render array, per config.
 */
class TabIconRenderer {

  /**
   * Construction of TabIconRenderer.
   */
  public function __construct(
    public ConfigFactoryInterface $configFactory,
    public IconLibrary $iconLibrary,
  ) {}

  /**
   * Applies the icon replacement for menu-local-task.html.twig, if a mapping exists.
   *
   * Called from preprocess_menu_local_task(); $variables is the twig
   * variables array as built by core's template_preprocess_menu_local_task().
   *
   * Mappings store only an icon KEY, not raw SVG - the actual markup is
   * resolved here via IconLibrary (custom icons first, then the bundled
   * library). If the key doesn't resolve to anything (the bundled set
   * changed, or the custom icon it pointed to was deleted), this skips
   * silently: the tab keeps its original text, nothing broken is ever
   * rendered to a site visitor.
   */
  public function applyIcon(array &$variables): void {
    $config = $this->configFactory->get('tabs_icons_ui.settings');
    $icons_config = $this->configFactory->get('tabs_icons_ui.icons');

    // Bubble both configs as cacheable dependencies: saving the route
    // mappings (Settings) OR the custom icon definitions (Icons) should
    // both invalidate any cached render of this tab.
    $cacheability = CacheableMetadata::createFromRenderArray($variables);
    $cacheability->addCacheableDependency($config);
    $cacheability->addCacheableDependency($icons_config);
    $cacheability->applyTo($variables);

    $url = $variables['element']['#link']['url'] ?? NULL;
    if (!$url instanceof Url || !$url->isRouted()) {
      return;
    }

    $mapping = $this->findMapping((array) ($config->get('mappings') ?? []), $url->getRouteName());
    if ($mapping === NULL) {
      return;
    }

    $icon_key = (string) ($mapping['icon'] ?? '');
    if ($icon_key === '') {
      return;
    }

    $icon = $this->iconLibrary->resolveIcon($icon_key);
    if ($icon === NULL) {
      // Broken reference - skip rather than render anything broken.
      return;
    }

    $original_text = $variables['element']['#link']['title'] ?? '';

    // Override the link's title with a render array - this is theme-independent
    // (works with any menu-local-task.html.twig, since #type => 'link' accepts
    // a renderable #title), unlike the previous CSS-mask approach.
    $variables['link']['#title'] = [
      '#theme' => 'tabs_icons_ui_tab_icon',
      '#icon' => Markup::create(Xss::filter((string) $icon['svg'], Constants::ALLOWED_SVG_TAGS)),
      '#text' => $original_text,
      '#hide_text' => !empty($mapping['hide_text']),
      // Attach the module's own sizing/layout CSS here, on the title render
      // array itself, so it's only loaded on pages where an icon actually
      // renders - and independent of whatever the active theme provides.
      '#attached' => [
        'library' => ['tabs_icons_ui/frontend'],
      ],
    ];

    // When the text is hidden, the tab is a bare icon with nothing visible
    // to explain it — the visually-hidden span already covers screen
    // readers, but a sighted mouse user gets no equivalent unless we add
    // a native title tooltip here. Skipped when text is shown, since the
    // tooltip would just repeat what's already visible.
    if (!empty($mapping['hide_text'])) {
      $variables['link']['#options']['attributes']['title'] = (string) $original_text;
    }
  }

  /**
   * Finds the mapping row matching a given route name.
   *
   * @return array{route: string, icon: string, hide_text: bool}|null
   */
  public function findMapping(array $mappings, string $route_name): ?array {
    foreach ($mappings as $mapping) {
      if (($mapping['route'] ?? NULL) === $route_name) {
        return $mapping;
      }
    }
    return NULL;
  }

  /**
   * Checks whether the current route itself is a mapped tab route.
   *
   * Used from hook_preprocess_menu_local_tasks() to decide whether to
   * detach the theme's tab-collapsing JS for this page. The page you're
   * currently on always IS the route of its own active tab (e.g. being on
   * the node edit form means the current route is literally
   * "entity.node.edit_form", which is exactly what gets stored as
   * "route" in a mapping row) - so this is a direct membership check,
   * nothing more.
   *
   * @param string $current_route
   *   The route name of the page currently being rendered.
   */
  public function currentRouteHasMappedTab(string $current_route): bool {
    $mappings = (array) ($this->configFactory->get('tabs_icons_ui.settings')->get('mappings') ?? []);
    return in_array($current_route, array_column($mappings, 'route'), TRUE);
  }

}
