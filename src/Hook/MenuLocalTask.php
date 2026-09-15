<?php

declare(strict_types=1);

namespace Drupal\tabs_icons_ui\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\tabs_icons_ui\Service\TabIconRenderer;

/**
 * Hook implementations for tabs_icons_ui.
 */
class MenuLocalTask {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    protected TabIconRenderer $iconRenderer,
    protected RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Implements hook_preprocess_HOOK() for menu-local-task.html.twig.
   *
   * Theme-independent: delegates the actual replacement to a service instead
   * of relying on theme CSS, so it applies regardless of the active theme.
   */
  #[Hook('preprocess_menu_local_task')]
  public function preprocessMenuLocalTask(array &$variables): void {
    $this->iconRenderer->applyIcon($variables);
  }

  /**
   * Implements hook_preprocess_HOOK() for menu-local-tasks.html.twig.
   *
   * Direct equivalent of the original theme-side implementation, moved
   * into the module and driven by config instead of a hardcoded route
   * list: detaches the tab-collapsing JS (primary/secondary #attached)
   * whenever the current route's local tasks include an icon-mapped tab.
   *
   * Runs after the theme's own preprocess for this hook thanks to
   * themeRegistryAlter() below - otherwise Olivero's base-theme (as example)
   * preprocess_menu_local_tasks() (which is what attaches the JS in the
   * first place) would simply reattach it right after this unsets it,
   * since module hooks always run before theme hooks for the same
   * theme hook.
   */
  #[Hook('preprocess_menu_local_tasks')]
  public function preprocessMenuLocalTasks(array &$variables): void {
    $current_route = $this->routeMatch->getRouteName();
    if ($current_route === NULL || !$this->iconRenderer->currentRouteHasMappedTab($current_route)) {
      return;
    }

    unset($variables['primary']['#attached']);
    unset($variables['secondary']['#attached']);
  }

}
