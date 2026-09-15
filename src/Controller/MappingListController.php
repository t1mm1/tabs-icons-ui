<?php

declare(strict_types=1);

namespace Drupal\tabs_icons_ui\Controller;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\tabs_icons_ui\Constants;
use Drupal\tabs_icons_ui\Service\IconLibrary;
use Drupal\tabs_icons_ui\Service\RouteTabDiscovery;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Read-only "List" tab: shows every configured mapping, grouped by entity type.
 */
class MappingListController extends ControllerBase {

  /**
   * Constructs the controller.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactoryService,
    protected RouteTabDiscovery $routeTabDiscovery,
    protected IconLibrary $iconLibrary,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get(RouteTabDiscovery::class),
      $container->get(IconLibrary::class),
    );
  }

  /**
   * Builds the listing page: one heading + table per entity-type group.
   */
  public function build(): array {
    $mappings = (array) ($this->configFactoryService->get('tabs_icons_ui.settings')->get('mappings') ?? []);

    if (empty($mappings)) {
      return [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('No route mappings are configured yet.') . '</p>',
      ];
    }

    // Group rows by entity type (Node / User / ...), same grouping
    // RouteTabDiscovery uses for the Settings form's route select, so the
    // two stay visually consistent with each other.
    $grouped = [];
    foreach ($mappings as $mapping) {
      $route = (string) ($mapping['route'] ?? '');
      $task_label = $this->routeTabDiscovery->getTaskLabel($route);
      $group = $task_label['group'] ?? $this->t('Other')->render();
      $title = $task_label['title'] ?? $route;

      $grouped[$group][] = [
        'title' => $title,
        'route' => $route,
        'icon_key' => (string) ($mapping['icon'] ?? ''),
        'hide_text' => !empty($mapping['hide_text']),
      ];
    }
    ksort($grouped);

    $build = [
      '#attached' => ['library' => ['tabs_icons_ui/admin']],
    ];

    foreach ($grouped as $group => $rows) {
      $build[$group]['heading'] = [
        '#type' => 'html_tag',
        '#tag' => 'h3',
        '#value' => $group,
      ];

      $table_rows = [];
      foreach ($rows as $row) {
        $preview = $this->buildPreviewCell($row['icon_key']);

        $table_rows[] = [
          $row['title'],
          $row['route'],
          ['data' => $preview],
          $row['hide_text'] ? $this->t('Yes') : $this->t('No'),
        ];
      }

      $build[$group]['table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Tab'),
          $this->t('Route'),
          $this->t('Icon'),
          $this->t('Hide text'),
        ],
        '#rows' => $table_rows,
      ];
    }

    return $build;
  }

  /**
   * Builds the "Icon" cell content for one row: a resolved preview, or an
   * explicit "not found" notice when the saved key doesn't resolve.
   *
   * @return array
   *   A render array for the table cell's 'data'.
   */
  public function buildPreviewCell(string $icon_key): array {
    if ($icon_key === '') {
      return [
        '#type' => 'markup',
        '#markup' => '<span class="tabs-icons-ui__preview-warning">' . $this->t('No icon set') . '</span>',
      ];
    }

    $icon = $this->iconLibrary->resolveIcon($icon_key);
    if ($icon === NULL) {
      return [
        '#type' => 'markup',
        '#markup' => '<span class="tabs-icons-ui__preview-warning">' .
          $this->t('Icon not found: %key', ['%key' => $icon_key]) .
          '</span>',
      ];
    }

    // Xss::filter() already sanitized $icon['svg'] against our own
    // SVG-aware allow-list. Markup::create() must wrap the FULL resulting
    // string (div wrapper included): wrapping only the inner SVG and then
    // concatenating with plain strings via '.' silently converts the
    // whole thing back into an untrusted plain string (PHP calls
    // __toString() on the Markup object during concatenation), which the
    // render pipeline then re-sanitizes with Xss::filterAdmin().
    $safe_svg = Xss::filter($icon['svg'], Constants::ALLOWED_SVG_TAGS);

    return [
      '#type' => 'markup',
      '#markup' => Markup::create('<div class="tabs-icons-ui__preview">' . $safe_svg . '</div>'),
    ];
  }

}
