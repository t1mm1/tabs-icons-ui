<?php

declare(strict_types=1);

namespace Drupal\tabs_icons_ui;

/**
 * Shared constants used across tabs_icons_ui.
 */
class Constants {

  /**
   * Minimal SVG tag set needed for stroke-based icon sets (Feather and similar).
   * Kept deliberately small - extend only if you actually need a tag not listed.
   */
  public const array ALLOWED_SVG_TAGS = [
    'svg', 'path', 'circle', 'line', 'polyline', 'polygon', 'rect', 'ellipse', 'g', 'defs', 'use',
  ];

  /**
   * Key used to keep the icons set of rows in $form_state across ajax rebuilds.
   */
  public const string ROWS_STORAGE_KEY_ICONS = 'tabs_icons_ui_custom_icon_rows';

}
