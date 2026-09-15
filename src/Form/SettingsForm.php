<?php

declare(strict_types=1);

namespace Drupal\tabs_icons_ui\Form;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\tabs_icons_ui\Constants;
use Drupal\tabs_icons_ui\Service\IconLibrary;
use Drupal\tabs_icons_ui\Service\RouteTabDiscovery;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form: route => icon mappings, config-exportable, no entity storage.
 *
 * Each mapping stores only an icon KEY (resolved at render time by
 * TabIconRenderer via IconLibrary), not raw SVG: this form never lets
 * you type SVG directly; the only way to set a row's icon is the
 * "Choose icon" picker dialog, which fills the hidden key field and
 * updates the preview cell from data already loaded into
 * drupalSettings. Raw SVG entry only happens on IconsForm, when defining
 * a new custom icon in the first place.
 */
class SettingsForm extends ConfigFormBase {

  use DependencySerializationTrait;

  /**
   * Key used to keep the current set of rows in $form_state across ajax rebuilds.
   */
  protected const string ROWS_STORAGE_KEY = 'tabs_icons_ui_rows';

  /**
   * {@inheritdoc}
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected RouteTabDiscovery $routeTabDiscovery,
    protected IconLibrary $iconLibrary,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('tabs_icons_ui.route_tab_discovery'),
      $container->get(IconLibrary::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'tabs_icons_ui_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['tabs_icons_ui.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);

    // Load existing mappings once; subsequent rebuilds (add/remove row) read
    // from form state instead, so unsaved row edits aren't lost mid-session.
    $mappings = $form_state->get(self::ROWS_STORAGE_KEY);
    if ($mappings === NULL) {
      $mappings = $this->config('tabs_icons_ui.settings')->get('mappings') ?? [];
      if (empty($mappings)) {
        // Start with one empty row so the table isn't blank on first visit.
        $mappings = [['route' => '', 'icon' => '', 'hide_text' => FALSE]];
      }
      $form_state->set(self::ROWS_STORAGE_KEY, $mappings);
    }

    $form['#tree'] = TRUE;
    $form['#attached']['library'][] = 'tabs_icons_ui/admin';
    $form['#attached']['library'][] = 'tabs_icons_ui/icon-picker';
    // Both icon sources are static per-request data, not anything tied to
    // a specific row - passed once for the whole form, and the picker JS
    // reuses them for whichever row's "Choose icon" link was clicked.
    $form['#attached']['drupalSettings']['tabsIconsUi']['iconLibrary'] = $this->iconLibrary->getIcons();
    $form['#attached']['drupalSettings']['tabsIconsUi']['customIcons'] = $this->iconLibrary->getCustomIcons();

    $form['icon_source_help'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t(
          'Use the "Choose icon" link to assign an icon from the bundled library or your own <a href=":icons_url">custom icons</a>. Raw SVG can only be entered when defining a custom icon, not here.',
          [':icons_url' => Url::fromRoute('tabs_icons_ui.icons_form')->toString()]
        ) . '</p>',
    ];

    $route_options = $this->routeTabDiscovery->getAvailableTabs();

    // "Routes" details wrapper: purely visual grouping, added around the
    // mappings table/container. Every reference below to this table's
    // location (ajax callback, validateForm, submitForm) has to include
    // this 'routes' level in its array path, or Drupal will look up a
    // key that no longer exists.
    $form['routes'] = [
      '#type' => 'details',
      '#title' => $this->t('Routes'),
      '#open' => TRUE,
    ];

    $form['routes']['mappings_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'tabs-icons-ui-mappings-wrapper'],
    ];

    $form['routes']['mappings_wrapper']['mappings'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Tab route'),
        $this->t('Icon'),
        $this->t('Preview'),
        $this->t('Hide text'),
        $this->t('Remove'),
      ],
    ];

    foreach ($mappings as $delta => $row) {
      $form['routes']['mappings_wrapper']['mappings'][$delta]['route'] = [
        '#type' => 'select',
        '#options' => $route_options,
        '#empty_option' => $this->t('- Select a route -'),
        '#default_value' => $row['route'] ?? '',
      ];

      $icon_key = (string) ($row['icon'] ?? '');

      // Explicit, predictable ids (rather than relying on Drupal's
      // auto-generated ones) so icon-picker.js can target exactly this
      // row's hidden field and preview cell via plain data attributes,
      // without needing to reverse-engineer Drupal's id-slugification
      // scheme or walk the DOM from the clicked link.
      $hidden_id = 'tabs-icons-ui-icon-' . $delta;
      $preview_id = 'tabs-icons-ui-preview-' . $delta;

      $form['routes']['mappings_wrapper']['mappings'][$delta]['icon'] = [
        // Wrapping container so the hidden key field and its "Choose icon"
        // link render as a single table cell (each top-level key under
        // $delta becomes its own <td> for a #type => table).
        '#type' => 'container',
        'value' => [
          '#type' => 'hidden',
          '#default_value' => $icon_key,
          '#attributes' => ['id' => $hidden_id],
        ],
        'picker_link' => [
          // A plain <a>, not a Drupal form element or #type => link (which
          // would route through the routing system) - it has no #name/
          // #value wired into $form_state, so it can never trigger form
          // submission or validation. Purely a JS hook: clicking it is
          // handled entirely by icon-picker.js via the data attributes.
          // href="#" keeps it keyboard-focusable and looking like a real
          // link; icon-picker.js calls preventDefault() on click so it
          // never actually navigates or jumps the page to the top.
          '#type' => 'html_tag',
          '#tag' => 'a',
          '#value' => $icon_key !== '' ? $this->t('Change') : $this->t('Select'),
          '#attributes' => [
            'href' => '#',
            'class' => ['tabs-icons-ui__picker-trigger'],
            'data-tabs-icons-picker-trigger' => $hidden_id,
            'data-tabs-icons-picker-preview' => $preview_id,
          ],
        ],
      ];

      // Server-rendered initial preview, resolved from whichever icon key
      // is currently saved - icon-picker.js overwrites this cell's markup
      // directly (by $preview_id) whenever a new icon is picked, without
      // needing a page reload.
      $preview_markup = '';
      if ($icon_key !== '') {
        $resolved = $this->iconLibrary->resolveIcon($icon_key);
        if ($resolved !== NULL) {
          $preview_markup = Xss::filter((string) $resolved['svg'], Constants::ALLOWED_SVG_TAGS);
        }
        else {
          // The saved key no longer resolves (bundled library changed, or
          // the custom icon it pointed to was deleted) - surface this
          // here rather than silently showing an empty preview, so it
          // gets noticed and fixed instead of quietly doing nothing on
          // the live site (where TabIconRenderer just skips the tab).
          $preview_markup = '<span class="tabs-icons-ui__preview-warning">' .
            $this->t('Icon not found: %key', ['%key' => $icon_key]) .
            '</span>';
        }
      }

      $form['routes']['mappings_wrapper']['mappings'][$delta]['preview'] = [
        '#type' => 'markup',
        '#markup' => Markup::create(
          '<div class="tabs-icons-ui__preview" id="' . $preview_id . '">' . $preview_markup . '</div>'
        ),
      ];

      $form['routes']['mappings_wrapper']['mappings'][$delta]['hide_text'] = [
        '#type' => 'checkbox',
        '#default_value' => $row['hide_text'] ?? FALSE,
      ];

      $form['routes']['mappings_wrapper']['mappings'][$delta]['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove'),
        '#name' => 'remove_' . $delta,
        '#submit' => ['::removeRow'],
        '#ajax' => [
          'callback' => '::ajaxRefreshRows',
          'wrapper' => 'tabs-icons-ui-mappings-wrapper',
        ],
        // Removing a row shouldn't fail validation on the other, unrelated rows.
        '#limit_validation_errors' => [],
      ];
    }

    $form['routes']['mappings_wrapper']['add_row'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add more'),
      '#submit' => ['::addRow'],
      '#ajax' => [
        'callback' => '::ajaxRefreshRows',
        'wrapper' => 'tabs-icons-ui-mappings-wrapper',
      ],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * Ajax callback: returns the rebuilt table wrapper.
   */
  public function ajaxRefreshRows(array &$form, FormStateInterface $form_state): array {
    return $form['routes']['mappings_wrapper'];
  }

