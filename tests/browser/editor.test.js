/**
 * The link editor's behaviour in a real browser, run by tests/browser/run.php.
 *
 * The editor markup is Craft's real rendering (render.php), and jQuery and Garnish are Craft's
 * own. Only Craft's server round trips and notices are stood in for: action requests answer
 * with the field controller's real responses, recorded by render.php, so what is tested here is
 * what the browser does with them.
 */
(async function () {
  'use strict';

  const results = [];
  const check = (name, pass, detail) => results.push({name: name, pass: !!pass, detail: detail === undefined ? null : detail});
  const field = () => $('#fields-links');
  const links = () => field().find('[data-smartlinks-links] > [data-smartlinks-link]');
  const titles = () => links().map((i, link) => $(link).find('[data-smartlinks-title]').text().trim()).get();
  const menu = ($link) => $(document.getElementById($link.find('[data-disclosure-trigger]').attr('aria-controls')));
  const action = ($link, name) => menu($link).find('[data-smartlinks-action="' + name + '"]');
  const posted = () => $('#form').serializeArray().filter((input) => input.name.indexOf('fields[links]') === 0);
  const settle = () => new Promise((resolve) => setTimeout(resolve, 50));
  const keys = ($field) => ($field || field()).find('[data-smartlinks-links] > [data-smartlinks-link]').map((i, link) => $(link).attr('data-key')).get();
  // What the form posts for each link, by the key its inputs are named with, and every input
  // named for a key that is not its own link's: one link's inputs posting as another's.
  const postedLinks = () => {
    const byKey = {};
    const strays = [];
    field().find('[data-smartlinks-links] :input[name]').each((i, input) => {
      const match = /^fields\[links\]\[links\]\[([^\]]+)\](.*)$/.exec(input.name);
      if (match) {
        if ($(input).closest('[data-smartlinks-link]').attr('data-key') !== match[1]) {
          strays.push(input.name);
        }
        byKey[match[1]] = byKey[match[1]] || {};
        if (!input.disabled && (input.type !== 'checkbox' || input.checked)) {
          byKey[match[1]][match[2]] = input.value;
        }
      }
    });
    return {byKey: byKey, strays: strays};
  };
  const operationOf = (request) => (request.params.find((pair) => pair[0] === 'operation') || [])[1];
  const removeUntil = (count) => {
    while (links().length > count) {
      action(links().last(), 'remove').trigger('click');
    }
  };

  try {
    // Initialised once, however often its script runs.
    eval(window.initJs);
    eval(window.initJs);
    const editor = field().data('smartLinksField');
    check('the editor initialises once', editor && links().length === 3);

    // Reordering from the actions menu's Move up and Move down.
    const $second = links().eq(1);
    action($second, 'move-up').trigger('click');
    check('Move up moves the link up', titles().join() === 'Second,First,Third', titles());
    check('Move up keeps focus on the moved link', document.activeElement === $second.find('[data-disclosure-trigger]')[0]);
    check('Move up is announced', Craft.announced.slice(-1)[0] === 'Moved link to position 1 of 3.', Craft.announced.slice(-1)[0]);
    check('the first link offers no Move up', action(links().eq(0), 'move-up').closest('li').hasClass('hidden'));
    check('the last link offers no Move down', action(links().eq(2), 'move-down').closest('li').hasClass('hidden'));
    action(links().eq(0), 'move-down').trigger('click');
    check('Move down moves the link down', titles().join() === 'First,Second,Third', titles());

    // From the keyboard: Enter on a menu item, which Garnish's menus take as `activate`, never
    // as a click.
    const enter = () => $.Event('keydown', {keyCode: 13, which: 13, key: 'Enter'});
    action(links().eq(1), 'move-up').trigger(enter());
    check('Enter on Move up moves the link up, from the keyboard', titles().join() === 'Second,First,Third', titles());
    action(links().eq(0), 'move-down').trigger(enter());
    check('Enter on Move down moves it back, from the keyboard', titles().join() === 'First,Second,Third', titles());

    // The order posted is the order shown.
    const uids = posted().filter((input) => /\[uid\]$/.test(input.name)).map((input) => input.value);
    check('links post in the order shown', uids.join() === '11111111-1111-4111-8111-111111111111,22222222-2222-4222-8222-222222222222,33333333-3333-4333-8333-333333333333', uids);

    // Drag and drop, with the mouse, by the drag handle.
    const $first = links().eq(0);
    const $handle = $first.find('.smartlinks-link__drag');
    const start = $handle.offset();
    const target = links().eq(2).offset().top + links().eq(2).outerHeight() + 10;
    $handle.trigger($.Event('mousedown', {which: 1, button: 0, pageX: start.left + 2, pageY: start.top + 2, target: $handle[0]}));

    for (let y = start.top + 2; y <= target; y += 8) {
      $(document).trigger($.Event('mousemove', {pageX: start.left + 2, pageY: y}));
      await new Promise((resolve) => setTimeout(resolve, 16));
    }

    $(document).trigger($.Event('mouseup', {pageX: start.left + 2, pageY: target}));
    check('dragging a link moves it', titles().join() === 'Second,Third,First', titles());

    // Garnish hides the dragged link until its helper has returned to it.
    for (let wait = 0; wait < 40 && getComputedStyle($first[0]).visibility !== 'visible'; wait++) {
      await new Promise((resolve) => setTimeout(resolve, 50));
    }

    check('the dropped link is shown again', getComputedStyle($first[0]).visibility === 'visible');

    // Collapse and expand.
    const $toggle = links().eq(0).find('[data-smartlinks-toggle]');
    $toggle.trigger('click');
    check('collapsing hides the body and says so', $toggle.attr('aria-expanded') === 'false' && links().eq(0).children('.smartlinks-link__body').hasClass('hidden'));
    $toggle.trigger('click');
    check('expanding shows the body and says so', $toggle.attr('aria-expanded') === 'true' && !links().eq(0).children('.smartlinks-link__body').hasClass('hidden'));
    check('the toggle controls its own link body', document.getElementById($toggle.attr('aria-controls')) === links().eq(0).children('.smartlinks-link__body')[0]);

    // Adding a link, and its type's own inputs.
    field().find('[data-smartlinks-add]').trigger('click');
    const $added = links().last();
    check('Add a link adds one from the template', links().length === 4 && $added.data('key') === 'new1');
    check('the added link has no placeholder left', $added[0].outerHTML.indexOf('__SMARTLINK__') === -1);
    check('the added link’s menu script ran', Craft.appended.length === 1 && Craft.appended[0].indexOf('new1') !== -1);
    $added.find('[data-smartlinks-type]').val('email').trigger('change');
    check('choosing a type shows its inputs only', !$added.find('[data-smartlinks-data="email"]').hasClass('hidden') && $added.find('[data-smartlinks-data="url"]').hasClass('hidden'));
    check('inputs the type does not support are hidden and disabled', $added.find('[data-smartlinks-feature="target"]').hasClass('hidden') && $added.find('[data-smartlinks-feature="target"] select').prop('disabled'));
    $added.find('[data-smartlinks-label]').val('Written').trigger('input');
    check('the title follows the label', $added.find('[data-smartlinks-title]').text() === 'Written');
    check('at the maximum, Add a link is disabled', field().find('[data-smartlinks-add]').prop('disabled'));

    // Download and its filename.
    const $url = links().filter((i, link) => $(link).find('[data-smartlinks-title]').text() === 'First');
    const $download = $url.find('input[type="checkbox"][data-smartlinks-download]');
    check('the filename is unavailable until download is on', $url.find('[data-smartlinks-filename] input').prop('disabled'));
    $download.prop('checked', true).trigger('change');
    check('turning download on makes the filename available', !$url.find('[data-smartlinks-filename] input').prop('disabled') && !$url.find('[data-smartlinks-filename]').hasClass('hidden'));

    // Custom attribute rows, in the Advanced section, opened as its toggle opens it.
    $url.find('.smartlinks-link__advanced').removeClass('hidden');
    $url.find('[data-smartlinks-add-row]').trigger('click');
    const rowNames = $url.find('[data-smartlinks-custom-row-item] input').map((i, input) => input.name).get();
    check('adding a row adds named inputs for this link', rowNames.length === 4 && rowNames[2].indexOf('[links][link0][attributes][custom][n1][name]') !== -1, rowNames);
    check('the new row has focus', document.activeElement === $url.find('[data-smartlinks-custom-row-item] input')[2], [document.activeElement && document.activeElement.name, $url.find('[data-smartlinks-custom-row-item] input').eq(2).is(':visible')]);
    $url.find('[data-smartlinks-remove-row]').first().trigger('click');
    check('removing a row removes it', $url.find('[data-smartlinks-custom-row-item]').length === 1);

    // Removing a link moves focus to the next one.
    const $removed = links().eq(0);
    action($removed, 'remove').trigger('click');
    check('Remove removes the link', links().length === 3 && $.contains(document, $removed[0]) === false);
    check('Remove moves focus to the next link', document.activeElement === links().eq(0).find('[data-smartlinks-toggle]')[0]);
    check('Remove is announced', Craft.announced.slice(-1)[0] === 'Link removed.');

    // Copying sends the link's inputs, named as the link data, to the server.
    Craft.requests = [];
    action($url, 'copy').trigger('click');
    await settle();
    const copy = Craft.requests[0];
    check('Copy asks the server for the link data', copy && copy.action === 'smart-links/field/copy');
    check('Copy says which editor it is', copy && copy.params.some((pair) => pair[0] === 'destination' && JSON.parse(pair[1]).editor === 'fields-links'), copy && copy.params);
    check('Copy sends only this link’s inputs, under links[…]', copy && copy.params.every((pair) => pair[0] === 'context' || pair[0] === 'destination' || pair[0].indexOf('links[link0]') === 0) && copy.params.some((pair) => pair[0] === 'links[link0][data][url][url]' && pair[1] === 'https://example.com/a'), copy && copy.params);
    check('Copy keeps the server’s clipboard text', window.localStorage.getItem('Craft-SmartLinks.clipboard') === window.responses.clipboard);
    check('Paste is offered once something is copied', !field().find('[data-smartlinks-paste]').prop('hidden'));

    // A paste the field refuses changes nothing, keeps the clipboard, and says why.
    const before = field().find('[data-smartlinks-links]').html();
    Craft.nextPaste = 'refuse';
    field().find('[data-smartlinks-paste]').trigger('click');
    await settle();
    check('a refused paste adds nothing', field().find('[data-smartlinks-links]').html() === before);
    check('a refused paste keeps the clipboard', window.localStorage.getItem('Craft-SmartLinks.clipboard') === window.responses.clipboard);
    check('a refused paste says why', Craft.errors.slice(-1)[0] === 'Could not paste. Link 1: Entry links are not allowed in this field.', Craft.errors.slice(-1)[0]);

    // Clipboard text is only ever sent to the server, never read or inserted here.
    window.localStorage.setItem('Craft-SmartLinks.clipboard', '<img src=x onerror="window.injected=1">');
    Craft.nextPaste = 'refuse';
    field().find('[data-smartlinks-paste]').trigger('click');
    await settle();
    const sent = Craft.requests.slice(-1)[0].params.find((pair) => pair[0] === 'clipboard');
    check('clipboard text is sent as text', sent && sent[1] === '<img src=x onerror="window.injected=1">');
    check('clipboard text is never inserted as markup', !window.injected && $('img').length === 0);

    // A paste the field accepts adds the server's rendering, after the source for a duplicate.
    window.localStorage.setItem('Craft-SmartLinks.clipboard', window.responses.clipboard);
    action(links().eq(0), 'remove').trigger('click');
    Craft.nextPaste = 'accept';
    const $source = links().eq(0);
    action($source, 'duplicate').trigger('click');
    await settle();
    check('Duplicate asks the server to copy and then paste', Craft.requests.slice(-2).map((request) => request.action).join() === 'smart-links/field/copy,smart-links/field/paste');
    const pastedKey = $source.next('[data-smartlinks-link]').attr('data-key');
    check('Duplicate inserts the copy right after its source', /^new\d+-1$/.test(pastedKey || ''), pastedKey);
    check('the pasted link is named for its own paste', links().filter('[data-key="' + pastedKey + '"]').find('input[name="fields[links][links][' + pastedKey + '][label]"]').val() === 'Pasted');
    check('the pasted link has no UID', links().filter('[data-key="' + pastedKey + '"]').find('[data-smartlinks-uid]').val() === '');
    check('focus moves to the pasted link', document.activeElement === links().filter('[data-key="' + pastedKey + '"]').find('[data-smartlinks-toggle]')[0]);
    check('Duplicate is announced', Craft.announced.slice(-1)[0] === 'Link duplicated.');

    // Each handler is bound once: one click is one move.
    const order = titles().join();
    action(links().eq(1), 'move-up').trigger('click');
    action(links().eq(0), 'move-down').trigger('click');
    check('each action runs once per click', titles().join() === order, titles());
    // Overlapping operations. Each new link's key is taken when its operation starts, so links
    // added while a paste is in flight never share a key, and so never share inputs, with what
    // the paste brings.
    removeUntil(1);
    Craft.nextPaste = 'accept';
    Craft.hold = true;
    Craft.requests = [];
    field().find('[data-smartlinks-paste]').trigger('click');
    await settle();
    check('a paste in flight marks the editor busy', field().attr('aria-busy') === 'true');
    check('a paste in flight shows on its button', field().find('[data-smartlinks-paste]').hasClass('loading'));
    check('a paste in flight holds back other pastes and copies', field().find('[data-smartlinks-paste]').prop('disabled') && field().find('[data-smartlinks-copy-all]').prop('disabled') && action(links().eq(0), 'duplicate').prop('disabled') && action(links().eq(0), 'copy').prop('disabled'));
    check('a paste in flight leaves Add available', !field().find('[data-smartlinks-add]').prop('disabled'));

    // Paste, then at once Add.
    field().find('[data-smartlinks-add]').trigger('click');
    const $addedMeanwhile = links().last();
    $addedMeanwhile.find('[data-smartlinks-label]').val('Added meanwhile').trigger('input');

    // Paste, then at once Paste and Duplicate again: neither starts another request.
    field().find('[data-smartlinks-paste]').prop('disabled', false).trigger('click');
    editor.duplicate(links().eq(0));
    await settle();
    check('a second paste or a duplicate does not start while one is in flight', Craft.requests.length === 1 && Craft.pending.length === 1, Craft.requests.map((request) => request.action));

    const pasteKey = 'new' + operationOf(Craft.requests[0]) + '-1';
    await Craft.release();
    Craft.hold = false;
    const afterOverlap = postedLinks();
    check('a paste and an Add that overlapped both keep their link', links().length === 3 && keys().indexOf(pasteKey) !== -1 && keys().indexOf($addedMeanwhile.attr('data-key')) !== -1, keys());
    check('no two links share a key', new Set(keys()).size === keys().length, keys());
    check('no two links share an input', afterOverlap.strays.length === 0 && Object.keys(afterOverlap.byKey).length === 3, afterOverlap.strays);
    check('each overlapping link posts its own values', (afterOverlap.byKey[$addedMeanwhile.attr('data-key')] || {})['[label]'] === 'Added meanwhile' && (afterOverlap.byKey[pasteKey] || {})['[label]'] === 'Pasted', afterOverlap.byKey);
    check('the editor is no longer busy', field().attr('aria-busy') === undefined && !field().find('[data-smartlinks-paste]').hasClass('loading'));

    // Duplicate, then at once Add: the copy still lands after its source, under its own key.
    removeUntil(2);
    Craft.hold = true;
    const $duplicated = links().eq(0);
    action($duplicated, 'duplicate').trigger('click');
    await settle();
    await Craft.release();
    field().find('[data-smartlinks-add]').trigger('click');
    await Craft.release();
    Craft.hold = false;
    check('a duplicate and an Add that overlapped both keep their link', links().length === 4 && /^new\d+-1$/.test($duplicated.next().attr('data-key') || '') && new Set(keys()).size === 4, keys());

    // An answer that no longer fits the editor is not applied: the editor may have changed while
    // the server was answering.
    removeUntil(3);
    Craft.hold = true;
    Craft.errors = [];
    field().find('[data-smartlinks-paste]').trigger('click');
    await settle();
    field().find('[data-smartlinks-add]').trigger('click');
    const keysAtMaximum = keys().join();
    await Craft.release();
    Craft.hold = false;
    check('a paste that no longer fits under the maximum adds nothing', links().length === 4 && keys().join() === keysAtMaximum, keys());
    check('a paste that no longer fits says why', /^Could not paste\. This field holds at most/.test(Craft.errors.slice(-1)[0] || ''), Craft.errors);

    removeUntil(2);
    Craft.hold = true;
    const $gone = links().eq(1);
    action($gone, 'duplicate').trigger('click');
    await settle();
    await Craft.release();
    action($gone, 'remove').trigger('click');
    await Craft.release();
    Craft.hold = false;
    check('a duplicate whose source was removed meanwhile adds nothing', links().length === 1 && keys()[0] === 'link0', keys());
    check('a duplicate whose source was removed says so', Craft.errors.slice(-1)[0] === 'Could not duplicate. The link was removed before its copy arrived.', Craft.errors.slice(-1)[0]);

    Craft.nextPaste = 'mismatch';
    const keysBefore = keys().join();
    field().find('[data-smartlinks-paste]').trigger('click');
    await settle();
    check('an answer for another paste is not applied', keys().join() === keysBefore);
    check('an answer for another paste is reported', Craft.errors.slice(-1)[0] === 'Could not paste. The server’s answer does not match this request. Reload the page and try again.', Craft.errors.slice(-1)[0]);
    Craft.nextPaste = 'accept';

    // Each kind of failure is reported as what it is, and the editor is usable again after it.
    const failures = {
      network: 'Could not paste. The server could not be reached. Check the connection and try again.',
      csrf: 'Could not paste. Unable to verify your data submission.',
      forbidden: 'Could not paste. You are not allowed to change these links.',
      notFound: 'Could not paste. The request failed with status 404. Reload the page and try again.',
      server: 'Could not paste. The server ran into a problem. Try again in a moment.',
      timeout: 'Could not paste. The server did not answer in time. Try again.',
    };

    for (const [failure, message] of Object.entries(failures)) {
      Craft.nextFailure = failure;
      field().find('[data-smartlinks-paste]').trigger('click');
      await settle();
      check('a ' + failure + ' failure is reported as such', Craft.errors.slice(-1)[0] === message, Craft.errors.slice(-1)[0]);
    }

    check('every request has a time limit, so the editor is never left busy', Craft.requests.length > 0 && Craft.requests.every((request) => request.timeout === 30000), Craft.requests.map((request) => request.timeout));

    // An answer that is not what was asked for adds nothing and stores nothing.
    Craft.nextPaste = 'malformed';
    const keysBeforeMalformed = keys().join();
    field().find('[data-smartlinks-paste]').trigger('click');
    await settle();
    Craft.nextPaste = 'accept';
    check('a paste answer that is not a link adds nothing', keys().join() === keysBeforeMalformed && field().find('[data-smartlinks-links] > p').length === 0);
    check('a paste answer that is not a link is reported', Craft.errors.slice(-1)[0] === 'Could not paste. The server’s answer does not match this request. Reload the page and try again.', Craft.errors.slice(-1)[0]);
    const clipboardBefore = window.localStorage.getItem('Craft-SmartLinks.clipboard');
    Craft.nextCopy = 'empty';
    action(links().eq(0), 'copy').trigger('click');
    await settle();
    Craft.nextCopy = 'accept';
    check('a copy answer without clipboard text stores nothing', window.localStorage.getItem('Craft-SmartLinks.clipboard') === clipboardBefore);
    check('a copy answer without clipboard text is reported', Craft.errors.slice(-1)[0] === 'Could not copy. The server’s answer does not match this request. Reload the page and try again.', Craft.errors.slice(-1)[0]);

    Craft.nextFailure = 'network';
    action(links().eq(0), 'copy').trigger('click');
    await settle();
    check('a failed copy says it was the copy', Craft.errors.slice(-1)[0] === 'Could not copy. The server could not be reached. Check the connection and try again.', Craft.errors.slice(-1)[0]);
    check('a failure leaves no link changed and the editor usable', links().length === 1 && field().attr('aria-busy') === undefined && !field().find('[data-smartlinks-paste]').prop('disabled') && !action(links().eq(0), 'copy').prop('disabled'));

    const setItem = Storage.prototype.setItem;
    Storage.prototype.setItem = () => { throw new Error('denied'); };
    action(links().eq(0), 'copy').trigger('click');
    await settle();
    Storage.prototype.setItem = setItem;
    check('a browser that refuses to store the copy is reported as that', Craft.errors.slice(-1)[0] === 'Could not copy. This browser does not let the copied links be stored.', Craft.errors.slice(-1)[0]);

    // A field with one link type has no type selector.
    const single = $('#fields-one');
    single.find('[data-smartlinks-add]').trigger('click');
    const $only = single.find('[data-smartlinks-links] > [data-smartlinks-link]').last();
    check('Add focuses the new link’s first input when there is no type selector', document.activeElement === $only.find('[data-smartlinks-data="url"] input:not([type="hidden"])')[0], document.activeElement && document.activeElement.name);
    $only.find('[data-smartlinks-label]').val('').trigger('input');
    check('an unlabelled link is titled with its type', $only.find('[data-smartlinks-title]').text() === 'URL', $only.find('[data-smartlinks-title]').text());
    $only.find('[data-smartlinks-label]').val('Home').trigger('input');
    check('the actions menu is named for the link as it is now', $only.find('[data-disclosure-trigger]').attr('aria-label') === 'Actions for Home', $only.find('[data-disclosure-trigger]').attr('aria-label'));
    action($only, 'remove').trigger('click');
    check('removing the last link leaves focus on Add', document.activeElement === single.find('[data-smartlinks-add]')[0]);

    // A value that cannot be read is cleared only on the author's word.
    const kept = $('#fields-kept');
    const confirm = window.confirm;
    window.confirm = () => false;
    kept.find('[data-smartlinks-clear]').trigger('click');
    check('declining to clear keeps the unreadable value', kept.find('input[name="fields[kept][stored]"]').length === 1);
    window.confirm = () => true;
    kept.find('[data-smartlinks-clear]').trigger('click');
    window.confirm = confirm;
    check('confirming clears it', kept.find('input[name="fields[kept][stored]"]').length === 0 && !kept.find('[data-smartlinks-add]').prop('hidden'));

    // Presets: choosing one fills in only what is still empty, choosing another takes back only
    // what the last one filled in untouched, and what a preset locks is held while it is
    // chosen. A link's stored values are its own: opening the editor changes none of them.
    const EXTERNAL = 'e1a2b3c4-d5e6-4f70-8a9b-0c1d2e3f4a5b';
    const TRACKED = 'f2b3c4d5-e6f7-4a81-9b0c-1d2e3f4a5b6c';
    const DOWNLOAD = 'a3c4d5e6-f7a8-4b92-8c1d-2e3f4a5b6c7d';
    const presetField = $('#fields-preset');
    const presetLinks = () => presetField.find('[data-smartlinks-links] > [data-smartlinks-link]');
    const setting = ($link, suffix) => $link.find(':input').filter((i, input) => input.type !== 'hidden' && input.name.slice(-suffix.length) === suffix).first();
    const choose = ($link, uid) => $link.find('[data-smartlinks-preset]').val(uid).trigger('change');
    const rows = ($link) => $link.find('[data-smartlinks-custom-rows] input[name$="[name]"]').map((i, input) => input.value).get();
    // What a link's inputs post, read as PHP reads a form: the last input of a name wins.
    const postedFor = ($link) => {
      const values = {};
      $link.find(':input[name]').each((i, input) => {
        const match = /\[links\]\[[^\]]+\](.*)$/.exec(input.name);
        if (match && !input.disabled && (input.type !== 'checkbox' || input.checked)) {
          values[match[1]] = input.value;
        }
      });
      return values;
    };

    const $made = presetLinks().eq(0);
    check('a link made with a preset opens with its own values', setting($made, '[attributes][rel]').val() === 'nofollow' && setting($made, '[attributes][target]').val() === '_blank');
    check('what its preset locks is read-only, and still posts', setting($made, '[attributes][target]').prop('disabled') && postedFor($made)['[attributes][target]'] === '_blank', postedFor($made));
    check('a locked setting says which preset sets it', $made.find('[data-smartlinks-lock-note]').text() === 'Set by the “External” preset.', $made.find('[data-smartlinks-lock-note]').text());

    const $own = presetLinks().eq(1);
    choose($own, TRACKED);
    check('choosing a preset fills in what is empty', setting($own, '[urlSuffix]').val() === '?ref=site' && postedFor($own)['[urlSuffix]'] === '?ref=site');
    check('choosing a preset never overwrites what the link has', setting($own, '[attributes][class]').val() === 'mine');
    check('a preset’s custom attributes are added as rows', rows($own).join() === 'data-track' && postedFor($own)['[attributes][custom][n0][value]'] === 'cta', postedFor($own));
    check('applying a preset is announced', Craft.announced.slice(-1)[0] === '“Tracked” preset applied.', Craft.announced.slice(-1)[0]);

    // The author changes what the preset filled in, and sets a target of their own.
    setting($own, '[urlSuffix]').val('?ref=mine').trigger('input');
    setting($own, '[attributes][target]').val('_top').trigger('change');
    choose($own, EXTERNAL);
    check('switching presets keeps what the author changed', setting($own, '[urlSuffix]').val() === '?ref=mine');
    check('switching presets takes back what the last one filled in untouched', rows($own).length === 0, rows($own));
    check('the new preset fills in what is now empty', setting($own, '[attributes][rel]').val() === 'external noopener');
    check('a locked setting takes the preset’s value over the author’s, visibly', setting($own, '[attributes][target]').val() === '_blank' && setting($own, '[attributes][target]').prop('disabled') && postedFor($own)['[attributes][target]'] === '_blank' && $own.find('[data-smartlinks-lock-note]').length === 1);

    choose($own, '');
    check('no preset gives a locked setting back what it had', setting($own, '[attributes][target]').val() === '_top' && !setting($own, '[attributes][target]').prop('disabled') && postedFor($own)['[attributes][target]'] === '_top', postedFor($own));
    check('no preset takes back only what it filled in', setting($own, '[attributes][rel]').val() === '' && setting($own, '[urlSuffix]').val() === '?ref=mine' && setting($own, '[attributes][class]').val() === 'mine');
    check('no lock is left behind', $own.find('[data-smartlinks-lock-copy], [data-smartlinks-lock-note], [data-smartlinks-locked]').length === 0);
    check('removing a preset is announced', Craft.announced.slice(-1)[0] === 'Preset removed.', Craft.announced.slice(-1)[0]);

    // A preset disabled since a link was made with it: that link keeps it, named as disabled,
    // with its lock shown; no other link is offered it.
    const RETIRED = 'b4d5e6f7-a8b9-4ca3-9d2e-3f4a5b6c7d8e';
    const $retired = presetLinks().eq(2);
    const offers = ($link) => $link.find('[data-smartlinks-preset] option').map((i, option) => option.value).get();
    check('a link keeps a preset disabled since, named as disabled', $retired.find('[data-smartlinks-preset]').val() === RETIRED && $retired.find('[data-smartlinks-preset] option:selected').text() === 'Retired (disabled)');
    check('a disabled preset’s lock is still shown on the link that has it', setting($retired, '[attributes][class]').prop('readonly') && $retired.find('[data-smartlinks-lock-note]').text() === 'Set by the “Retired” preset.');
    check('no other link is offered a disabled preset', offers($own).indexOf(RETIRED) === -1 && offers($made).indexOf(RETIRED) === -1, offers($own));

    // Moving that link to an enabled preset, and back to the one it had (still offered to it).
    choose($retired, DOWNLOAD);
    check('switching away from a kept disabled preset keeps the link’s own values, unlocked', setting($retired, '[attributes][class]').val() === 'old' && !setting($retired, '[attributes][class]').prop('readonly'));
    check('the new preset fills in and locks what it sets', setting($retired, '[attributes][download]').prop('checked') && postedFor($retired)['[attributes][download]'] === '1');
    choose($retired, RETIRED);
    check('switching back takes back what the other preset filled in, and locks again', !setting($retired, '[attributes][download]').prop('checked') && postedFor($retired)['[attributes][download]'] === '' && setting($retired, '[attributes][class]').prop('readonly') && setting($retired, '[attributes][class]').val() === 'old', postedFor($retired));

    // Switching back and forth leaves no stale locks, copies or values.
    for (let round = 0; round < 3; round++) {
      choose($retired, DOWNLOAD);
      choose($retired, RETIRED);
    }

    check('switching back and forth leaves one lock and no stale copies or fills', $retired.find('[data-smartlinks-lock-copy]').length === 0 && $retired.find('[data-smartlinks-locked]').length === 1 && $retired.find('[data-smartlinks-lock-note]').length === 1 && $retired.find('[data-smartlinks-filled]').length === 0 && !setting($retired, '[attributes][download]').prop('checked'));

    // A preset for other link types switches the link to one it is for.
    presetField.find('[data-smartlinks-add]').trigger('click');
    const $new = presetLinks().last();
    $new.find('[data-smartlinks-type]').val('email').trigger('change');
    choose($new, DOWNLOAD);
    check('a preset for other types switches the link to the first it is for', $new.find('[data-smartlinks-type]').val() === 'url');
    check('a new link is not offered a disabled preset', offers($new).indexOf(RETIRED) === -1 && offers($new).indexOf(DOWNLOAD) !== -1, offers($new));
    check('the switch is announced with the preset', Craft.announced.slice(-1)[0] === '“Download” preset applied, as a URL link.', Craft.announced.slice(-1)[0]);
    check('a locked checkbox is on, read-only, and posts on', setting($new, '[attributes][download]').prop('checked') && setting($new, '[attributes][download]').prop('disabled') && postedFor($new)['[attributes][download]'] === '1', postedFor($new));
    check('a locked download keeps its filename editable', !$new.find('[data-smartlinks-filename] input').prop('disabled'));
    $new.find('[data-smartlinks-type]').val('email').trigger('change');
    check('a lock on what the link’s type lacks posts nothing', !('[attributes][download]' in postedFor($new)), postedFor($new));
    $new.find('[data-smartlinks-type]').val('url').trigger('change');
    check('the lock holds again when the type has it', setting($new, '[attributes][download]').prop('disabled') && postedFor($new)['[attributes][download]'] === '1', postedFor($new));
  } catch (error) {
    check('the scenario ran without errors', false, String(error && error.stack || error));
  }

  document.getElementById('result').textContent = JSON.stringify(results);
  document.title = 'done';
})();
