((Drupal, once) => {
  Drupal.behaviors.tabsIconsUiPreview = {
    attach(context) {
      once('tabs-icons-ui-preview', '[data-tabs-icons-svg-input]', context).forEach((input) => {
        const row = input.closest('tr');
        const preview = row ? row.querySelector('[data-tabs-icons-svg-preview]') : null;
        if (!preview) {
          return;
        }

        // Client-side only: mirrors raw SVG into the preview cell.
        // The server still re-filters the value on submit (Xss::filter).
        // This is purely a visual aid, not a security boundary.
        const syncPreview = () => {
          preview.innerHTML = input.value;
        };

        // Sync immediately on attach - the textfield can already hold a
        // value at this point (loaded from saved config on first page
        // load, or retained by Drupal's Form API after an "Add more" /
        // "Remove" ajax rebuild), and the preview cell for that row
        // otherwise stays empty until something changes it, since the
        // listeners below only fire on an actual future interaction.
        syncPreview();

        // 'input' already covers both typing and pasting (Ctrl+V) in all
        // modern browsers - the browser updates the field's value first,
        // then fires 'input'. 'paste' is added only as a defensive
        // backstop; syncPreview() is cheap enough that firing twice on a
        // paste is harmless.
        input.addEventListener('input', syncPreview);
        input.addEventListener('paste', syncPreview);
      });
    },
  };
})(Drupal, once);
