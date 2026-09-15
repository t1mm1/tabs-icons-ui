<?php

declare(strict_types=1);

namespace Drupal\tabs_icons_ui\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Menu\LocalTaskManagerInterface;

/**
 * Discovers local task routes eligible for icon replacement.
 *
 * Scope: any local task belonging to a content entity type that has a
 * canonical ("view") page: Node, User, Taxonomy term, Media, and any
 * custom content entity on the site - discovered automatically via
 * EntityTypeManager, rather than a hardcoded list of entity types. This
 * deliberately excludes non-entity admin/config-form local tasks (Views
 * UI, Field UI, block/menu/taxonomy-vocabulary config forms, etc.), which
 * would otherwise flood the route picker with hundreds of irrelevant
 * options unrelated to "icon instead of text on an entity's own tabs".
 */
class RouteTabDiscovery {

  /**
   * @var array<string, string>|null
   *   Map of base_route => human-readable group label, e.g.
   *   ['entity.node.canonical' => 'Content', 'entity.user.canonical' => 'User'].
   */
  public ?array $groupsByBaseRoute = NULL;

  /**
   * Construction of RouteTabDiscovery.
   */
  public function __construct(
    protected LocalTaskManagerInterface $localTaskManager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns local task routes grouped by entity type.
   *
   * Shape matches what a #type => 'select' expects for optgroups:
   * ['Content' => ['entity.node.canonical' => 'View (entity.node.canonical)'], ...].
   *
   * @return array<string, array<string, string>>
   */
  public function getAvailableTabs(): array {
    $options = [];

    foreach ($this->localTaskManager->getDefinitions() as $definition) {
      $base_route = (string) ($definition['base_route'] ?? '');
      $group = $this->lookupGroup($base_route);
      if ($group === NULL) {
        continue;
      }

      $route_name = (string) $definition['route_name'];
      $title = (string) ($definition['title'] ?? $route_name);
      $options[$group][$route_name] = sprintf('%s (%s)', $title, $route_name);
    }

    foreach ($options as &$group_options) {
      asort($group_options);
    }

    return $options;
  }

  /**
   * Reverse lookup: given a route name, returns its group + tab title.
   *
   * Used by MappingListController to display a human-readable label next
   * to each configured mapping, instead of just the raw route name.
   *
   * @return array{group: string, title: string}|null
   */
  public function getTaskLabel(string $route_name): ?array {
    foreach ($this->localTaskManager->getDefinitions() as $definition) {
      if (($definition['route_name'] ?? NULL) !== $route_name) {
        continue;
      }
      $group = $this->lookupGroup((string) ($definition['base_route'] ?? ''));
      if ($group === NULL) {
        continue;
      }
      return [
        'group' => $group,
        'title' => (string) ($definition['title'] ?? $route_name),
      ];
    }
    return NULL;
  }

  /**
   * Looks up the group label for a given base_route, building the map on
   * first use and caching it for the rest of the request.
   */
  public function lookupGroup(string $base_route): ?string {
    return $this->getGroupsByBaseRoute()[$base_route] ?? NULL;
  }

  /**
   * Builds the base_route => group label map from all content entity types.
   *
   * Only entity types that are content entities (not config entities like
   * views, blocks, menus - those aren't what this module is for) AND
   * declare a 'canonical' link template (meaning they actually have a
   * standalone "view" page with tabs) are included. The base_route itself
   * is derived by the standard Drupal naming convention
   * "entity.<entity_type_id>.canonical", which is what
   * \Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider names it for any
   * entity type using the default route provider - true for the vast
   * majority of content entities, core or contrib.
   *
   * @return array<string, string>
   */
  public function getGroupsByBaseRoute(): array {
    if ($this->groupsByBaseRoute !== NULL) {
      return $this->groupsByBaseRoute;
    }

    $map = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if (!$entity_type->entityClassImplements(ContentEntityInterface::class)) {
        continue;
      }
      if (!$entity_type->hasLinkTemplate('canonical')) {
        continue;
      }

      $base_route = 'entity.' . $entity_type_id . '.canonical';
      $map[$base_route] = (string) $entity_type->getLabel();
    }

    return $this->groupsByBaseRoute = $map;
  }

}
