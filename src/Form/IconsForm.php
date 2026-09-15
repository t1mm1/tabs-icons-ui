<?php

declare(strict_types=1);

namespace Drupal\tabs_icons_ui\Form;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\tabs_icons_ui\Constants;
use Drupal\tabs_icons_ui\Service\IconLibrary;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Manages custom icon definitions (tabs_icons_ui.icons config).
 *
 * These are additive to the bundled assets/icon-library.json set - shown
 * as a separate "Custom icons" section in the icon-picker dialog. Unlike
 * the JSON file (which ships with the module and is meant to stay as-is),
 * this config is meant to be portable between environments via ordinary
 * config sync, and editable entirely through this form.
 */
class IconsForm extends ConfigFormBase {

  use DependencySerializationTrait;

  /**
   * Key used to keep the icons set of rows in $form_state across ajax rebuilds.
   */
  protected const string ROWS_STORAGE_KEY = 'tabs_icons_ui_custom_icon_rows';

  /**
   * Constructs the icons form.
   *
   * @param ConfigFactoryInterface $config_factory
   *   The config factory, required by the parent ConfigFormBase.
   * @param TypedConfigManagerInterface $typed_config_manager
   *   Required by ConfigFormBase since Drupal core 10.2 - passed through to
   *   the parent even though this form doesn't use it directly.
   * @param IconLibrary $iconLibrary
   *   Service used to check custom icon names against the bundled library
   *   for uniqueness in validateForm().
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
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
      $container->get(IconLibrary::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'tabs_icons_ui_icons_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getEditableConfigNames(): array {
    return ['tabs_icons_ui.icons'];
  }

  /**
   * {@inheritdoc}
   *
   * Builds the custom-icons table: one row per name => label => svg entry,
   * with "Add more" / "Remove" ajax buttons, mirroring the same pattern as
   * SettingsForm's mapping table.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);

    $icons = $form_state->get(self::ROWS_STORAGE_KEY);
    if ($icons === NULL) {
      $icons = $this->config('tabs_icons_ui.icons')->get('icons') ?? [];
      if (empty($icons)) {
        // Start with one empty row so the table isn't blank on first visit.
        $icons = [['name' => '', 'label' => '', 'svg' => '']];
      }
      $form_state->set(self::ROWS_STORAGE_KEY, $icons);
    }

    $form['#tree'] = TRUE;
    $form['#attached']['library'][] = 'tabs_icons_ui/admin';

    $form['help'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t(
          'Define icons of your own here: they appear as a separate "Icons" section in the icon picker on the Settings tab, alongside the bundled library.'
        ) . '</p>',
    ];

    $form['icons_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'tabs-icons-ui-custom-icons-wrapper'],
    ];

    $form['icons_wrapper']['icons'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Machine name'),
        $this->t('Label'),
        $this->t('Icon SVG'),
        $this->t('Preview'),
        $this->t('Remove'),
      ],
    ];

    foreach ($icons as $delta => $row) {
      $form['icons_wrapper']['icons'][$delta]['label'] = [
        '#type' => 'textfield',
        '#size' => 20,
        '#default_value' => $row['label'] ?? '',
      ];

      $form['icons_wrapper']['icons'][$delta]['name'] = [
        '#type' => 'machine_name',
        '#default_value' => $row['name'] ?? '',
        '#title' => '',
        '#description' => '',
        '#machine_name' => [
          // No #source field to slugify from (label isn't a sibling
          // element FAPI can watch here inside a table row reliably).
          // The user types the machine name directly, same as the
          // route/icon table doesn't auto-derive anything either.
          'exists' => '\Drupal\tabs_icons_ui\Form\IconsForm::machineNameExists',
        ],
        '#required' => FALSE,
      ];

      $textfield_id = 'tabs-icons-ui-custom-svg-' . $delta;

      $form['icons_wrapper']['icons'][$delta]['svg'] = [
        '#type' => 'textfield',
        '#default_value' => $row['svg'] ?? '',
        '#attributes' => [
          // Read by icon-preview.js to live-update the preview cell on input.
          'data-tabs-icons-svg-input' => 'true',
          'id' => $textfield_id,
        ],
        '#maxlength' => 512,
      ];

      $preview_markup = ($row['svg'] ?? '') !== ''
        ? Xss::filter((string) $row['svg'], Constants::ALLOWED_SVG_TAGS)
        : '';

      $form['icons_wrapper']['icons'][$delta]['preview'] = [
        '#type' => 'markup',
        // Initial server-rendered preview; icon-preview.js overwrites it live on input.
        '#markup' => '<div class="tabs-icons-ui__preview" data-tabs-icons-svg-preview>' . $preview_markup . '</div>',
      ];

      $form['icons_wrapper']['icons'][$delta]['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove'),
        '#name' => 'remove_icon_' . $delta,
        '#submit' => ['::removeRow'],
        '#ajax' => [
          'callback' => '::ajaxRefreshRows',
          'wrapper' => 'tabs-icons-ui-custom-icons-wrapper',
        ],
        // Removing a row shouldn't fail validation on the other, unrelated rows.
        '#limit_validation_errors' => [],
      ];
    }

    $form['icons_wrapper']['add_row'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add more'),
      '#submit' => ['::addRow'],
      '#ajax' => [
        'callback' => '::ajaxRefreshRows',
        'wrapper' => 'tabs-icons-ui-custom-icons-wrapper',
      ],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * #machine_name 'exists' callback.
   *
   * machine_name only checks uniqueness syntactically; the actual
   * cross-row and cross-library uniqueness check happens in
   * validateForm() below, where the full picture (other rows, the
   * bundled library) is available. This callback always returns FALSE
   * (never blocks on its own) to avoid duplicating that check with a
   * weaker, row-unaware version.
   */
  public static function machineNameExists(): bool {
    return FALSE;
  }

