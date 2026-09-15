# Tabs Icons UI

A Drupal module that replaces entity local task tabs (View, Edit, Delete, Revisions, etc.) with icons instead of text - configurable per route through an admin UI, with no theme customization required.

## Description

By default, Drupal renders entity local tasks (the "View / Edit / Delete" tabs on a node, user, or any other content entity) as plain text links. This module lets you map any of those tabs to an icon instead - chosen from a bundled icon library or your own custom set - without writing theme-level CSS or preprocess code.

Icon assignment works through an icon-picker dialog with search, and every mapping is stored in configuration, so it's exportable and portable between environments like any other Drupal config.

## Features

- **Icon-only tabs**: Replace any entity local task's text with an SVG icon, theme-independent
- **Automatic entity discovery**: Works for any content entity type with a canonical page (Node, User, Taxonomy term, Media, custom entities) - no hardcoded entity list
- **Bundled icon library**: Ships with a full set of ready-made icons out of the box
- **Custom icons**: Define your own icons (raw SVG) in a dedicated admin form, additive to the bundled set
- **Icon picker with search**: Visual picker dialog with live search across both custom and bundled icons, highlighting the currently selected one
- **Hide text option**: Optionally hide the tab's text label entirely, keeping only the icon (with a hover tooltip and screen-reader text preserved for accessibility)
- **Config-driven, no code required**: All mappings and custom icons live in configuration - no theme overrides, no custom preprocess functions needed
- **List overview**: A read-only summary page showing every configured mapping, grouped by entity type
- **Graceful degradation**: If an icon reference ever becomes invalid, the affected tab silently falls back to its original text instead of rendering anything broken

## Requirements

- Drupal core: `^10.3 || ^11`
- No contributed module dependencies

## Installation

1. Download and place the module in your `modules/contrib` (or `modules/custom`, if installed manually) directory
2. Enable the module via Drush: `drush en tabs_icons_ui`
   Or via the UI: Admin → Extend
3. Grant the **Administer tabs icons UI** permission to the appropriate roles at Admin → People → Permissions

## Configuration

Navigate to **Admin → Configuration → User interface → Tabs Icons UI** (`/admin/config/user-interface/tabs-icons-ui`). From there:

- **List**: A read-only overview of every currently configured mapping, grouped by entity type, useful for reviewing the current setup at a glance.
- **Settings**: Add route → icon mappings. Select a route (grouped by entity type), choose an icon via the "Choose icon" picker dialog, and optionally enable "Hide text" to show the icon only.
- **Icons**: Define your own custom icons (machine name, label, and raw SVG). These appear as a separate "Custom icons" section in the picker dialog, alongside the bundled library.

## Theming

The module is theme-independent by design -- icons render via a dedicated Twig template and ship their own CSS, so no theme overrides are required. If you do want to customize the appearance, the module provides: tabs-icons-ui-tab-icon.html.twig


Copy this template to your custom theme to override the icon/label markup.

### CSS Classes

The front-end tab markup uses the following CSS classes:

- `.tabs-icons-ui__icon` - Icon wrapper, sizes and displays the SVG
- `.tabs-icons-ui__label` - Visible text label (omitted from display when "Hide text" is enabled)

The admin forms and icon-picker dialog use:

- `.tabs-icons-ui__preview` - Icon preview cell in the settings tables and List overview
- `.tabs-icons-ui__preview-warning` - Shown when a saved icon key can no longer be resolved
- `.tabs-icons-ui__picker-trigger` - The "Choose icon" link under each route/icon field
- `.tabs-icons-ui__picker-grid` - The icon grid inside the picker dialog
- `.tabs-icons-ui__picker-item` - An individual icon button in the picker
- `.tabs-icons-ui__picker-item--selected` - Applied to the icon currently assigned to that row

## Usage

Once a route is mapped to an icon, the change applies automatically wherever that local task tab appears - no further action needed. Visiting the corresponding entity page (e.g. a node's edit form) will show the icon in place of the tab's text.

## License

This project is licensed under the GPL-2.0-or-later.

The bundled icon set (`assets/icon-library.json`) is derived from [Feather Icons](https://feathericons.com/), licensed separately under the MIT License.
See `assets/icon-library.LICENSE.txt` for the full license text and copyright notice.
It does not affect the module's own GPL license.

## Author

Pavel Kasianov.

Linkedin: https://www.linkedin.com/in/pkasianov</br>
Drupal.org: https://www.drupal.org/u/pkasianov

## Support

For issues or feature requests, please contact the module maintainer.
