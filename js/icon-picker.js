((Drupal, once, drupalSettings) => {
  /**
   * Builds one icon button for the grid, tagged with searchable text in a
   * data attribute so filtering later doesn't need to re-read icon.label
   * from a closure.
   *
   * @param {{name: string, label: string, svg: string}} icon
   * @param {string} currentValue - the icon key currently saved in the
   *   target field, so the matching button can be highlighted as selected.
   * @param {(icon: {name: string, label: string, svg: string}) => void} onPick
   * @return {HTMLButtonElement}
   */
  function buildIconButton(icon, currentValue, onPick) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'tabs-icons-ui__picker-item';
    if (icon.name === currentValue) {
      // Marks the icon that's already saved for this row, so re-opening
      // the dialog shows at a glance which one is currently in use
      // instead of making the user remember or guess.
      button.classList.add('tabs-icons-ui__picker-item--selected');
    }
    button.title = icon.label;
    // Used by the search filter below: lowercased once here so filtering
    // doesn't need to re-lowercase icon.label on every keystroke.
    button.dataset.searchText = `${icon.name} ${icon.label}`.toLowerCase();
    // innerHTML here is icon source data (bundled library JSON or the
    // site's own custom-icons config, sanitized server-side on save), not
    // anything coming from the settings form input itself: safe to
    // insert directly.
    button.innerHTML = `<span class="tabs-icons-ui__picker-icon">${icon.svg}</span>` +
      `<span class="tabs-icons-ui__picker-label">${icon.label}</span>`;

    button.addEventListener('click', () => onPick(icon));

    return button;
  }

  /**
   * Builds one labeled section (heading + grid) for a set of icons.
   * Returns null if the icon list is empty, so the caller can skip
   * rendering an empty section entirely (e.g. no custom icons defined).
   *
   * @param {string} heading
   * @param {Array<{name: string, label: string, svg: string}>} icons
   * @param {string} currentValue
   * @param {(icon: {name: string, label: string, svg: string}) => void} onPick
   * @return {{section: HTMLElement, buttons: HTMLButtonElement[]}|null}
   */
  function buildSection(heading, icons, currentValue, onPick) {
    if (icons.length === 0) {
      return null;
    }

    const section = document.createElement('div');
    section.className = 'tabs-icons-ui__picker-section';

    const title = document.createElement('h4');
    title.className = 'tabs-icons-ui__picker-section-heading';
    title.textContent = heading;

    const grid = document.createElement('div');
    grid.className = 'tabs-icons-ui__picker-grid';

    const buttons = icons.map((icon) => buildIconButton(icon, currentValue, onPick));
    buttons.forEach((button) => grid.appendChild(button));

    section.appendChild(title);
    section.appendChild(grid);

    return { section, buttons };
  }

  /**
   * Builds the full dialog content: a search input on top of two sections
   * (Custom icons, then the bundled Library), each with its own heading
   * and grid. Typing in the search box filters buttons across BOTH
   * sections at once via plain display toggling: nothing is rebuilt per
   * keystroke. A section is hidden entirely once none of its buttons
   * match the current query, so no empty heading is left dangling.
   *
   * The onPick callback is invoked (icon) => void on click: building the
   * content doesn't know or care about the dialog instance that will
   * wrap it; the caller decides what "picking" an icon actually does
   * (fill the hidden field, update the preview, close the dialog).
   *
   * @param {Array<{name: string, label: string, svg: string}>} customIcons
   * @param {Array<{name: string, label: string, svg: string}>} libraryIcons
   * @param {string} currentValue - the icon key currently saved in the
   *   target field, used to highlight the matching button.
   * @param {(icon: {name: string, label: string, svg: string}) => void} onPick
   * @return {HTMLElement}
   */
  function buildDialogContent(customIcons, libraryIcons, currentValue, onPick) {
    const wrapper = document.createElement('div');
    wrapper.className = 'tabs-icons-ui__picker-wrapper';

    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'tabs-icons-ui__picker-search';
    search.placeholder = Drupal.t('Search icons…');
    // Keep focus in the search field the moment the dialog opens, so the
    // user can start typing immediately without an extra click.
    search.autofocus = true;

    const scrollArea = document.createElement('div');
    scrollArea.className = 'tabs-icons-ui__picker-scroll-area';

    const sections = [
      buildSection(Drupal.t('Custom icons'), customIcons, currentValue, onPick),
      buildSection(Drupal.t('Library'), libraryIcons, currentValue, onPick),
    ].filter((entry) => entry !== null);

    search.addEventListener('input', () => {
      const query = search.value.trim().toLowerCase();
      sections.forEach(({ section, buttons }) => {
        let anyVisible = false;
        buttons.forEach((button) => {
          const matches = query === '' || button.dataset.searchText.includes(query);
          button.style.display = matches ? '' : 'none';
          anyVisible = anyVisible || matches;
        });
        // Hide the whole section (heading included) once nothing in it
        // matches, rather than leaving a heading with an empty grid.
        section.style.display = anyVisible ? '' : 'none';
      });
    });

    wrapper.appendChild(search);
    sections.forEach(({ section }) => scrollArea.appendChild(section));
    wrapper.appendChild(scrollArea);

    // Scroll the already-selected icon into view on open, so on a large
    // library the user doesn't have to hunt for what's already picked.
    const selected = scrollArea.querySelector('.tabs-icons-ui__picker-item--selected');
    if (selected) {
      // Deferred one tick: the dialog's own layout/sizing (set by
      // Drupal.dialog/jQuery UI right after this content is handed off)
      // hasn't necessarily settled yet, so scrolling immediately could
      // measure against a not-yet-final scroll container height.
      setTimeout(() => selected.scrollIntoView({ block: 'center' }), 0);
    }

    return wrapper;
  }

  Drupal.behaviors.tabsIconsUiPicker = {
    attach(context) {
      once('tabs-icons-ui-picker', '[data-tabs-icons-picker-trigger]', context).forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
          // The trigger is a plain <a href="#">, styled as a link rather
          // than a button, so its default navigation must be suppressed.
          event.preventDefault();

          const targetId = trigger.getAttribute('data-tabs-icons-picker-trigger');
          const targetField = document.getElementById(targetId);
          if (!targetField) {
            return;
          }

          const previewId = trigger.getAttribute('data-tabs-icons-picker-preview');
          const previewElement = previewId ? document.getElementById(previewId) : null;

          const settings = drupalSettings.tabsIconsUi || {};
          const customIcons = settings.customIcons || [];
          const libraryIcons = settings.iconLibrary || [];

          // The dialog instance is created first and captured in this
          // closure, so the click handler below can call dialogInstance.close()
          // directly instead of re-querying the DOM for it.
          const content = buildDialogContent(customIcons, libraryIcons, targetField.value, (icon) => {
            // The hidden field stores only the icon's KEY (its "name").
            // Not the SVG - since that's what actually gets saved. The
            // preview cell, on the other hand, needs to show the real
            // icon, so it's updated directly from the icon object here
            // rather than by re-deriving it from the field's own value.
            targetField.value = icon.name;
            targetField.dispatchEvent(new Event('change', { bubbles: true }));

            if (previewElement) {
              previewElement.innerHTML = icon.svg;
            }

            dialogInstance.close();
          });

          const dialogInstance = Drupal.dialog(content, {
            title: Drupal.t('Choose an icon'),
            width: '50%',
          });
          dialogInstance.showModal();
        });
      });
    },
  };
})(Drupal, once, drupalSettings);
