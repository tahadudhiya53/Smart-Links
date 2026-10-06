/**
 * The Smart Links field's link editor.
 *
 * Links are posted in the order they appear, so reordering here is reordering the value. A new
 * link is made from the field's link template, with the placeholder key replaced by a new one.
 *
 * Copying, duplicating and pasting go through the server: a copy is the link's data as the link
 * rules read it, and a paste is checked against this field and rendered there, so a link is
 * pasted exactly as copied or not at all. The clipboard is only ever text sent back to the
 * server; nothing in it is read, run or inserted here.
 *
 * Every new link's key is taken from one counter at the moment the operation that needs it
 * starts, so no two links can share a key however operations overlap or whichever finishes
 * first. One request to the server runs at a time; while it does, the editor is busy and its
 * copying, duplicating and pasting wait, and what comes back is applied only if it still fits.
 */
(function ($) {
  'use strict';

  const CLIPBOARD_KEY = 'Craft-SmartLinks.clipboard';

  // A request that has not been answered by then has failed, so the editor is never left busy.
  const REQUEST_TIMEOUT = 30000;

  Craft.SmartLinks = Craft.SmartLinks || {};

  Craft.SmartLinks.Field = Garnish.Base.extend({
    $container: null,
    $links: null,
    $add: null,
    $paste: null,
    settings: null,
    dragSort: null,
    newKeys: 0,
    busy: false,

    init: function (container, settings) {
      this.$container = $(container);

      if (!this.$container.length || this.$container.data('smartLinksField')) {
        return;
      }

      this.$container.data('smartLinksField', this);
      this.settings = settings;
      this.$links = this.$container.children('[data-smartlinks-links]');
      this.$add = this.$container.find('> .smartlinks-field__buttons > [data-smartlinks-add]');
      this.$paste = this.$container.find('> .smartlinks-field__buttons > [data-smartlinks-paste]');
      this.$copyAll = this.$container.find('> .smartlinks-field__buttons > [data-smartlinks-copy-all]');

      if (this.settings.multiple) {
        this.dragSort = new Garnish.DragSort({
          handle: '.smartlinks-link__drag',
          axis: Garnish.Y_AXIS,
          collapseDraggees: true,
          magnetStrength: 4,
          helperLagBase: 1.5,
          helperOpacity: 0.9,
          onSortChange: () => this.update(),
        });
      } else {
        this.$container.addClass('smartlinks-field--single');
      }

      this.links().each((index, link) => this.initLink($(link)));

      this.addListener(this.$add, 'click', () => this.addLink());
      this.addListener(this.$paste, 'click', () => this.paste(this.$paste));
      this.addListener(this.$copyAll, 'click', () => this.copy(this.links(), this.$copyAll));
      this.addListener(this.$container.find('> [data-smartlinks-stored] > [data-smartlinks-clear]'), 'click', 'clearStored');
      this.addListener(Garnish.$win, 'storage', () => this.update());

      this.update();
    },

    links: function () {
      return this.$links.children('[data-smartlinks-link]');
    },

    initLink: function ($link) {
      if (this.dragSort) {
        this.dragSort.addItems($link);
      }

      const $toggle = $link.find('> .smartlinks-link__header > [data-smartlinks-toggle]');
      this.addListener($toggle, 'click', () => this.setExpanded($link, $toggle.attr('aria-expanded') !== 'true'));

      // The actions menu is moved out of the link when it opens, so it is found by its ID.
      const menuId = $link.find('> .smartlinks-link__header [data-disclosure-trigger]').attr('aria-controls');
      const $menu = menuId ? $(document.getElementById(menuId)) : $();
      // Garnish's `activate`, as Craft's own menus use: the disclosure menu takes Enter and Space
      // on its items, so a keyboard never produces the click a mouse does.
      this.addListener($menu.find('[data-smartlinks-action]'), 'activate', (event) => {
        const action = $(event.currentTarget).data('smartlinksAction');
        this.closeMenu($link);
        this.runAction($link, action);
      });

      const $type = $link.find('[data-smartlinks-type]').first();
      this.addListener($type, 'change', () => this.showType($link));

      this.addListener($link.find('[data-smartlinks-label]'), 'input', () => this.updateTitle($link));

      this.addListener($link.find('[data-smartlinks-download]'), 'change', (event) => {
        const $filename = $link.find('[data-smartlinks-filename]');
        $filename.toggleClass('hidden', !event.currentTarget.checked);
        $filename.find('input').prop('disabled', !event.currentTarget.checked);
      });

      this.addListener($link.find('[data-smartlinks-add-row]'), 'click', () => this.addRow($link));
      this.addListener($link.find('[data-smartlinks-custom-rows]'), 'click', (event) => {
        const $remove = $(event.target).closest('[data-smartlinks-remove-row]');

        if ($remove.length) {
          $remove.closest('[data-smartlinks-custom-row-item]').remove();
        }
      });
    },

    runAction: function ($link, action) {
      switch (action) {
        case 'move-up':
          return this.move($link, -1);
        case 'move-down':
          return this.move($link, 1);
        case 'duplicate':
          return this.duplicate($link);
        case 'copy':
          return this.copy($link, this.menuTrigger($link));
        case 'remove':
          return this.remove($link);
      }
    },

    menuTrigger: function ($link) {
      return $link.find('> .smartlinks-link__header [data-disclosure-trigger]');
    },

    closeMenu: function ($link) {
      const disclosure = this.menuTrigger($link).data('disclosureMenu');

      if (disclosure) {
        disclosure.hide();
      }
    },

    setExpanded: function ($link, expanded) {
      const $toggle = $link.find('> .smartlinks-link__header > [data-smartlinks-toggle]');
      $toggle.attr('aria-expanded', expanded ? 'true' : 'false');
      $link.children('.smartlinks-link__body').toggleClass('hidden', !expanded);
      $link.toggleClass('smartlinks-link--collapsed', !expanded);
    },

    updateTitle: function ($link) {
      const label = $link.find('[data-smartlinks-label]').val();
      const $type = $link.find('[data-smartlinks-type]').first();
      const typeName = ($type.is('select') ? $type.find('option:selected').text() : $type.attr('data-name')) || Craft.t('smart-links', 'Link');
      const title = label || typeName;
      $link.find('[data-smartlinks-title]').text(title);
      this.menuTrigger($link).attr('aria-label', Craft.t('smart-links', 'Actions for {link}', {link: title}));
    },

    showType: function ($link) {
      const $type = $link.find('[data-smartlinks-type]').first();
      const handle = $type.val();
      const features = ($type.is('select') ? $type.find('option:selected') : $type).data('features') || [];

      $link.find('[data-smartlinks-data]').each(function () {
        $(this).toggleClass('hidden', $(this).attr('data-smartlinks-data') !== handle);
      });

      // An input for a feature the type does not support is disabled, so it posts nothing.
      $link.find('[data-smartlinks-feature]').each(function () {
        const supported = features.indexOf($(this).attr('data-smartlinks-feature')) !== -1;
        $(this).toggleClass('hidden', !supported);
        $(this).find('input, select, textarea').prop('disabled', !supported);
      });

      const download = $link.find('[data-smartlinks-download]')[0];
      $link.find('[data-smartlinks-filename] input').prop('disabled', !download || download.disabled || !download.checked);

      this.updateTitle($link);
    },

    move: function ($link, direction) {
      const $target = direction < 0 ? $link.prev('[data-smartlinks-link]') : $link.next('[data-smartlinks-link]');

      if (!$target.length) {
        return;
      }

      if (direction < 0) {
        $link.insertBefore($target);
      } else {
        $link.insertAfter($target);
      }

      this.update();
      this.menuTrigger($link).trigger('focus');
      Craft.cp.announce(Craft.t('smart-links', 'Moved link to position {position} of {total}.', {
        position: this.links().index($link) + 1,
        total: this.links().length,
      }));
    },

    remove: function ($link) {
      const $next = $link.next('[data-smartlinks-link]').length ? $link.next('[data-smartlinks-link]') : $link.prev('[data-smartlinks-link]');

      if (this.dragSort) {
        this.dragSort.removeItems($link);
      }

      $link.remove();
      this.update();

      // Focus stays in the editor: on the next link, else on Add, else on the editor itself.
      const $add = this.$add.is(':visible') && !this.$add.prop('disabled') ? this.$add : this.$container;
      ($next.length ? $next.find('> .smartlinks-link__header > [data-smartlinks-toggle]') : $add).trigger('focus');
      Craft.cp.announce(Craft.t('smart-links', 'Link removed.'));
    },

    canAdd: function () {
      return this.settings.max === null || this.links().length < this.settings.max;
    },

    createLink: function () {
      const template = this.$container.children('template[data-smartlinks-new-link]')[0];

      if (!template || !this.canAdd()) {
        return null;
      }

      const key = 'new' + this.reserveKey();
      const placeholder = new RegExp(this.settings.placeholder, 'g');
      const $link = $(template.innerHTML.replace(placeholder, key).trim());

      return {$link: $link, key: key, js: (this.settings.newLinkJs || '').replace(placeholder, key)};
    },

    insertLink: function (created, $after) {
      if ($after) {
        created.$link.insertAfter($after);
      } else {
        created.$link.appendTo(this.$links);
      }

      this.initLink(created.$link);
      Craft.initUiElements(created.$link);

      if (created.js) {
        Craft.appendBodyHtml('<script type="text/javascript">' + created.js + '</script>');
      }

      this.update();
    },

    addLink: function () {
      const created = this.createLink();

      if (!created) {
        return;
      }

      this.insertLink(created);
      this.showType(created.$link);
      created.$link.find('select[data-smartlinks-type], input:not([type="hidden"]):not(:disabled)').first().trigger('focus');
      Craft.cp.announce(Craft.t('smart-links', 'Link added.'));
    },

    /**
     * The number the next new link, or the next paste's links, are named with. It is taken when
     * the operation starts, never when the server answers.
     */
    reserveKey: function () {
      return ++this.newKeys;
    },

    /**
     * Runs one request to the server, or nothing if one is already running: a repeated click on
     * Paste, Copy or Duplicate does not start a second. Adding, editing, moving and removing
     * links go on meanwhile; nothing they do can take a key the request has.
     */
    transfer: function ($trigger, run) {
      if (this.busy) {
        return Promise.resolve();
      }

      this.busy = true;
      this.$container.attr('aria-busy', 'true');
      $trigger && $trigger.addClass('loading');
      this.update();

      return Promise.resolve()
        .then(run)
        .catch((error) => {
          // A fault in the editor itself, not a failed request (those are reported where they
          // happen): said, rather than left to look as if nothing happened, and still thrown.
          Craft.cp.displayError(Craft.t('smart-links', 'Something went wrong in the link editor. Reload the page and try again.'));
          throw error;
        })
        .finally(() => {
          this.busy = false;
          this.$container.removeAttr('aria-busy');
          $trigger && $trigger.removeClass('loading');
          this.update();
        });
    },

    duplicate: function ($link) {
      return this.transfer(this.menuTrigger($link), () => this.requestCopy($link, 'duplicate').then((clipboard) => {
        if (clipboard !== null) {
          return this.requestPaste(clipboard, $link, 'duplicate');
        }
      }));
    },

    copy: function ($links, $trigger) {
      return this.transfer($trigger, () => this.requestCopy($links, 'copy').then((clipboard) => {
        if (clipboard === null) {
          return;
        }

        try {
          window.localStorage.setItem(CLIPBOARD_KEY, clipboard);
          Craft.cp.displayNotice($links.length > 1
            ? Craft.t('smart-links', 'Links copied. Paste them into any Smart Links field.')
            : Craft.t('smart-links', 'Link copied. Paste it into any Smart Links field.'));
        } catch (e) {
          Craft.cp.displayError(Craft.t('smart-links', 'Could not copy. This browser does not let the copied links be stored.'));
        }
      }));
    },

    paste: function ($trigger) {
      const clipboard = this.clipboard();

      if (!clipboard) {
        Craft.cp.displayError(Craft.t('smart-links', 'There is nothing to paste.'));

        return Promise.resolve();
      }

      return this.transfer($trigger, () => this.requestPaste(clipboard, null, 'paste'));
    },

    /**
     * Sends links' inputs to the server, which reads them by the link rules and returns them as
     * clipboard text, or refuses links that are not valid yet. Null when it could not.
     */
    requestCopy: function ($links, action) {
      const data = new URLSearchParams();
      data.append('context', this.settings.context);
      data.append('destination', JSON.stringify(this.settings.destination));

      // Each input is named `…[links][<key>][…]`; the link's own key marks where its part starts,
      // whatever the field and its namespace are called.
      $links.each((index, link) => {
        const marker = '[links][' + $(link).attr('data-key') + ']';

        $(link).find(':input').serializeArray().forEach((input) => {
          const at = input.name.lastIndexOf(marker);

          if (at !== -1) {
            data.append('links' + input.name.slice(at + '[links]'.length), input.value);
          }
        });
      });

      return this.request('smart-links/field/copy', data, action).then((response) => {
        if (response === null) {
          return null;
        }

        const clipboard = response.data && response.data.clipboard;

        if (typeof clipboard !== 'string' || clipboard === '') {
          this.showFailure(action, Craft.t('smart-links', 'The server’s answer does not match this request. Reload the page and try again.'));

          return null;
        }

        return clipboard;
      });
    },

    /**
     * Asks the server to render clipboard links for this field, under keys of this paste's own,
     * and adds every one of them as copied, after $after or at the end, or none, saying why.
     */
    requestPaste: function (clipboard, $after, action) {
      const operation = this.reserveKey();
      const data = new URLSearchParams();
      data.append('context', this.settings.context);
      data.append('destination', JSON.stringify(this.settings.destination));
      data.append('clipboard', clipboard);
      data.append('count', String(this.links().length));
      data.append('operation', String(operation));

      return this.request('smart-links/field/paste', data, action).then((response) => {
        if (response === null) {
          return;
        }

        const links = response.data && response.data.links;
        const refusal = this.pasteRefusal(links, operation, $after);

        if (refusal !== null) {
          this.showFailure(action, refusal);

          return;
        }

        let $last = $after || null;
        let $first = null;

        links.forEach((link) => {
          const $link = link.$link;
          this.insertLink({$link: $link, js: link.js}, $last);
          this.showType($link);
          $last = $link;
          $first = $first || $link;
        });

        $first.find('> .smartlinks-link__header > [data-smartlinks-toggle]').trigger('focus');
        Craft.cp.announce(action === 'duplicate' ? Craft.t('smart-links', 'Link duplicated.') : Craft.t('smart-links', 'Links pasted.'));
      });
    },

    /**
     * The server's response, or null once a failed request has been reported. Only the request's
     * own failures are reported this way; a fault in handling the response is not mistaken for one.
     */
    request: function (path, data, action) {
      return Craft.sendActionRequest('POST', path, {data: data, timeout: REQUEST_TIMEOUT}).then((response) => response, (error) => {
        this.showFailure(action, this.failureReason(error));

        return null;
      });
    },

    /**
     * Why the links the server rendered for a paste cannot be added now, or null if they can.
     * The editor may have changed while the server was answering, so the answer is checked
     * against the editor as it is, and added whole or not at all.
     */
    pasteRefusal: function (links, operation, $after) {
      const prefix = 'new' + operation + '-';
      const keys = this.links().map((index, link) => $(link).attr('data-key')).get();

      // Each link must be exactly one link of this paste's own, under a key nothing else has, so
      // every one of them is known to be addable before any is added.
      const malformed = !Array.isArray(links) || links.length === 0 || links.some((link) => {
        if (!link || typeof link.key !== 'string' || typeof link.html !== 'string' || typeof link.js !== 'string' || link.key.indexOf(prefix) !== 0 || keys.indexOf(link.key) !== -1) {
          return true;
        }

        keys.push(link.key);
        link.$link = $($.parseHTML(link.html.trim()));

        return link.$link.length !== 1 || !link.$link.is('[data-smartlinks-link]') || link.$link.attr('data-key') !== link.key;
      });

      if (malformed) {
        return Craft.t('smart-links', 'The server’s answer does not match this request. Reload the page and try again.');
      }

      if ($after && !$after.parent().is(this.$links)) {
        return Craft.t('smart-links', 'The link was removed before its copy arrived.');
      }

      if (this.settings.max !== null && this.links().length + links.length > this.settings.max) {
        return Craft.t('smart-links', 'This field holds at most {max, number} {max, plural, =1{link} other{links}}.', {max: this.settings.max});
      }

      return null;
    },

    /**
     * What went wrong with a request, as the author can act on it: the server's reasons when it
     * refused the links, otherwise what kind of failure it was.
     */
    failureReason: function (error) {
      const response = error && error.response;
      const data = response && response.data;

      if (data && Array.isArray(data.errors) && data.errors.length) {
        return data.errors.join(' ');
      }

      if (!response && error && (error.code === 'ECONNABORTED' || error.code === 'ETIMEDOUT')) {
        return Craft.t('smart-links', 'The server did not answer in time. Try again.');
      }

      if (!response) {
        return Craft.t('smart-links', 'The server could not be reached. Check the connection and try again.');
      }

      if (response.status === 403) {
        return Craft.t('smart-links', 'You are not allowed to change these links.');
      }

      // Craft's own message for the request, e.g. that the form expired (CSRF).
      if (data && typeof data.message === 'string' && data.message !== '') {
        return data.message;
      }

      if (response.status >= 500) {
        return Craft.t('smart-links', 'The server ran into a problem. Try again in a moment.');
      }

      return Craft.t('smart-links', 'The request failed with status {status}. Reload the page and try again.', {status: response.status});
    },

    showFailure: function (action, reason) {
      const messages = {
        copy: Craft.t('smart-links', 'Could not copy. {reason}', {reason: reason}),
        duplicate: Craft.t('smart-links', 'Could not duplicate. {reason}', {reason: reason}),
        paste: Craft.t('smart-links', 'Could not paste. {reason}', {reason: reason}),
      };

      Craft.cp.displayError(messages[action]);
    },

    clipboard: function () {
      try {
        return window.localStorage.getItem(CLIPBOARD_KEY);
      } catch (e) {
        return null;
      }
    },

    addRow: function ($link, index) {
      const template = this.$container.children('template[data-smartlinks-custom-row]')[0];
      const $rows = $link.find('[data-smartlinks-custom-rows]');

      if (!template || !$rows.length) {
        return;
      }

      if (index === undefined) {
        index = 'n' + $rows.data('nextRow');
        $rows.data('nextRow', $rows.data('nextRow') + 1);
      }

      const html = template.innerHTML
        .replace(new RegExp(this.settings.placeholder, 'g'), $link.attr('data-key'))
        .replace(/__ROW__/g, index);

      const $row = $(html.trim()).appendTo($rows);
      $row.find('input').first().trigger('focus');
    },

    clearStored: function () {
      // What the value holds now is gone once the element is saved, so it is cleared only on
      // the author's word.
      if (!window.confirm(Craft.t('smart-links', 'Clear this value? What it holds now is removed when the element is saved.'))) {
        return;
      }

      this.$container.children('[data-smartlinks-stored]').remove();
      this.$add.removeAttr('hidden');
      this.update();
    },

    update: function () {
      const $links = this.links();
      const full = !this.canAdd();
      const stored = this.$container.children('[data-smartlinks-stored]').length > 0;
      const busy = this.busy;

      this.$add.prop('disabled', full).toggleClass('disabled', full);
      // Copying and pasting need an editor the server can name; a field not saved yet has none.
      const canCopy = !!this.settings.context;
      this.$paste.prop('hidden', !canCopy || stored || !this.clipboard()).prop('disabled', full || busy).toggleClass('disabled', full || busy);
      this.$copyAll.prop('hidden', !canCopy || $links.length === 0).prop('disabled', busy).toggleClass('disabled', busy);

      $links.each((index, link) => {
        const $menu = $(document.getElementById(this.menuTrigger($(link)).attr('aria-controls')));
        $menu.find('[data-smartlinks-action="move-up"]').closest('li').toggleClass('hidden', index === 0);
        $menu.find('[data-smartlinks-action="move-down"]').closest('li').toggleClass('hidden', index === $links.length - 1);
        $menu.find('[data-smartlinks-action="duplicate"]').prop('disabled', full || busy).toggleClass('disabled', full || busy);
        $menu.find('[data-smartlinks-action="copy"]').prop('disabled', busy).toggleClass('disabled', busy);
        $menu.find('[data-smartlinks-action="duplicate"], [data-smartlinks-action="copy"]').closest('li').toggleClass('hidden', !canCopy || $(link).find('[name$="[stored]"]').length > 0);
      });
    },
  });
})(jQuery);
