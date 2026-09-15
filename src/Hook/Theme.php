<?php

declare(strict_types=1);

namespace Drupal\tabs_icons_ui\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for tabs_icons_ui.
 */
class Theme {

  /**
   * Implements hook_theme().
   *
   * Registers the icon + label template used to replace a tab's title.
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'tabs_icons_ui_tab_icon' => [
        'variables' => [
          'icon' => NULL,
          'text' => NULL,
          'hide_text' => FALSE,
        ],
        'template' => 'tabs-icons-ui-tab-icon',
      ],
    ];
  }

  /**
   * Implements hook_theme_registry_alter().
   *
   * Physically relocates preprocessMenuLocalTasks() above to the end of
   * the preprocess chain for menu-local-tasks.html.twig, so it runs after
   * the active theme's own preprocess (and its base theme's, e.g.
   * Olivero) instead of before. Module hook_preprocess_HOOK()
   * implementations always run before theme ones - that ordering is
   * fixed and isn't affected by hook weights. So this reordering trick
   * is the only supported way to have module code run last for a given
   * theme hook.
   *
   * The theme registry is cached, so this only takes effect after a
   * cache rebuild (drush cr).
   */
  #[Hook('theme_registry_alter')]
  public function themeRegistryAlter(array &$theme_registry): void {
    if (empty($theme_registry['menu_local_tasks']['preprocess functions'])) {
      return;
    }

    $functions = &$theme_registry['menu_local_tasks']['preprocess functions'];

    // Preprocess entries can be plain function name strings (classic
    // procedural hooks) or [class, method] / "Class::method" callables
    // (the OOP #[Hook] system) - normalize both to a comparable string.
    $to_label = static function ($callable): string {
      if (is_string($callable)) {
        return $callable;
      }
      if (is_array($callable)) {
        return implode('::', array_map(
          static fn ($part) => is_object($part) ? get_class($part) : (string) $part,
          $callable
        ));
      }
      return '';
    };

    $ours = [];
    $rest = [];
    foreach ($functions as $function) {
      $label = $to_label($function);
      if (str_contains($label, 'tabs_icons_ui') || str_contains($label, 'Theme')) {
        $ours[] = $function;
      }
      else {
        $rest[] = $function;
      }
    }

    // Nothing of ours registered for this hook.
    if (empty($ours)) {
      return;
    }

    $functions = array_merge($rest, $ours);
  }

}