  /**
   * Submit handler: appends an empty row and triggers a rebuild.
   */
  public function addRow(array &$form, FormStateInterface $form_state): void {
    $mappings = $form_state->get(self::ROWS_STORAGE_KEY);
    $mappings[] = ['route' => '', 'icon' => '', 'hide_text' => FALSE];
    $form_state->set(self::ROWS_STORAGE_KEY, $mappings);
    $form_state->setRebuild();
  }

  /**
   * Submit handler: removes the row whose button was clicked, triggers a rebuild.
   */
  public function removeRow(array &$form, FormStateInterface $form_state): void {
    $triggering_element = $form_state->getTriggeringElement();
    $delta = (int) str_replace('remove_', '', (string) $triggering_element['#name']);

    $mappings = $form_state->get(self::ROWS_STORAGE_KEY);
    unset($mappings[$delta]);
    $form_state->set(self::ROWS_STORAGE_KEY, array_values($mappings));
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Reject duplicate routes - only one icon mapping per route makes sense.
    $seen_routes = [];
    $rows = $form_state->getValue(['routes', 'mappings_wrapper', 'mappings']) ?? [];
    foreach ($rows as $delta => $row) {
      $route = $row['route'] ?? '';
      if ($route === '') {
        continue;
      }
      if (isset($seen_routes[$route])) {
        $form_state->setError(
          $form['routes']['mappings_wrapper']['mappings'][$delta]['route'],
          $this->t('Route %route is mapped more than once.', ['%route' => $route])
        );
      }
      $seen_routes[$route] = TRUE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $mappings = [];
    $rows = $form_state->getValue(['routes', 'mappings_wrapper', 'mappings']) ?? [];

    foreach ($rows as $row) {
      // icon is a container wrapping the actual hidden field (added so the
      // "Choose icon" link renders inside the same table cell) - its value
      // lives one level deeper than the other fields, at icon.value.
      $icon_key = $row['icon']['value'] ?? '';

      // Skip incomplete rows (no route selected, or no icon chosen) -
      // avoids saving dead/empty rows left over from "Add more".
      if (($row['route'] ?? '') === '' || trim((string) $icon_key) === '') {
        continue;
      }
      $mappings[] = [
        'route' => $row['route'],
        'icon' => $icon_key,
        'hide_text' => (bool) $row['hide_text'],
      ];
    }

    $this->config('tabs_icons_ui.settings')
      ->set('mappings', array_values($mappings))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