  /**
   * Ajax callback: returns the rebuilt table wrapper.
   *
   * Used by both the "Add more" and "Remove" buttons to refresh the table
   * in place without a full page reload.
   */
  public function ajaxRefreshRows(array &$form, FormStateInterface $form_state): array {
    return $form['icons_wrapper'];
  }

  /**
   * Submit handler: appends an empty row and triggers a rebuild.
   *
   * Triggered by the "Add more" button. Only touches $form_state storage -
   * nothing is persisted to config until the main form is actually submitted.
   */
  public function addRow(array &$form, FormStateInterface $form_state): void {
    $icons = $form_state->get(self::ROWS_STORAGE_KEY);
    $icons[] = [
      'name' => '',
      'label' => '',
      'svg' => '',
    ];
    $form_state->set(self::ROWS_STORAGE_KEY, $icons);
    $form_state->setRebuild();
  }

  /**
   * Submit handler: removes the row whose button was clicked, triggers a rebuild.
   *
   * The row index is parsed out of the triggering button's #name
   * (format: "remove_icon_<delta>"), set on each row's remove button in
   * buildForm().
   */
  public function removeRow(array &$form, FormStateInterface $form_state): void {
    $triggering_element = $form_state->getTriggeringElement();
    $delta = (int) str_replace('remove_icon_', '', (string) $triggering_element['#name']);

    $icons = $form_state->get(self::ROWS_STORAGE_KEY);
    unset($icons[$delta]);
    $form_state->set(self::ROWS_STORAGE_KEY, array_values($icons));
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $library_names = array_column($this->iconLibrary->getIcons(), 'name');

    $seen_names = [];
    $rows = $form_state->getValue(['icons_wrapper', 'icons']) ?? [];
    if ($rows) {
      foreach ($rows as $delta => $row) {
        $name = $row['name'] ?? '';
        if ($name === '') {
          continue;
        }
        if (isset($seen_names[$name])) {
          $form_state->setError(
            $form['icons_wrapper']['icons'][$delta]['name'],
            $this->t('Machine name %name is used more than once.', ['%name' => $name])
          );
        }
        if (in_array($name, $library_names, TRUE)) {
          $form_state->setError(
            $form['icons_wrapper']['icons'][$delta]['name'],
            $this->t('Machine name %name is already used by the bundled icon library. Choose a different name.', ['%name' => $name])
          );
        }
        $seen_names[$name] = TRUE;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $icons = [];
    $rows = $form_state->getValue(['icons_wrapper', 'icons']) ?? [];

    if ($rows) {
      foreach ($rows as $row) {
        $name = $row['name'] ?? '';
        $svg = $row['svg'] ?? '';
        // Skip incomplete rows (no name or no icon) - avoids saving dead
        // rows left over from "Add more".
        if ($name === '' || trim((string) $svg) === '') {
          continue;
        }
        $icons[] = [
          'name' => $name,
          'label' => ($row['label'] ?? '') !== '' ? $row['label'] : $name,
          'svg' => $svg,
        ];
      }
    }

    $this->config('tabs_icons_ui.icons')
      ->set('icons', array_values($icons))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
