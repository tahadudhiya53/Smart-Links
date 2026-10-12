// The Smart Links field in Craft's real control panel: the host project's own web server, Craft's
// own element editor, element selector modals and saving, driven in headless Chrome through the
// Chrome DevTools Protocol. Content comes from fixture.php, inside DDEV, and is removed afterwards
// whatever happens; the run fails if the project is not left exactly as it was.
//
// Exits 0 when every check passes, 1 when any fails, and 2 when it could not run, so a run that
// did not happen is never reported as passed.

import {spawn, spawnSync} from 'node:child_process';
import {mkdtempSync, readFileSync, rmSync, existsSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {setTimeout as sleep} from 'node:timers/promises';

const chromeBin = process.env.CHROME_BIN || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const results = [];
let failed = false;

function fixture(...args) {
  const run = spawnSync('ddev', ['exec', '-d', '/var/www/html/plugins/Smart-Links', 'php', 'tests/cp/fixture.php', ...args], {encoding: 'utf8'});

  if (run.status !== 0) {
    throw new Error(`fixture.php ${args.join(' ')} failed:\n${run.stderr}${run.stdout}`);
  }

  return JSON.parse(run.stdout.trim().split('\n').pop());
}

function check(name, ok, detail = '') {
  results.push({name, ok});
  failed ||= !ok;
  console.log(`  ${ok ? '✔' : '✘'} ${name}${ok || !detail ? '' : `\n      ${detail}`}`);
}

// Chrome DevTools Protocol, over the WebSocket Node has built in.

class Browser {
  static async launch() {
    const profile = mkdtempSync(join(tmpdir(), 'smart-links-cp-'));
    const chrome = spawn(chromeBin, ['--headless=new', '--remote-debugging-port=0', `--user-data-dir=${profile}`, '--ignore-certificate-errors', '--window-size=1400,1100', '--no-first-run', '--no-default-browser-check', 'about:blank'], {stdio: 'ignore'});
    const portFile = join(profile, 'DevToolsActivePort');

    for (let i = 0; i < 100 && !existsSync(portFile); i++) {
      await sleep(100);
    }

    if (!existsSync(portFile)) {
      throw new Error('Chrome did not start.');
    }

    await sleep(200);
    const [port, path] = readFileSync(portFile, 'utf8').trim().split('\n');
    const socket = new WebSocket(`ws://127.0.0.1:${port}${path}`);
    await new Promise((resolve, reject) => {
      socket.onopen = resolve;
      socket.onerror = reject;
    });

    return new Browser(chrome, socket, profile);
  }

  constructor(chrome, socket, profile) {
    this.chrome = chrome;
    this.socket = socket;
    this.profile = profile;
    this.nextId = 1;
    this.pending = new Map();
    this.listeners = [];
    socket.onmessage = (event) => {
      const message = JSON.parse(event.data);

      if (message.id && this.pending.has(message.id)) {
        const {resolve, reject} = this.pending.get(message.id);
        this.pending.delete(message.id);
        message.error ? reject(new Error(message.error.message)) : resolve(message.result);
      } else if (message.method) {
        this.listeners.forEach((listener) => listener(message));
      }
    };
  }

  send(method, params = {}, sessionId = undefined) {
    const id = this.nextId++;
    this.socket.send(JSON.stringify({id, method, params, sessionId}));

    return new Promise((resolve, reject) => this.pending.set(id, {resolve, reject}));
  }

  async newPage() {
    // Each page in a context of its own: its own cookies, so its own signed-in user.
    const {browserContextId} = await this.send('Target.createBrowserContext');
    const {targetId} = await this.send('Target.createTarget', {url: 'about:blank', browserContextId});
    const {sessionId} = await this.send('Target.attachToTarget', {targetId, flatten: true});
    const page = new Page(this, sessionId);
    await page.send('Page.enable');
    await page.send('Runtime.enable');

    return page;
  }

  async close() {
    this.socket.close();
    const exited = new Promise((resolve) => this.chrome.once('exit', resolve));
    this.chrome.kill();
    await Promise.race([exited, sleep(5000)]);
    // Chrome can still be writing its profile as it exits.
    rmSync(this.profile, {recursive: true, force: true, maxRetries: 10, retryDelay: 200});
  }
}

class Page {
  constructor(browser, sessionId) {
    this.browser = browser;
    this.sessionId = sessionId;
    this.errors = [];
    browser.listeners.push((message) => {
      if (message.sessionId === sessionId && message.method === 'Runtime.exceptionThrown') {
        this.errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
      }
    });
  }

  send(method, params = {}) {
    return this.browser.send(method, params, this.sessionId);
  }

  loaded() {
    return new Promise((resolve) => {
      const listener = (message) => {
        if (message.sessionId === this.sessionId && message.method === 'Page.loadEventFired') {
          this.browser.listeners.splice(this.browser.listeners.indexOf(listener), 1);
          resolve();
        }
      };
      this.browser.listeners.push(listener);
    });
  }

  async goto(url) {
    const loaded = this.loaded();
    await this.send('Page.navigate', {url});
    await loaded;
    await this.settle();
  }

  // Waits for Craft's control panel scripts to have set the page up.
  async settle() {
    await this.waitFor('document.readyState === "complete"');
    await sleep(400);
  }

  async eval(expression) {
    const {result, exceptionDetails} = await this.send('Runtime.evaluate', {expression: `(async () => { ${expression} })()`, awaitPromise: true, returnByValue: true});

    if (exceptionDetails) {
      throw new Error(exceptionDetails.exception?.description || exceptionDetails.text);
    }

    return result.value;
  }

  async waitFor(condition, timeout = 15000) {
    const start = Date.now();

    while (Date.now() - start < timeout) {
      if (await this.eval(`return !!(${condition});`)) {
        return true;
      }

      await sleep(150);
    }

    throw new Error(`Timed out waiting for: ${condition}`);
  }

  // A real mouse click at the element's centre, for widgets that listen for mouse down and up
  // (Garnish's selection) rather than a click event.
  async mouseClick(elementExpression) {
    const box = await this.eval(`const el = ${elementExpression}; el.scrollIntoView({block: 'center'}); const r = el.getBoundingClientRect(); return {x: r.left + r.width / 2, y: r.top + r.height / 2};`);

    for (const type of ['mouseMoved', 'mousePressed', 'mouseReleased']) {
      await this.send('Input.dispatchMouseEvent', {type, x: box.x, y: box.y, button: 'left', clickCount: type === 'mouseMoved' ? 0 : 1});
    }
  }

  // Clicks something that makes the page navigate, e.g. the editor's Save button.
  async clickAndLoad(selector) {
    const loaded = this.loaded();
    await this.eval(`document.querySelector(${JSON.stringify(selector)}).click();`);
    await loaded;
    await this.settle();
  }
}

// What the test does in the page, as small pieces of page script.

const FIELD = 'smartLinksCpLinks';
const field = (handle = FIELD) => `document.querySelector('[data-attribute="${handle}"] [data-smartlinks-field]')`;
const links = (handle = FIELD) => `[...${field(handle)}.querySelectorAll(':scope [data-smartlinks-links] > [data-smartlinks-link]')]`;
const linkByKey = (key, handle = FIELD) => `${field(handle)}.querySelector('[data-smartlinks-link][data-key="${key}"]')`;
// Garnish modals are fixed-position, so visibility is read from the class and the computed style.
const modal = `[...document.querySelectorAll('.modal.elementselectormodal:not(.hidden)')].filter((m) => getComputedStyle(m).display !== 'none').pop()`;
const js = (value) => JSON.stringify(value);

function setValue(scope, selector, value) {
  return `{
    const el = ${scope}.querySelector(${js(selector)});
    if (!el) throw new Error('No input ' + ${js(selector)});
    el.value = ${js(value)};
    el.dispatchEvent(new Event('input', {bubbles: true}));
    el.dispatchEvent(new Event('change', {bubbles: true}));
  }`;
}

async function addLink(page, type, handle = FIELD) {
  const before = await page.eval(`return ${links(handle)}.length;`);
  await page.eval(`${field(handle)}.querySelector('[data-smartlinks-add]').click();`);
  await page.waitFor(`${links(handle)}.length === ${before + 1}`);
  const key = await page.eval(`return ${links(handle)}[${before}].getAttribute('data-key');`);
  await page.eval(`{ const select = ${linkByKey(key, handle)}.querySelector('select[data-smartlinks-type]'); if (select) { select.value = ${js(type)}; select.dispatchEvent(new Event('change', {bubbles: true})); } }`);
  await page.waitFor(`!${linkByKey(key, handle)}.querySelector('[data-smartlinks-data="${type}"]').classList.contains('hidden')`);

  return key;
}

function data(key, type, handle = FIELD) {
  return `${linkByKey(key, handle)}.querySelector('[data-smartlinks-data="${type}"]')`;
}

// Chooses an element through Craft's own element selector modal, as an author does. The modal
// is the one Craft's element select opened, so a previous picker still fading out is never used.
async function pick(page, key, type, source, id) {
  await page.eval(`${data(key, type)}.querySelector('.elementselect button.add').click();`);
  const modal = `$(${data(key, type)}.querySelector('.elementselect')).data('elementSelect')?.modal?.$container?.[0]`;
  await page.waitFor(`${modal} && !${modal}.classList.contains('hidden') && getComputedStyle(${modal}).display !== 'none'`);

  if (source) {
    const sourceItem = `${modal}.querySelector('.sidebar [data-key="${source}"]')`;
    await page.waitFor(sourceItem);
    await page.eval(`${sourceItem}.click();`);
    await page.waitFor(`${modal}?.querySelector('.sidebar .sel')?.getAttribute('data-key') === ${js(source)}`);
  }

  // The index reloads for the chosen source; a row selected before it settles is replaced.
  const row = `${modal}.querySelector('tr[data-id="${id}"], li[data-id="${id}"], .element[data-id="${id}"]')`;
  await page.waitFor(row, 20000);
  await sleep(1000);

  for (let attempt = 0; attempt < 5 && !(await page.eval(`return !!${row}?.closest('.sel, [aria-selected="true"]') || ${row}?.classList.contains('sel');`)); attempt++) {
    await page.mouseClick(`${row}.querySelector('.checkbox') || ${row}`);
    await sleep(400);
  }
  // The footer's Select button; Craft's "New entry" menu button there is a `.submit` too.
  const select = `${modal}.querySelector('.footer button[type="submit"].submit')`;

  try {
    await page.waitFor(`!${select}.classList.contains('disabled')`);
  } catch (error) {
    const state = await page.eval(`const m = ${modal}; return {source: m.querySelector('.sidebar .sel')?.getAttribute('data-key'), row: ${row}?.outerHTML.slice(0, 600), selected: [...m.querySelectorAll('.sel')].map((el) => el.tagName + (el.getAttribute('data-id') || el.getAttribute('data-key') || '')).slice(0, 10)};`);
    throw new Error(`${error.message}\n${JSON.stringify(state, null, 2)}`);
  }
  await page.eval(`${select}.click();`);

  try {
    await page.waitFor(`${data(key, type)}.querySelector('.elementselect .chip[data-id="${id}"], .elementselect .element[data-id="${id}"]')`);
  } catch (error) {
    const state = await page.eval(`return {select: ${data(key, type)}.querySelector('.elementselect').outerHTML.slice(0, 1500), input: ${data(key, type)}.querySelector('input[name$="[elementId]"]').value, modalOpen: !!${modal}, selected: ${modal}?.querySelectorAll('.sel').length ?? null};`);
    throw new Error(`${error.message}\n${JSON.stringify(state, null, 2)}\nScript errors: ${page.errors.join('\n')}`);
  }
}

async function menu(page, key, action) {
  await page.eval(`{
    const item = document.getElementById('fields-${FIELD}-${key}-${action}');
    if (!item) throw new Error('No ${action} for ${key}');
    item.click();
  }`);
}

async function save(page) {
  await page.clickAndLoad('#main-form button[type="submit"].submit');
}

// Waits for Craft's element editor to have saved the page's changes as a provisional draft.
async function autosaved(page, content) {
  for (let i = 0; i < 40; i++) {
    const state = fixture('inspect', String(content.page.id), content.primarySite.handle);

    if (state.provisional) {
      return state;
    }

    await sleep(500);
  }

  throw new Error('No provisional draft was saved.');
}

// Submits the page's full-page form, as its Save button does, and waits for the next page.
async function submitForm(page) {
  const loaded = page.loaded();
  await page.eval(`document.querySelector('#main-form').requestSubmit();`);
  await loaded;
  await page.settle();
}

// What project config has stored for one preset, by setting.
function storedPreset(uid) {
  const prefix = `smartLinks.presets.${uid}.`;
  const stored = {};

  for (const [path, value] of Object.entries(fixture('presets'))) {
    if (path.startsWith(prefix)) {
      stored[path.slice(prefix.length)] = JSON.parse(value);
    }
  }

  return stored;
}

function presetUidNamed(name) {
  const path = Object.entries(fixture('presets')).find(([path, value]) => path.endsWith('.name') && JSON.parse(value) === name)?.[0];

  return path ? path.split('.')[2] : null;
}

function presetOrder() {
  return Object.entries(fixture('presets')).filter(([path]) => path.endsWith('.sortOrder')).sort((a, b) => Number(a[1]) - Number(b[1])).map(([path]) => path.split('.')[2]);
}

// Teardown commands say what they found even when they find something wrong.
function fixtureResult(...args) {
  const run = spawnSync('ddev', ['exec', '-d', '/var/www/html/plugins/Smart-Links', 'php', 'tests/cp/fixture.php', ...args], {encoding: 'utf8'});

  try {
    return JSON.parse(run.stdout.trim().split('\n').pop());
  } catch {
    return {failed: `${run.stderr}${run.stdout}`};
  }
}

// Managing presets through the real control panel, as an admin: create, edit, disable and enable,
// refuse, reorder, and delete, each checked against what project config stored.
async function managePresets(admin, content) {
  const name = 'Smart Links CP UI preset';
  const form = 'document.querySelector("#main-form")';
  const originalOrder = presetOrder();

  await admin.goto(`${content.cpUrl}/smart-links/presets/new`);
  await admin.eval(`{
    const f = ${form};
    f.querySelector('[name="name"]').value = ${js(name)};
    f.querySelector('[name="types[]"][value="url"]').checked = true;
    f.querySelector('[name="attributes[target]"]').value = '_blank';
    f.querySelector('[name="attributes[class]"]').value = 'ui-cta';
    f.querySelector('[name="locked[]"][value="target"]').checked = true;
  }`);
  await submitForm(admin);
  const uid = presetUidNamed(name);
  check('a preset created in the control panel is stored in project config as entered', uid !== null && js(storedPreset(uid)) === js({'attributes.class.0': 'ui-cta', 'attributes.target': '_blank', enabled: true, 'locked.0': 'target', name, sortOrder: originalOrder.length + 1, 'types.0': 'url'}), js(uid && storedPreset(uid)));
  check('saving returns to the list, which shows the new preset and says it was saved', new URL(await admin.eval('return location.href;')).pathname.endsWith('/smart-links/presets') && await admin.eval(`return [...document.querySelectorAll('#smartlinks-presets-table tbody tr')].some((row) => row.innerText.includes(${js(name)}));`) && await admin.eval(`return document.body.innerText.includes('Preset saved.');`));

  if (uid === null) {
    return;
  }

  await admin.goto(`${content.cpUrl}/smart-links/presets/${uid}`);
  await admin.eval(`${form}.querySelector('[name="attributes[class]"]').value = 'ui-cta ui-big';`);
  await submitForm(admin);
  await admin.goto(`${content.cpUrl}/smart-links/presets/${uid}`);
  check('an edit is stored, and shown after reloading', storedPreset(uid)['attributes.class.1'] === 'ui-big' && await admin.eval(`return ${form}.querySelector('[name="attributes[class]"]').value === 'ui-cta ui-big';`));

  for (const enabled of [false, true]) {
    await admin.goto(`${content.cpUrl}/smart-links/presets/${uid}`);
    await admin.eval(`$('#enabled').data('lightswitch').${enabled ? 'turnOn' : 'turnOff'}();`);
    await submitForm(admin);
    await admin.goto(`${content.cpUrl}/smart-links/presets/${uid}`);
    check(`${enabled ? 'enabling' : 'disabling'} a preset is stored, and shown after reloading`, storedPreset(uid).enabled === enabled && await admin.eval(`return ${form}.querySelector('input[name="enabled"]').value === ${js(enabled ? '1' : '')};`), js(storedPreset(uid)));
  }

  // A refused save stores nothing and says why, on the form.
  await admin.goto(`${content.cpUrl}/smart-links/presets/new`);
  await admin.eval(`${form}.querySelector('[name="name"]').value = ${js(content.existingName.toUpperCase())}; ${form}.querySelector('[name="attributes[rel]"]').value = 'no/follow';`);
  await submitForm(admin);
  check('a duplicate name and an invalid rel are refused on the form, and nothing is stored', await admin.eval(`return document.body.innerText.includes('Another preset is already called') && document.body.innerText.includes('is not a valid rel value');`) && presetOrder().length === originalOrder.length + 1);

  // The list's own reorder request, with the user's session and CSRF token.
  const reversed = [...presetOrder()].reverse();
  await admin.goto(`${content.cpUrl}/smart-links/presets`);
  await admin.eval(`await Craft.sendActionRequest('POST', 'smart-links/presets/reorder', {data: {ids: ${js(JSON.stringify(reversed))}}});`);
  await admin.goto(`${content.cpUrl}/smart-links/presets`);
  const shownOrder = await admin.eval(`return [...document.querySelectorAll('#smartlinks-presets-table tbody tr')].map((row) => row.innerText.trim().split('\\n')[0].trim());`);
  check('a new order is stored, and shown after reloading', js(presetOrder()) === js(reversed) && shownOrder[0].includes(name), js(shownOrder));
  await admin.eval(`await Craft.sendActionRequest('POST', 'smart-links/presets/reorder', {data: {ids: ${js(JSON.stringify([...originalOrder, uid]))}}});`);

  // Deleting asks first: declined, nothing goes; accepted, the preset goes.
  await admin.goto(`${content.cpUrl}/smart-links/presets`);
  const row = `[...document.querySelectorAll('#smartlinks-presets-table tbody tr')].find((row) => row.innerText.includes(${js(name)}))`;
  await admin.eval(`window.confirmed = []; window.confirm = (message) => { window.confirmed.push(message); return false; }; (${row}).querySelector('.delete').click();`);
  await sleep(1000);
  check('deleting asks for confirmation, and declining keeps the preset', await admin.eval('return window.confirmed.length === 1 && window.confirmed[0].includes("Links made with it keep it");') && presetUidNamed(name) === uid);
  await admin.eval(`window.confirm = () => true; (${row}).querySelector('.delete').click();`);
  await admin.waitFor(`!(${row})`);
  await admin.goto(`${content.cpUrl}/smart-links/presets`);
  check('a deleted preset is gone from project config and the list, and the order is as it was', presetUidNamed(name) === null && !(await admin.eval(`return !!(${row});`)) && js(presetOrder()) === js(originalOrder), js(presetOrder()));
}

// Presets for a user with control panel access to Smart Links but not the preset permission, then
// with it: refused at every page and action, then able to manage presets.
// Presets for a user with control panel access to Smart Links but not the preset permission, then
// with it: refused at every page and action, then able to manage presets.
async function presetPermissions(author, content) {
  const before = js(fixture('presets'));
  const status = (method, path, data) => author.eval(`
    const body = new URLSearchParams(${js(data ?? {})});
    body.append(Craft.csrfTokenName, Craft.csrfTokenValue);
    const response = await fetch(${js(path)}, {method: ${js(method)}, headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, body: ${js(method)} === 'GET' ? undefined : body, redirect: 'manual'});
    return response.status;`);
  const everything = async () => ({
    list: await status('GET', `${content.cpUrl}/smart-links/presets`),
    form: await status('GET', `${content.cpUrl}/smart-links/presets/new`),
    edit: await status('GET', `${content.cpUrl}/smart-links/presets/${content.presets[0]}`),
    create: await status('POST', `${content.cpUrl}/actions/smart-links/presets/save`, {presetUid: '', name: 'By the author', enabled: '1', types: '', urlSuffix: '', attributes: '', locked: ''}),
    save: await status('POST', `${content.cpUrl}/actions/smart-links/presets/save`, {presetUid: content.presets[0], name: 'Renamed by the author', enabled: '', types: '', urlSuffix: '', attributes: '', locked: ''}),
    delete: await status('POST', `${content.cpUrl}/actions/smart-links/presets/delete`, {id: content.presets[0]}),
    reorder: await status('POST', `${content.cpUrl}/actions/smart-links/presets/reorder`, {ids: JSON.stringify([...presetOrder()].reverse())}),
  });
  const navOffers = (path) => author.eval(`return [...document.querySelectorAll('#global-sidebar a')].some((a) => a.href && new URL(a.href).pathname.endsWith(${js(path)}));`);

  // Control panel access only, then with access to the Smart Links section: every presets page
  // and action is refused either way, by Craft's section gate or by the presets controller.
  for (const stage of ['control panel access only', 'access to the Smart Links section']) {
    if (stage !== 'control panel access only') {
      fixture('grant', String(content.authorId), 'section');
    }

    await author.goto(content.cpUrl);
    const refused = await everything();
    check(`with ${stage}, every presets page and action is refused, and nothing changes`, Object.values(refused).every((code) => code === 403) && js(fixture('presets')) === before, js(refused));
    check(`with ${stage}, the nav offers no presets page${stage === 'control panel access only' ? ' and no Smart Links section' : ''}`, !(await navOffers('/smart-links/presets')) && (stage !== 'control panel access only' || !(await navOffers('/smart-links'))));
  }

  fixture('grant', String(content.authorId), 'presets');
  await author.goto(`${content.cpUrl}/smart-links/presets`);
  check('with the permission, a user who is not an admin gets the presets page', await author.eval(`return !!document.querySelector('#smartlinks-presets-table');`) && await navOffers('/smart-links/presets'));
  await author.goto(`${content.cpUrl}/smart-links/presets/new`);
  await author.eval(`document.querySelector('#main-form [name="name"]').value = 'By the author';`);
  await submitForm(author);
  const uid = presetUidNamed('By the author');
  check('with the permission, they can create a preset', uid !== null);

  if (uid !== null) {
    check('and delete it', await status('POST', `${content.cpUrl}/actions/smart-links/presets/delete`, {id: uid}) === 200 && presetUidNamed('By the author') === null);
  }
}

// The presets phase, before any other content exists (see fixture.php): the presets pages and
// actions, as an admin and as a user with and without the preset permission, then everything put
// back as it was.
async function presetsPhase(browser) {
  console.log('Managing presets in the control panel…');
  const content = fixture('presets-setup');
  const names = Object.entries(fixture('presets')).filter(([path]) => path.endsWith('.name')).map(([, value]) => JSON.parse(value));
  content.existingName = names[0];

  try {
    check('the project has a preset to compare names with', names.length > 0);
    const admin = await browser.newPage();
    await admin.goto(fixture('impersonate', String(content.adminId)).url);
    await managePresets(admin, content);
    check('no script errors on the presets pages', admin.errors.length === 0, admin.errors.join('\n'));

    if (content.authorId) {
      const author = await browser.newPage();
      await author.goto(fixture('impersonate', String(content.authorId)).url);
      await presetPermissions(author, content);
    } else {
      check('a user without the preset permission could be created', false, 'The host refused to save a test user.');
    }
  } catch (error) {
    console.error(`\nThe presets phase stopped: ${error.stack || error}`);
    throw error;
  } finally {
    const teardown = fixtureResult('presets-teardown');
    check('managing presets leaves the project as it was (its files differed only in the time of the last change, which is put back)', teardown.restored === true && teardown.userRemoved === true, js(teardown));
    check('Craft sees no project config changes pending after managing presets', fixture('pending').pending === false);
  }
}

// Link presets: the pages that manage them, as the real control panel renders them, and a preset
// chosen in a real entry.
async function presets(admin, content) {
  await admin.goto(`${content.cpUrl}/smart-links/presets`);
  const row = `[...document.querySelectorAll('#smartlinks-presets-table tbody tr')].find((row) => row.innerText.includes(${js(content.preset.name)}))`;

  try {
    await admin.waitFor(row);
  } catch {
    // Reported by the checks below.
  }

  check('the presets page lists the presets, in Craft’s admin table', await admin.eval(`return !!(${row});`));
  check('a preset’s row says what it is for and what it sets and locks', await admin.eval(`const text = (${row})?.innerText ?? ''; return text.includes('URL') && text.includes('Target (locked)') && text.includes('CSS classes');`), await admin.eval(`return (${row})?.innerText ?? '';`));
  check('presets can be deleted from the list, after confirming', await admin.eval(`return !!(${row})?.querySelector('.delete, [data-icon="remove"], button[title]');`));
  check('the section’s nav offers the presets page', await admin.eval(`return [...document.querySelectorAll('#global-sidebar a')].some((a) => a.href && new URL(a.href).pathname.endsWith('/smart-links/presets'));`), await admin.eval(`return [...document.querySelectorAll('#global-sidebar a')].map((a) => a.getAttribute('href')).filter((href) => href?.includes('smart-links')).join(' ');`));

  await admin.goto(`${content.cpUrl}/smart-links/presets/${content.preset.uid}`);
  const form = 'document.querySelector("#main-form")';
  const shown = await admin.eval(`const f = ${form}; return f && {
    name: f.querySelector('[name="name"]').value,
    types: [...f.querySelectorAll('[name="types[]"]:checked')].map((i) => i.value),
    target: f.querySelector('[name="attributes[target]"]').value,
    cls: f.querySelector('[name="attributes[class]"]').value,
    locked: [...f.querySelectorAll('[name="locked[]"]:checked')].map((i) => i.value),
  };`);
  check('the edit page shows the preset as defined', js(shown) === js({name: content.preset.name, types: ['url'], target: '_blank', cls: 'cp-cta', locked: ['target']}), js(shown));
  // Exactly what the controller reads, and nothing it does not.
  const posted = await admin.eval(`return [...new Set([...new FormData(${form}).keys()].map((key) => key.split('[')[0]))].sort();`);
  const expected = ['CRAFTCMS_CSRF_TOKEN', 'action', 'attributes', 'enabled', 'locked', 'name', 'presetUid', 'redirect', 'types', 'urlSuffix'];
  check('the edit form posts exactly the settings the controller reads', js(posted.filter((key) => !/csrf/i.test(key))) === js(expected.filter((key) => !/csrf/i.test(key))) && posted.some((key) => /csrf/i.test(key)), js(posted));
  check('the edit form saves through the presets controller', await admin.eval(`return ${form}.querySelector('[name="action"]').value === 'smart-links/presets/save' && ${form}.querySelector('[name="presetUid"]').value === ${js(content.preset.uid)};`));

  await admin.goto(`${content.cpUrl}/smart-links/presets/new`);
  check('the new preset page starts empty and enabled', await admin.eval(`const f = ${form}; return f.querySelector('[name="name"]').value === '' && f.querySelector('[name="presetUid"]').value === '' && f.querySelector('input[name="enabled"]').value === '1';`));

  // An author choosing the preset in an entry: the editor fills in what it sets and holds what
  // it locks, and the link is saved with them.
  await admin.goto(content.presetPage.editUrl);
  const key = await addLink(admin, 'url');
  await admin.eval(setValue(data(key, 'url'), 'input[name$="[data][url][url]"]', 'https://example.com/campaign'));
  await admin.eval(`{ const select = ${linkByKey(key)}.querySelector('[data-smartlinks-preset]'); select.value = ${js(content.preset.uid)}; select.dispatchEvent(new Event('change', {bubbles: true})); }`);
  check('choosing the preset fills in its settings and locks its target', await admin.eval(`const link = ${linkByKey(key)}; return link.querySelector('select[name$="[attributes][target]"]').value === '_blank' && link.querySelector('select[name$="[attributes][target]"]').disabled && link.querySelector('input[name$="[attributes][class]"]').value === 'cp-cta' && link.querySelector('[data-smartlinks-lock-note]')?.textContent === 'Set by the “${content.preset.name}” preset.';`), await admin.eval(`const link = ${linkByKey(key)}; return JSON.stringify({target: link.querySelector('select[name$="[attributes][target]"]').value, disabled: link.querySelector('select[name$="[attributes][target]"]').disabled, cls: link.querySelector('input[name$="[attributes][class]"]').value, note: link.querySelector('[data-smartlinks-lock-note]')?.textContent});`));
  await save(admin);
  const stored = fixture('inspect', String(content.presetPage.id), content.primarySite.handle).canonical.smartLinksCpLinks?.links?.[0];
  check('the link is saved with the preset and what it set', stored?.presetUid === content.preset.uid && js(stored?.attributes) === js({target: '_blank', class: ['cp-cta']}), js(stored));

  // Disabled since: the link keeps it, a new link is not offered it, and a forged one is refused
  // by the server when the entry is saved.
  fixture('set-preset-enabled', content.preset.uid, '0');

  try {
    await admin.goto(content.presetPage.editUrl);
    const keptKey = await admin.eval(`return ${links()}[0].getAttribute('data-key');`);
    check('a link keeps a preset disabled since, named as disabled', await admin.eval(`const select = ${linkByKey(keptKey)}.querySelector('[data-smartlinks-preset]'); return select.value === ${js(content.preset.uid)} && select.selectedOptions[0].textContent.includes('(disabled)');`));
    const newKey = await addLink(admin, 'url');
    check('a disabled preset is not offered for a new link', !(await admin.eval(`return [...${linkByKey(newKey)}.querySelectorAll('[data-smartlinks-preset] option')].some((option) => option.value === ${js(content.preset.uid)});`)));
    await admin.eval(setValue(data(newKey, 'url'), 'input[name$="[data][url][url]"]', 'https://example.com/forged'));
    // An input the page never offered, named as the link's own preset input would be.
    await admin.eval(`{
      const link = ${linkByKey(newKey)};
      link.querySelector('[data-smartlinks-preset]')?.remove();
      const forged = document.createElement('input');
      forged.type = 'hidden';
      forged.name = link.querySelector('input[name$="[uid]"]').name.replace(/\\[uid\\]$/, '[presetUid]');
      forged.value = ${js(content.preset.uid)};
      link.append(forged);
    }`);
    await save(admin);
    const live = fixture('inspect', String(content.presetPage.id), content.primarySite.handle).canonical.smartLinksCpLinks?.links ?? [];
    check('a disabled preset forged onto a new link is refused when the entry is saved, and the live entry is unchanged', await admin.eval(`return document.body.innerText.includes('preset is disabled, so no link can be given it');`) && live.length === 1 && live[0].presetUid === content.preset.uid, js(live));
  } finally {
    fixture('set-preset-enabled', content.preset.uid, '1');
  }
}

// The link inventory, as the real control panel serves it: who may see it and rebuild the index,
// its table, search, filters, sorting and pages, malformed requests, encoding, and a rebuild run
// through Craft's own queue.
async function linksPhase(browser, content) {
  const indexed = fixture('index');
  check('the run’s content is indexed without problems', indexed.problems.length === 0 && indexed.stale === false, JSON.stringify(indexed));

  const linksUrl = `${content.cpUrl}/smart-links/links`;
  const rows = 'document.querySelectorAll("#smartlinks-inventory tbody tr")';
  const urls = `[...${rows}].map((row) => row.querySelector('th a.go, th code')?.textContent.trim())`;
  const status = (page, method, path) => page.eval(`
    const body = new URLSearchParams();
    body.append(Craft.csrfTokenName, Craft.csrfTokenValue);
    const response = await fetch(${js(path)}, {method: ${js(method)}, headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, body: ${js(method)} === 'GET' ? undefined : body, redirect: 'manual'});
    return response.status;`);
  const navOffers = (page) => page.eval(`return [...document.querySelectorAll('#global-sidebar a')].some((a) => a.href && new URL(a.href).pathname.endsWith('/smart-links/links'));`);
  const pages = [];

  // A user of the section without the inventory permission: no page, no nav item.
  const outsider = await browser.newPage();
  pages.push(outsider);
  await outsider.goto(fixture('impersonate', String(content.inventory.outsider)).url);
  await outsider.goto(`${content.cpUrl}/smart-links`);
  check('without the inventory permission, the nav offers no Links page', !(await navOffers(outsider)));
  check('without the inventory permission, the Links page and the rebuild are refused', await status(outsider, 'GET', linksUrl) === 403 && await status(outsider, 'POST', `${content.cpUrl}/actions/smart-links/links/rebuild`) === 403);

  // A viewer: the page, its table, and everything but rebuilding.
  const viewer = await browser.newPage();
  pages.push(viewer);
  await viewer.goto(fixture('impersonate', String(content.inventory.viewer)).url);
  await viewer.goto(linksUrl);
  check('with the inventory permission, the nav offers the Links page', await navOffers(viewer));
  check('the Links page renders the inventory table, a page of 50', await viewer.eval(`return ${rows}.length === 50 && !!document.querySelector('#smartlinks-inventory-pages .next-page');`));
  check('a viewer is not offered a rebuild', !(await viewer.eval(`return !!document.querySelector('#smartlinks-index-actions form');`)));
  const queuedBefore = fixture('index-status').queuedRebuilds;
  check('a viewer posting a rebuild directly is refused, and nothing is queued', await status(viewer, 'POST', `${content.cpUrl}/actions/smart-links/links/rebuild`) === 403 && fixture('index-status').queuedRebuilds === queuedBefore);
  check('a rebuild cannot be asked for with GET', await status(viewer, 'GET', `${content.cpUrl}/actions/smart-links/links/rebuild`) >= 400 && fixture('index-status').queuedRebuilds === queuedBefore);

  // Search, through the page's own form.
  await viewer.eval(`document.querySelector('#smartlinks-inventory-filters [name="search"]').value = 'inventory.example.test/page-0';`);
  let loaded = viewer.loaded();
  await viewer.eval(`document.querySelector('#smartlinks-inventory-filters').requestSubmit();`);
  await loaded;
  await viewer.settle();
  const searched = await viewer.eval(`return ${urls};`);
  check('search finds the links it names', searched.length === 9 && searched.every((url) => url.startsWith('https://inventory.example.test/page-0')) && new URL(await viewer.eval('return location.href;')).searchParams.get('search') === 'inventory.example.test/page-0', JSON.stringify(searched));

  // Each filter, through the form's own controls.
  const filtered = async (params) => {
    await viewer.goto(linksUrl);
    await viewer.eval(`
      const form = document.querySelector('#smartlinks-inventory-filters');
      for (const [name, value] of Object.entries(${js(params)})) {
        form.querySelector('[name="' + name + '"]').value = value;
      }`);
    const loaded = viewer.loaded();
    await viewer.eval(`document.querySelector('#smartlinks-inventory-filters').requestSubmit();`);
    await loaded;
    await viewer.settle();

    return viewer.eval(`return ${urls};`);
  };
  const inSecondSite = (await filtered({search: 'inventory.example.test', sourceSite: content.inventory.secondSite})).sort();
  check('the site filter counts the site the links are in', js(inSecondSite) === js(['https://inventory.example.test/one-field', 'https://inventory.example.test/second-only']), js(inSecondSite));
  check('the link type filter shows that type only', js(await filtered({search: 'inventory', type: 'email'})) === js(['mailto:inventory@example.test']));
  check('the source filter shows that field’s links only', js(await filtered({search: 'inventory.example.test', source: 'smartLinksCpOne'})) === js(['https://inventory.example.test/one-field']));
  check('the health filter shows what was observed', js(await filtered({search: 'inventory', health: 'broken'})) === js(['https://inventory.example.test/page-01']));
  check('a target that can’t be checked is filtered as such', js(await filtered({search: 'inventory', health: 'none'})) === js(['mailto:inventory@example.test']));
  const missing = await filtered({target: 'missing'});
  check('the target filter shows targets that don’t exist', missing.length > 0 && await viewer.eval(`return [...${rows}].every((row) => row.querySelector('[data-target]').dataset.target === 'missing');`), JSON.stringify(missing));

  // Sorting by a header link, both ways, and pages.
  await viewer.goto(`${linksUrl}?search=inventory.example.test%2Fpage-`);
  loaded = viewer.loaded();
  await viewer.eval(`[...document.querySelectorAll('#smartlinks-inventory thead a')].find((a) => new URL(a.href).searchParams.get('sort') === 'link').click();`);
  await loaded;
  await viewer.settle();
  const ascending = await viewer.eval(`return ${urls};`);
  check('sorting by link puts them in order', ascending.length === 50 && ascending[0] === 'https://inventory.example.test/page-01' && await viewer.eval(`return document.querySelector('#smartlinks-inventory thead [aria-sort="ascending"]') !== null;`), JSON.stringify(ascending.slice(0, 3)));
  await viewer.clickAndLoad('#smartlinks-inventory-pages .next-page');
  check('the next page holds the rest, and leads back', js(await viewer.eval(`return ${urls};`)) === js(['https://inventory.example.test/page-51']) && await viewer.eval(`return !!document.querySelector('#smartlinks-inventory-pages .prev-page') && document.querySelector('#smartlinks-inventory-pages .page-info').textContent.includes('51');`));
  loaded = viewer.loaded();
  await viewer.eval(`[...document.querySelectorAll('#smartlinks-inventory thead a')].find((a) => new URL(a.href).searchParams.get('sort') === 'link').click();`);
  await loaded;
  await viewer.settle();
  check('sorting again reverses it, from the first page', (await viewer.eval(`return ${urls};`))[0] === 'https://inventory.example.test/page-51');

  // A label written as markup is text.
  await viewer.goto(`${linksUrl}?search=marked-up`);
  check('a label written as markup is shown as text', await viewer.eval(`const cell = document.querySelector('#smartlinks-inventory tbody tr td:nth-of-type(2)'); return cell.textContent.includes('<b>Bold</b> & "quoted"') && !document.querySelector('#smartlinks-inventory tbody b');`));

  // Malformed requests are refused, never shown as everything.
  const malformed = {};

  for (const query of ['sort=nonsense', 'sourceSite=noSuchSite', 'page=0', 'health=great', 'type=Not%20A%20Type', 'source=title']) {
    malformed[query] = await status(viewer, 'GET', `${linksUrl}?${query}`);
  }

  check('malformed filters, sorts and pages are refused', Object.values(malformed).every((code) => code === 400), js(malformed));
  await viewer.goto(`${linksUrl}?site=noSuchSite&search=inventory.example.test%2Fpage-`);
  check('Craft’s own site parameter is not a filter', await viewer.eval(`return ${rows}.length === 50;`));

  // Where a target is used: a permission of its own, nested under the inventory's.
  const usageLinks = 'document.querySelectorAll("#smartlinks-inventory tbody a[href*=\\"/smart-links/links/\\"]")';
  await viewer.goto(`${linksUrl}?search=inventory.example.test%2Fone-field`);
  const targetId = await viewer.eval('return document.querySelector("#smartlinks-inventory tbody tr")?.dataset.id;');
  check('without the usage permission, the inventory leads nowhere else, and the usage page is refused', targetId && await viewer.eval(`return ${usageLinks}.length === 0;`) && await status(viewer, 'GET', `${linksUrl}/${targetId}`) === 403, String(targetId));

  const usageViewer = await browser.newPage();
  pages.push(usageViewer);
  await usageViewer.goto(fixture('impersonate', String(content.inventory.rebuilder)).url);
  await usageViewer.goto(`${linksUrl}?search=inventory.example.test%2Fone-field`);
  const counted = await usageViewer.eval(`return Number(${usageLinks}[0]?.textContent.trim());`);
  await usageViewer.clickAndLoad('#smartlinks-inventory tbody a[href*="/smart-links/links/"]');
  const usageRows = 'document.querySelectorAll("#smartlinks-usage tbody tr")';
  check('with it, the usage count leads to the target’s page, listing every occurrence', new URL(await usageViewer.eval('return location.href;')).pathname.endsWith(`/smart-links/links/${targetId}`) && counted > 0 && await usageViewer.eval(`return ${usageRows}.length;`) === counted, js({counted, shown: await usageViewer.eval(`return ${usageRows}.length;`)}));
  check('each occurrence names its source, element type, field and site', await usageViewer.eval(`return [...${usageRows}].every((row) => row.querySelector('th').textContent.includes('CP inventory') && row.textContent.includes('Entry') && row.textContent.includes('smartLinksCpOne') && row.querySelector('[data-stale]').dataset.stale === 'current');`), await usageViewer.eval(`return ${usageRows}[0]?.innerText ?? '';`));
  check('the page names the target and leads back to the inventory', await usageViewer.eval(`return document.querySelector('#smartlinks-target').textContent.includes('https://inventory.example.test/one-field') && [...document.querySelectorAll('#crumb-list a')].some((a) => new URL(a.href).pathname.endsWith('/smart-links/links'));`));
  await usageViewer.eval(`document.querySelector('#smartlinks-usage-filters [name="sourceSite"]').value = ${js(content.inventory.secondSite)};`);
  loaded = usageViewer.loaded();
  await usageViewer.eval(`document.querySelector('#smartlinks-usage-filters').requestSubmit();`);
  await loaded;
  await usageViewer.settle();
  check('the site filter shows that site’s occurrences only', await usageViewer.eval(`return ${usageRows}.length === 1 && new URL(location.href).searchParams.get('sourceSite') === ${js(content.inventory.secondSite)};`), await usageViewer.eval(`return ${usageRows}.length;`));
  const usageRefusals = {malformed: await status(usageViewer, 'GET', `${linksUrl}/${targetId}?page=0`), unknown: await status(usageViewer, 'GET', `${linksUrl}/999999999`)};
  check('a malformed usage page is refused, and an unknown target is not found', usageRefusals.malformed === 400 && usageRefusals.unknown === 404, js(usageRefusals));

  // Where links are used, through more of the page: pages, filters, nesting, edit pages, and
  // what the index has not caught up with yet.
  const usage = fixture('usage-setup');
  const usagePage = (indexId, query = '') => `${linksUrl}/${indexId}${query}`;
  const usageInfo = () => usageViewer.eval('return document.querySelector("#smartlinks-usage-pages .page-info")?.textContent.trim() ?? "";');
  const usageKeys = () => usageViewer.eval(`return [...${usageRows}].map((row) => row.dataset.usage);`);
  const submitUsageFilters = async (values) => {
    await usageViewer.eval(`for (const [name, value] of Object.entries(${js(values)})) { document.querySelector('#smartlinks-usage-filters [name="' + name + '"]').value = value; }`);
    const filtered = usageViewer.loaded();
    await usageViewer.eval(`document.querySelector('#smartlinks-usage-filters').requestSubmit();`);
    await filtered;
    await usageViewer.settle();
  };

  // One target used 56 times in each site's copy: 50 a page, every one once.
  await usageViewer.goto(usagePage(usage.many));
  const seen = [];
  let pageCount = 0;

  while (true) {
    seen.push(...await usageViewer.eval(`return [...${usageRows}].map((row) => row.dataset.usage);`));
    pageCount++;

    if (!(await usageViewer.eval('return !!document.querySelector("#smartlinks-usage-pages .next-page");'))) {
      break;
    }

    await usageViewer.clickAndLoad('#smartlinks-usage-pages .next-page');
  }

  const total = Number((await usageInfo()).match(/of ([\d,]+)/)?.[1].replace(/,/g, ''));
  check('every occurrence is on exactly one page, 50 a page, through the next-page links', seen.length === total && new Set(seen).size === total && pageCount === Math.ceil(total / 50) && total >= 112, js({seen: seen.length, unique: new Set(seen).size, total, pageCount}));
  await usageViewer.clickAndLoad('#smartlinks-usage-pages .prev-page');
  check('the previous-page link leads back', (await usageInfo()).startsWith(`${(pageCount - 2) * 50 + 1}–`), await usageInfo());

  await usageViewer.goto(usagePage(usage.many));
  await submitUsageFilters({source: 'smartLinksCpOne'});
  check('the field filter shows that field’s occurrences only', await usageViewer.eval(`return [...${usageRows}].every((row) => row.textContent.includes('smartLinksCpOne'));`) && (await usageKeys()).length > 0 && (await usageKeys()).length < 50, js(await usageKeys()));
  await usageViewer.goto(usagePage(usage.many));
  await submitUsageFilters({sourceSite: content.inventory.secondSite, source: 'smartLinksCpLinks'});
  await usageViewer.clickAndLoad('#smartlinks-usage-pages .next-page');
  const kept = new URL(await usageViewer.eval('return location.href;')).searchParams;
  check('changing page keeps both filters', kept.get('sourceSite') === content.inventory.secondSite && kept.get('source') === 'smartLinksCpLinks' && kept.get('page') === '2', kept.toString());
  check('a page past the last one says so', await status(usageViewer, 'GET', usagePage(usage.many, '?page=99')) === 200 && await (async () => { await usageViewer.goto(usagePage(usage.many, '?page=99')); return usageViewer.eval('return !!document.querySelector("#smartlinks-usage-past-end");'); })());

  // A nested entry: shown in the page it is in, through the Matrix field; named, with no way in,
  // for someone who may not view the content.
  await usageViewer.goto(usagePage(usage.nested));
  check('a nested occurrence shows the page and the field it is nested in', await usageViewer.eval(`const text = ${usageRows}[0]?.querySelector('th').textContent.replace(/\\s+/g, ' ') ?? ''; return text.includes(${js(usage.nestedPage.title)}) && text.includes('smartLinksCpBlocks') && text.includes('Block');`), await usageViewer.eval(`return ${usageRows}[0]?.querySelector('th').textContent.replace(/\\s+/g, ' ') ?? '';`));
  check('without the right to view the content, no edit page is linked', await usageViewer.eval(`return ${usageRows}[0].querySelectorAll('th a').length === 0;`));
  const usageAdmin = await browser.newPage();
  pages.push(usageAdmin);
  await usageAdmin.goto(fixture('impersonate', String(content.adminId)).url);
  await usageAdmin.goto(usagePage(usage.nested));
  const editLinks = await usageAdmin.eval(`return [...document.querySelectorAll('#smartlinks-usage tbody tr:first-child th a')].map((a) => new URL(a.href).pathname);`);
  check('with it, the page and the nested entry each link to their edit page', editLinks.length === 2 && editLinks[0].endsWith(new URL(usage.nestedPage.editUrl, content.cpUrl).pathname), js(editLinks));
  await usageAdmin.clickAndLoad('#smartlinks-usage tbody tr:first-child th a');
  check('following the source’s link opens it in Craft’s editor', new URL(await usageAdmin.eval('return location.href;')).pathname.endsWith(new URL(usage.nestedPage.editUrl, content.cpUrl).pathname) && await usageAdmin.eval('return !!document.querySelector("#main-form");'));

  // A link to an entry deleted since: still found by it, and said to lead nowhere.
  await usageViewer.goto(usagePage(usage.deletedTarget));
  check('a link to a deleted entry is listed, and its target said not to exist', await usageViewer.eval(`return document.querySelector('#smartlinks-target [data-target]').dataset.target === 'missing' && ${usageRows}.length > 0;`));

  // A source trashed without the index hearing of it: listed as last indexed, said to be in the
  // trash; once the index catches up, gone.
  fixture('usage-stale', String(usage.trashedPage));
  await usageViewer.goto(usagePage(usage.trashed));
  check('an occurrence in a source trashed since is said to be in the trash, with no element shown as current', await usageViewer.eval(`return [...${usageRows}].length > 0 && [...${usageRows}].every((row) => row.querySelector('[data-stale]').dataset.stale === 'sourceTrashed' && row.textContent.includes('The element is in the trash'));`));
  fixture('index');
  check('once the index has caught up, the trashed source’s target is not found', await status(usageViewer, 'GET', usagePage(usage.trashed)) === 404);

  // A field renamed: shown by its new name at once; deleted: said to be, until a rebuild.
  fixture('usage-field', 'rename', 'smartLinksCpOne', 'Renamed in the CP run');
  await usageViewer.goto(usagePage(usage.oneField));
  check('a renamed field is shown by its new name, with nothing indexed again', await usageViewer.eval(`return ${usageRows}.length > 0 && [...${usageRows}].every((row) => row.textContent.includes('Renamed in the CP run') && row.querySelector('[data-stale]').dataset.stale === 'current');`));
  fixture('usage-field', 'rename', 'smartLinksCpOne', 'smartLinksCpOne');
  fixture('usage-field', 'delete', 'smartLinksCpOne');
  await usageViewer.goto(usagePage(usage.oneField));
  check('occurrences of a deleted field are said to be so, naming no field in its place', await usageViewer.eval(`return ${usageRows}.length > 0 && [...${usageRows}].every((row) => row.querySelector('[data-stale]').dataset.stale === 'fieldDeleted' && /Field \\d+ \\(deleted\\)/.test(row.textContent) && !row.textContent.includes('smartLinksCpOne'));`), await usageViewer.eval(`return ${usageRows}[0]?.innerText ?? '';`));
  fixture('index');
  check('once rebuilt, the deleted field’s occurrences are gone', await status(usageViewer, 'GET', usagePage(usage.oneField)) === 404);

  // A rebuilder: offered a rebuild, which goes through Craft's queue and brings the index up to
  // date.
  fixture('make-stale', String(content.inventory.pageId));
  const rebuilder = await browser.newPage();
  pages.push(rebuilder);
  await rebuilder.goto(fixture('impersonate', String(content.inventory.rebuilder)).url);
  await rebuilder.goto(linksUrl);
  check('the page says when the index is behind content', await rebuilder.eval(`return !!document.querySelector('#smartlinks-index-status');`));
  check('a rebuilder is offered a rebuild', await rebuilder.eval(`return !!document.querySelector('#smartlinks-index-actions form button');`));
  await rebuilder.clickAndLoad('#smartlinks-index-actions form button');
  let current = false;

  for (let attempt = 0; attempt < 20 && !current; attempt++) {
    // Craft runs its queue from control panel pages.
    await rebuilder.goto(linksUrl);
    await sleep(1500);
    current = fixture('index-status').stale === false;
  }

  check('the rebuild was queued and ran in Craft’s queue, and the index is up to date again', current && fixture('index-status').queuedRebuilds === 0);
  await rebuilder.goto(linksUrl);
  check('the page no longer says the index is behind', !(await rebuilder.eval(`return !!document.querySelector('#smartlinks-index-status');`)));

  // A guest (no session cookie): sent to sign in, and nothing queued.
  const guest = await rebuilder.eval(`
    const page = await fetch(${js(linksUrl)}, {credentials: 'omit'});
    const rebuild = await fetch(${js(`${content.cpUrl}/actions/smart-links/links/rebuild`)}, {method: 'POST', credentials: 'omit'});
    return {page: page.url, rebuild: rebuild.status, rebuildUrl: rebuild.url};`);
  check('a guest is sent to sign in, and cannot rebuild', new URL(guest.page).pathname.endsWith('/login') && (guest.rebuild >= 400 || new URL(guest.rebuildUrl).pathname.endsWith('/login')) && fixture('index-status').queuedRebuilds === 0, js(guest));

  check('no script errors on the inventory pages', pages.every((page) => page.errors.length === 0), pages.flatMap((page) => page.errors).join('\n'));
}

async function main() {
  let browser = await Browser.launch();

  try {
    await presetsPhase(browser);
  } catch (error) {
    await browser.close();
    throw error;
  }

  console.log('Setting up content in the host project…');
  const content = fixture('setup');

  try {
    check('the content’s project config was stored, so it can be removed again', content.configStored === true);
    await scenario(browser, content);
  } catch (error) {
    // Said before the teardown runs, so a teardown failure cannot hide it.
    console.error(`\nThe scenario stopped: ${error.stack || error}`);
    throw error;
  } finally {
    try {
      await browser?.close();
    } catch (error) {
      console.error(`Closing Chrome failed: ${error}`);
    }

    console.log('Removing the content…');
    const teardown = fixtureResult('teardown');
    check('the project is left exactly as it was (no leftovers, project config and YAML unchanged)', teardown.leftovers.length === 0 && teardown.configRestored && teardown.yamlUnchanged, JSON.stringify(teardown));
    check('Craft sees no project config changes pending afterwards', fixture('pending').pending === false);
  }
}

async function scenario(browser, content) {
  const logMark = fixture('log-mark');
  const admin = await browser.newPage();
  await admin.send('Network.enable');
  const failedRequests = [];
  const requests = new Map();
  browser.listeners.push(async (message) => {
    if (message.sessionId !== admin.sessionId) {
      return;
    }

    if (message.method === 'Network.requestWillBeSent') {
      requests.set(message.params.requestId, message.params.request.postData ?? '');
    }

    if (message.method === 'Network.responseReceived') {
      const {url, status} = message.params.response;

      if (status >= 400 && !url.includes('favicon')) {
        let body = '';

        try {
          body = (await admin.send('Network.getResponseBody', {requestId: message.params.requestId})).body;
        } catch {
          body = '(no body)';
        }

        const errors = (() => {
          try {
            return JSON.stringify(JSON.parse(body).errors ?? JSON.parse(body).message ?? null);
          } catch {
            return body.slice(0, 300);
          }
        })();
        failedRequests.push(`${status} ${url.replace(/\?.*$/, '')} ${new URL(url).searchParams.get('p') ?? ''} → ${errors}`);
        console.log(`      [request failed] ${failedRequests[failedRequests.length - 1]}`);
      }
    }
  });

  await admin.goto(fixture('impersonate', String(content.adminId)).url);
  check('an admin signs in to the control panel', await admin.eval('return location.pathname.startsWith("/admin") && !!document.querySelector("#global-sidebar, #global-container");'));

  await presets(admin, content);

  await admin.goto(content.page.editUrl);
  check('the entry editor shows the Smart Links field', await admin.eval(`return !!${field()};`));
  check('the field’s script and styles are loaded', await admin.eval(`return !!(window.Craft && Craft.SmartLinks && Craft.SmartLinks.Field) && [...document.styleSheets].some((sheet) => (sheet.href || '').includes('field.css'));`));
  check('a single-link field is there too, with no type selector of its own beyond its types', await admin.eval(`return !!${field('smartLinksCpOne')};`));


  // Every type, authored through its own inputs and Craft's element pickers.
  const keys = {};
  keys.url = await addLink(admin, 'url');
  const offered = await admin.eval(`return [...${linkByKey(keys.url)}.querySelectorAll('select[data-smartlinks-type] option')].map((option) => option.value);`);
  check('the type selector offers every registered link type, in order', js(offered) === js(content.handles), js(offered));
  await admin.eval(setValue(data(keys.url, 'url'), 'input[name$="[url]"]', 'HTTPS://Example.COM/docs'));
  await admin.eval(setValue(linkByKey(keys.url), 'input[data-smartlinks-label]', 'Docs'));

  keys.entry = await addLink(admin, 'entry');
  await pick(admin, keys.entry, 'entry', content.sources.entry, content.target.id);
  check('the entry picker shows the chosen entry by its own title', await admin.eval(`return ${data(keys.entry, 'entry')}.querySelector('.chip[data-id="${content.target.id}"]').getAttribute('data-label') === ${js(content.target.title)};`));
  check('choosing an entry fills the link’s element ID', await admin.eval(`return ${data(keys.entry, 'entry')}.querySelector('input[name$="[elementId]"]').value === '${content.target.id}';`));

  keys.category = await addLink(admin, 'category');
  await pick(admin, keys.category, 'category', content.sources.category, content.category.id);
  keys.asset = await addLink(admin, 'asset');
  await pick(admin, keys.asset, 'asset', content.sources.asset, content.asset.id);
  keys.user = await addLink(admin, 'user');
  await pick(admin, keys.user, 'user', content.sources.user, content.adminId);

  if (content.product) {
    keys.product = await addLink(admin, 'commerce-product');
    await pick(admin, keys.product, 'commerce-product', content.sources['commerce-product'], content.product.id);
  }

  // A type switched after typing: only the chosen type's data is the link's.
  keys.email = await addLink(admin, 'url');
  await admin.eval(setValue(data(keys.email, 'url'), 'input[name$="[url]"]', 'https://stale.example/'));
  await admin.eval(`{ const select = ${linkByKey(keys.email)}.querySelector('select[data-smartlinks-type]'); select.value = 'email'; select.dispatchEvent(new Event('change', {bubbles: true})); }`);
  await admin.eval(setValue(data(keys.email, 'email'), 'input[name$="[address]"]', 'Hello@EXAMPLE.com'));
  await admin.eval(setValue(data(keys.email, 'email'), 'input[name$="[subject]"]', 'Hi'));
  await admin.eval(setValue(linkByKey(keys.email), 'input[data-smartlinks-label]', '<b>Mail</b> "us"'));

  keys.tel = await addLink(admin, 'tel');
  await admin.eval(setValue(data(keys.tel, 'tel'), 'input[name$="[number]"]', '+1 (555) 123-4567'));
  keys.sms = await addLink(admin, 'sms');
  await admin.eval(setValue(data(keys.sms, 'sms'), 'input[name$="[number]"]', '+15551234567'));
  await admin.eval(setValue(data(keys.sms, 'sms'), 'textarea[name$="[body]"]', 'Hi there'));
  keys.social = await addLink(admin, 'social');
  await admin.eval(setValue(data(keys.social, 'social'), 'select[name$="[network]"]', 'github'));
  await admin.eval(setValue(data(keys.social, 'social'), 'input[name$="[account]"]', 'craftcms'));
  keys.embed = await addLink(admin, 'embed');
  await admin.eval(setValue(data(keys.embed, 'embed'), 'input[name$="[url]"]', 'https://youtu.be/dQw4w9WgXcQ?si=share'));
  keys.doomed = await addLink(admin, 'entry');
  await pick(admin, keys.doomed, 'entry', content.sources.entry, content.doomed.id);
  keys.private = await addLink(admin, 'entry');
  await pick(admin, keys.private, 'entry', content.sources.privateEntry, content.private.id);
  keys.disabled = await addLink(admin, 'entry');
  await pick(admin, keys.disabled, 'entry', content.sources.entry, content.disabled.id);

  await save(admin);
  let stored = fixture('inspect', String(content.page.id), content.primarySite.handle).canonical;
  const types = stored.smartLinksCpLinks?.links?.map((link) => link.type) ?? [];
  const expectedTypes = ['url', 'entry', 'category', 'asset', 'user', ...(content.product ? ['commerce-product'] : []), 'email', 'tel', 'sms', 'social', 'embed', 'entry', 'entry', 'entry'];
  check('saving stores every link, in the order authored', js(types) === js(expectedTypes), js(types));
  // The first link of each type (the entry links after the first are the deleted and private ones).
  const byType = {};
  (stored.smartLinksCpLinks?.links ?? []).forEach((link) => byType[link.type] ??= link);
  check('the URL is stored in canonical form, with its label', js(byType.url?.data) === js({url: 'https://example.com/docs'}) && byType.url?.label === 'Docs');
  check('the switched link keeps only the email data, normalized (no stale URL)', js(byType.email?.data) === js({address: 'Hello@example.com', subject: 'Hi'}) && !JSON.stringify(stored).includes('stale.example'));
  check('phone, SMS, social and embed are stored structured', js(byType.tel?.data) === js({number: '+15551234567'}) && js(byType.sms?.data) === js({number: '+15551234567', body: 'Hi there'}) && js(byType.social?.data) === js({network: 'github', account: 'craftcms'}) && js(byType.embed?.data) === js({provider: 'youtube', mediaId: 'dQw4w9WgXcQ'}));
  check('element links store only the chosen elements’ IDs, following the content’s site', byType.entry?.data?.elementId === content.target.id && byType.entry?.data?.siteId === undefined && byType.category?.data?.elementId === content.category.id && byType.asset?.data?.elementId === content.asset.id && byType.user?.data?.elementId === content.adminId && (!content.product || byType['commerce-product']?.data?.elementId === content.product.id), js(Object.fromEntries(['entry', 'category', 'asset', 'user', 'commerce-product'].map((type) => [type, byType[type]?.data]))) + ' expected ' + js([content.target.id, content.category.id, content.asset.id, content.adminId, content.product?.id]));
  const expectedRelations = [content.target.id, content.category.id, content.asset.id, content.adminId, ...(content.product ? [content.product.id] : []), content.doomed.id, content.private.id, content.disabled.id];
  check('the element links are Craft relations of the entry, in link order, and nothing else is', js(stored.relations) === js(expectedRelations), js(stored.relations));

  // Reloaded, the editor shows exactly what was saved.
  await admin.goto(content.page.editUrl);
  check('after reloading, the editor has every link', await admin.eval(`return ${links()}.length;`) === expectedTypes.length);
  check('after reloading, the chosen elements show by their own titles', await admin.eval(`return [${js(content.target.title)}, ${js(content.category.title)}, ${js(content.asset.title)}].every((title) => ${field()}.querySelector('.chip[data-label="' + title + '"]'));`));
  check('after reloading, the authored values are in their inputs', await admin.eval(`return ${field()}.querySelector('input[name$="[data][url][url]"]').value === 'https://example.com/docs' && [...${field()}.querySelectorAll('input[name$="[data][email][address]"]')].some((input) => input.value === 'Hello@example.com');`));
  const reloadedKeys = await admin.eval(`return ${links()}.map((link) => link.getAttribute('data-key'));`);

  // An invalid edit is refused, shown, and not saved.
  await admin.eval(setValue(data(reloadedKeys[0], 'url'), 'input[name$="[url]"]', 'javascript:alert(1)'));
  await save(admin);
  check('an unsafe URL is refused with a message in the editor', await admin.eval(`return document.body.innerText.includes('Only http and https URLs can be linked to.');`));
  const afterRefusal = fixture('inspect', String(content.page.id), content.primarySite.handle);
  check('the refused value never reaches the live entry; a draft keeps it only as unfinished input', js(afterRefusal.canonical.smartLinksCpLinks.links[0].data) === js({url: 'https://example.com/docs'}) && (afterRefusal.provisional === null || afterRefusal.provisional.smartLinksCpLinks.invalid === true), js(afterRefusal.provisional));

  // Reorder, duplicate, copy and paste, through each link's own menu.
  await admin.goto(content.page.editUrl);
  let current = await admin.eval(`return ${links()}.map((link) => link.getAttribute('data-key'));`);

  // From the keyboard: Enter on a menu item, as real key events, moves the link and back again.
  const pressEnter = async (element) => {
    await admin.eval(`(${element}).focus();`);

    // Only ever the intended element: Enter elsewhere could submit Craft's form.
    if (!(await admin.eval(`return document.activeElement === (${element});`))) {
      throw new Error(`Could not focus ${element}`);
    }

    for (const type of ['keyDown', 'keyUp']) {
      await admin.send('Input.dispatchKeyEvent', {type, key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, nativeVirtualKeyCode: 13, ...(type === 'keyDown' ? {text: '\r'} : {})});
    }

    await sleep(300);
  };
  const viaMenu = async (key, action) => {
    await pressEnter(`document.querySelector('[aria-controls="fields-${FIELD}-${key}-actions"]')`);
    await admin.waitFor(`document.getElementById('fields-${FIELD}-${key}-${action}')?.offsetParent`);
    await pressEnter(`document.getElementById('fields-${FIELD}-${key}-${action}')`);
  };
  await viaMenu(current[3], 'move-up');
  const movedUp = await admin.eval(`return ${links()}[2].getAttribute('data-key') === ${js(current[3])};`);
  await viaMenu(current[3], 'move-down');
  const keyboardOrder = await admin.eval(`return ${links()}.map((link) => link.getAttribute('data-key'));`);
  check('Enter on Move up and Move down moves a link from the keyboard', movedUp && keyboardOrder[3] === current[3], js({movedUp, before: current.slice(0, 5), after: keyboardOrder.slice(0, 5)}));
  await menu(admin, current[0], 'move-down');
  check('Move down moves the link', await admin.eval(`return ${links()}[1].getAttribute('data-key') === ${js(current[0])};`));
  const emailKey = current[expectedTypes.indexOf('email')];
  await menu(admin, emailKey, 'duplicate');
  await admin.waitFor(`${links()}.length === ${expectedTypes.length + 1}`);
  const socialKey = current[expectedTypes.indexOf('social')];
  await menu(admin, socialKey, 'copy');
  await admin.waitFor(`!${field()}.querySelector('[data-smartlinks-paste]').hidden`);
  await admin.eval(`${field()}.querySelector('[data-smartlinks-paste]').click();`);
  await admin.waitFor(`${links()}.length === ${expectedTypes.length + 2}`);
  const toggle = `${linkByKey(current[1])}.querySelector('[data-smartlinks-toggle]')`;
  await admin.eval(`${toggle}.click();`);
  const collapsed = await admin.eval(`return ${toggle}.getAttribute('aria-expanded') === 'false';`);
  await admin.eval(`${toggle}.click();`);
  check('a link collapses and expands', collapsed && await admin.eval(`return ${toggle}.getAttribute('aria-expanded') === 'true';`));
  await save(admin);
  stored = fixture('inspect', String(content.page.id), content.primarySite.handle).canonical;
  const after = stored.smartLinksCpLinks.links;
  check('the reorder is saved', after[0].type === 'entry' && after[1].type === 'url');
  const emails = after.filter((link) => link.type === 'email');
  check('the duplicate is a copy of the link with its own UID', emails.length === 2 && js(emails[0].data) === js(emails[1].data) && emails[0].uid !== emails[1].uid);
  const socials = after.filter((link) => link.type === 'social');
  check('the pasted link is a copy of the copied one with its own UID, added last', socials.length === 2 && after[after.length - 1].type === 'social' && socials[0].uid !== socials[1].uid);
  check('the relations follow the new order', stored.relations[0] === content.target.id);

  // A target deleted since: said so, its title is gone, and the link keeps its ID.
  fixture('delete', String(content.doomed.id));
  await admin.goto(content.page.editUrl);
  check('a deleted target is named as gone in the editor', await admin.eval(`return document.body.innerText.includes('The entry this link points at (ID ${content.doomed.id}) no longer exists.');`));
  check('a deleted target’s title is not shown', !(await admin.eval(`return ${field()}.innerHTML.includes(${js(content.doomed.title)});`)));
  check('the deleted target’s ID is kept in the form', await admin.eval(`return [...${field()}.querySelectorAll('input[name$="[elementId]"]')].some((input) => input.value === '${content.doomed.id}');`));

  // A provisional draft holds unsaved changes; saving applies it. Each save is a revision.
  await admin.eval(setValue(linkByKey(await admin.eval(`return ${links()}[1].getAttribute('data-key');`)), 'input[data-smartlinks-label]', 'Docs (draft)'));
  const drafted = await autosaved(admin, content);
  check('Craft autosaves the edit as a provisional draft, the live entry unchanged', drafted.provisional.smartLinksCpLinks.links[1].label === 'Docs (draft)' && drafted.canonical.smartLinksCpLinks.links[1].label === 'Docs');
  await save(admin);
  const applied = fixture('inspect', String(content.page.id), content.primarySite.handle);
  check('saving applies the draft', applied.canonical.smartLinksCpLinks.links[1].label === 'Docs (draft)' && applied.provisional === null);
  check('each save made a revision', applied.revisions >= 3, String(applied.revisions));

  // Another site has its own value: the field is translated per site.
  const secondUrl = content.page.editUrl.replace(/site=[^&]+/, `site=${content.secondSite.handle}`);
  await admin.goto(secondUrl);
  check('the second site’s editor starts with its own (empty) value', await admin.eval(`return ${links()}.length === 0;`));
  const secondKey = await addLink(admin, 'entry');
  check('the site choice offers the content’s site by default', await admin.eval(`return ${data(secondKey, 'entry')}.querySelector('select[name$="[siteId]"]').value === '';`));
  await pick(admin, secondKey, 'entry', content.sources.entry, content.target.id);
  await save(admin);
  const second = fixture('inspect', String(content.page.id), content.secondSite.handle).canonical;
  const primaryAgain = fixture('inspect', String(content.page.id), content.primarySite.handle).canonical;
  check('the second site saves its own link, and the first site’s are untouched', second.smartLinksCpLinks.links.length === 1 && second.smartLinksCpLinks.links[0].data.elementId === content.target.id && primaryAgain.smartLinksCpLinks.links.length === after.length);
  check('the second site’s relations are its own', js(second.relations) === js([content.target.id]));


  // Inside a Matrix field: a nested entry, edited inline, has its own Smart Links field.
  await admin.goto(content.page.editUrl);
  await admin.eval(`document.querySelector('[data-attribute="smartLinksCpBlocks"] .matrix-field .buttons .btn.add').click();`);
  const blockEditor = `document.querySelector('[data-attribute="smartLinksCpBlocks"] .matrixblock [data-attribute="smartLinksCpOne"] [data-smartlinks-field]')`;
  // Craft renders the nested entry, then runs its scripts: the editor is ready once it is set up.
  await admin.waitFor(`${blockEditor} && $(${blockEditor}).data('smartLinksField')`, 20000);
  await admin.eval(`(${blockEditor}).querySelector('[data-smartlinks-add]').click();`);
  await admin.waitFor(`(${blockEditor}).querySelector('[data-smartlinks-link] input[name$="[data][url][url]"]')`);
  await admin.eval(setValue(blockEditor, '[data-smartlinks-link] input[name$="[data][url][url]"]', 'https://example.com/in-matrix'));
  await save(admin);
  const nested = fixture('inspect', String(content.page.id), content.primarySite.handle).nested;
  check('a link in a Matrix nested entry is saved with that entry', nested.length === 1 && js(nested[0]?.links?.[0]?.data) === js({url: 'https://example.com/in-matrix'}), js(nested));
  await admin.goto(content.page.editUrl);
  check('after reloading, the nested entry’s link is in its editor', await admin.eval(`return (${blockEditor})?.querySelector('input[name$="[data][url][url]"]')?.value === 'https://example.com/in-matrix';`));

  // In a slideout: Craft's element editor for the linked entry, opened from its chip in the
  // page's own element picker, as Craft's element select opens it.
  await admin.eval(`Craft.createElementEditor(${js('craft\\elements\\Entry')}, $(${field()}.querySelector('.elementselect .chip[data-id="${content.target.id}"]')));`);
  const slideoutEditor = `[...document.querySelectorAll('.slideout-container:not(.hidden) .slideout')].pop()?.querySelector('[data-attribute="smartLinksCpLinks"] [data-smartlinks-field]')`;
  await admin.waitFor(slideoutEditor, 20000);
  // The slideout's markup is in the page before Craft runs its scripts, so Add is retried until
  // the editor answers.
  for (let i = 0; i < 20 && !(await admin.eval(`return !!(${slideoutEditor}).querySelector('[data-smartlinks-link]');`)); i++) {
    await admin.eval(`(${slideoutEditor}).querySelector('[data-smartlinks-add]').click();`);
    await sleep(500);
  }

  await admin.waitFor(`(${slideoutEditor}).querySelector('[data-smartlinks-link] input[name$="[data][url][url]"]')`);
  await admin.eval(setValue(slideoutEditor, '[data-smartlinks-link] input[name$="[data][url][url]"]', 'https://example.com/in-slideout'));
  await admin.eval(`[...document.querySelectorAll('.slideout-container:not(.hidden) .slideout')].pop().querySelector('button[type="submit"].submit').click();`);
  let inSlideout = null;

  for (let i = 0; i < 40 && !inSlideout; i++) {
    await sleep(500);
    const links = fixture('inspect', String(content.target.id), content.primarySite.handle).canonical.smartLinksCpLinks?.links;
    inSlideout = links?.length ? links : null;
  }

  check('a link authored in a slideout is saved', js(inSlideout?.[0]?.data) === js({url: 'https://example.com/in-slideout'}), js(inSlideout));

  // The control panel's element cards show the field's preview: a link to a disabled entry is a
  // status, never the entry's title.
  await admin.goto(content.indexUrl);
  await admin.eval(`document.querySelector('button[data-view="cards"]').click();`);
  const pageCard = `[...document.querySelectorAll('[data-id="${content.page.id}"]')].map((el) => el.closest('.card, li')).find((el) => el && el.querySelector('.smartlinks-status, a[href]'))`;
  await admin.waitFor(pageCard, 20000);
  const card = await admin.eval(`return (${pageCard}).innerHTML;`);
  check('the CP card preview renders the links, with each target that leads nowhere as a status', card.includes('href="https://example.com/docs"') && card.includes('data-status="disabled"') && card.includes('data-status="missing"'), card.slice(0, 600));
  check('the CP card preview never names a disabled target', !card.includes(content.disabled.title) && card.includes('Entry (leads nowhere: its target is disabled)'));

  // The front end renders what the CP saved, every value encoded.
  await admin.goto(content.page.url);
  const rendered = await admin.eval(`return [...document.querySelectorAll('#smartlinks-cp-render li')].map((li) => ({type: li.getAttribute('data-type'), href: li.querySelector('a')?.getAttribute('href') ?? null, text: li.querySelector('a')?.textContent ?? null, html: li.innerHTML}));`);
  const hrefOf = (type) => rendered.find((item) => item.type === type)?.href;
  check('the front end renders the URL link', hrefOf('url') === 'https://example.com/docs');
  check('the front end renders element links to their URLs', rendered.some((item) => item.type === 'entry' && item.href === content.target.url) && hrefOf('category') === content.category.url && (hrefOf('asset') ?? '').endsWith('cp-brochure.pdf') && (!content.product || hrefOf('commerce-product') === content.product.url));
  check('a user link without a URL, a deleted target and a disabled one render nothing', rendered.find((item) => item.type === 'user')?.href === null && !rendered.some((item) => (item.html || '').includes(content.doomed.title) || (item.html || '').includes(content.disabled.title)));
  check('the front end renders email, phone, SMS, social and embed hrefs', hrefOf('email') === 'mailto:Hello@example.com?subject=Hi' && hrefOf('tel') === 'tel:+15551234567' && hrefOf('sms') === 'sms:+15551234567?body=Hi%20there' && hrefOf('social') === 'https://github.com/craftcms' && hrefOf('embed') === 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');
  const emailItem = rendered.find((item) => item.type === 'email');
  check('a label with markup is shown as text, encoded', emailItem?.text === '<b>Mail</b> "us"' && emailItem.html.includes('&lt;b&gt;Mail&lt;/b&gt;') && !emailItem.html.includes('<b>'));

  // GraphQL over HTTP, with a token whose schema may read and save these pages but not the
  // private section.
  const gql = (query, variables = {}) => admin.eval(`const response = await fetch('/actions/graphql/api', {method: 'POST', headers: {'Content-Type': 'application/json', Authorization: 'Bearer ${content.gqlToken}'}, body: JSON.stringify({query: ${js(query)}, variables: ${js(variables)}})}); return {status: response.status, body: await response.json()};`);
  const readQuery = `{ entry(id: ${content.page.id}) { ... on smartLinksCpPage_Entry { ${FIELD} { type status url text data { __typename ... on SmartLinkData_url { url } ... on SmartLinkData_entry { elementId } } element { id } } } } }`;
  const read = await gql(readQuery);
  const gqlLinks = read.body?.data?.entry?.[FIELD] ?? [];
  check('GraphQL over HTTP returns the saved links', read.status === 200 && !read.body.errors && gqlLinks.length === after.length, js(read.body).slice(0, 400));
  check('GraphQL returns the URL link’s own data and rendered URL', gqlLinks[1]?.data?.url === 'https://example.com/docs' && gqlLinks[1]?.url === 'https://example.com/docs');
  check('GraphQL returns the linked entry the schema may read', gqlLinks[0]?.element?.id === String(content.target.id));
  const privateLink = gqlLinks.find((link) => link.data?.elementId === content.private.id);
  check('GraphQL does not return an element its schema may not read', privateLink !== undefined && privateLink.element === null);
  check('GraphQL reports a deleted target as missing, with no URL', gqlLinks.some((link) => link.data?.elementId === content.doomed.id && link.status === 'missing' && link.url === null));

  const mutation = `mutation($id: ID, $links: [SmartLinkInput!]) { save_smartLinksCpPages_smartLinksCpPage_Entry(id: $id, ${FIELD}: $links) { id } }`;
  const gqlStored = () => fixture('inspect', String(content.graphqlEntry.id), content.primarySite.handle).canonical;
  let saved = await gql(mutation, {id: String(content.graphqlEntry.id), links: [{type: 'url', data: '{"url": "https://Example.com/a"}', label: 'A'}, {type: 'entry', data: js({elementId: content.target.id})}]});
  let value = gqlStored();
  check('a GraphQL mutation saves links by the editor’s rules', !saved.body.errors && js(value.smartLinksCpLinks.links.map((link) => link.data)) === js([{url: 'https://example.com/a'}, {elementId: content.target.id}]) && js(value.relations) === js([content.target.id]), js(saved.body).slice(0, 300));
  saved = await gql(mutation, {id: String(content.graphqlEntry.id), links: [{type: 'url', data: '{"url": "javascript:alert(1)"}'}]});
  check('a GraphQL mutation with an unsafe URL is refused, and nothing changes', !!saved.body.errors && gqlStored().smartLinksCpLinks.links.length === 2);
  saved = await gql(mutation, {id: String(content.graphqlEntry.id), links: [{type: 'entry', data: js({elementId: content.category.id})}]});
  check('a GraphQL mutation pointing an entry link at a category is refused', !!saved.body.errors && gqlStored().smartLinksCpLinks.links.length === 2);
  saved = await gql(`mutation { save_smartLinksCpPrivate_smartLinksCpPage_Entry(id: ${content.private.id}, title: "x") { id } }`);
  check('the token cannot save the private section at all', !!saved.body.errors);
  saved = await gql(mutation, {id: String(content.graphqlEntry.id), links: []});
  check('an empty list clears the field through GraphQL', !saved.body.errors && (gqlStored().smartLinksCpLinks?.links ?? []).length === 0 && js(gqlStored().relations) === js([]));
  saved = await gql(mutation, {id: String(content.graphqlEntry.id), links: null});
  check('null is refused by Craft (as documented), and changes nothing', !!saved.body.errors && (gqlStored().smartLinksCpLinks?.links ?? []).length === 0);

  // Craft autosaves a provisional draft as the author works; a link still being written fails
  // the field's validation, which refuses invalid input even in a draft.
  const refusedAutosaves = failedRequests.filter((request) => request.includes('elements/save-draft') && request.includes('smartLinksCpLinks'));
  check('Craft’s autosave is never refused while a link is being written', refusedAutosaves.length === 0, `${refusedAutosaves.length} autosaves refused, e.g.\n      ${refusedAutosaves.slice(0, 3).join('\n      ')}`);
  // What Craft's element editor throws when a draft save is refused: the rejected request, and its
  // saveDraft() reading the missing response afterwards (cp.js).
  const isAutosaveError = (error) => error.includes('AxiosError: Request failed with status code 400')
    || error.trim() === 'Uncaught (in promise)'
    || (error.startsWith("TypeError: Cannot read properties of undefined (reading 'data')") && error.includes('/cp.js'));
  // Craft's element index cancels its own request when a picker's source changes while the last
  // one loads, and leaves the cancellation uncaught (cp.js: updateElements → _cancelRequests).
  const isCraftIndexCancel = (error) => error.startsWith('CanceledError: canceled') && error.includes('_cancelRequests') && error.includes('updateElements');
  const autosaveScriptErrors = admin.errors.filter((error) => isAutosaveError(error) || isCraftIndexCancel(error));
  check('no script errors in the admin’s pages, besides Craft’s own (refused autosaves, cancelled index loads)', admin.errors.length === autosaveScriptErrors.length, admin.errors.filter((error) => !autosaveScriptErrors.includes(error)).map((error) => error.slice(0, 1500)).join('\n---\n'));
  check('no other failed requests in the admin’s session', failedRequests.length === refusedAutosaves.length, failedRequests.filter((request) => !refusedAutosaves.includes(request)).join('\n'));

  // An author who may edit these pages in the primary site only, and not the private section.
  if (content.authorId) {
    const author = await browser.newPage();
    await author.goto(fixture('impersonate', String(content.authorId)).url);
    await author.goto(content.page.editUrl);
    check('the author opens the entry and sees the field', await author.eval(`return !!${field()};`));
    check('the author is told a link points at an entry they can’t view, and never sees its title', await author.eval(`return document.body.innerText.includes('You can’t view the entry this link points at (ID ${content.private.id}).') && !document.documentElement.innerHTML.includes(${js(content.private.title)});`));
    const authorKey = await addLink(author, 'entry');
    await author.eval(`${data(authorKey, 'entry')}.querySelector('.elementselect button.add').click();`);
    await author.waitFor(modal);
    await author.waitFor(`${modal}.querySelector('.sidebar [data-key]')`);
    check('the author’s entry picker does not offer the private section', !(await author.eval(`return !!${modal}.querySelector('.sidebar [data-key="${content.sources.privateEntry}"]');`)));
    // A pasted link to the private entry: the server renders it without disclosing it.
    await author.eval(`localStorage.setItem('Craft-SmartLinks.clipboard', ${js(JSON.stringify({version: 1, links: [{type: 'entry', data: {elementId: content.private.id}}]}))});`);
    await author.goto(content.page.editUrl);
    await author.waitFor(`!${field()}.querySelector('[data-smartlinks-paste]').hidden`);
    const beforePaste = await author.eval(`return ${links()}.length;`);
    await author.eval(`${field()}.querySelector('[data-smartlinks-paste]').click();`);
    await author.waitFor(`${links()}.length === ${beforePaste + 1}`);
    check('a pasted link to a hidden entry shows no title', !(await author.eval(`return document.documentElement.innerHTML.includes(${js(content.private.title)});`)) && await author.eval(`return ${links()}[${beforePaste}].innerText.includes('You can’t view the entry this link points at');`));
    // Craft answers an editor for a site the user may not edit with another site's, or a refusal.
    await author.goto(secondUrl);
    const opened = await author.eval(`return {site: new URL(location.href).searchParams.get('site'), editingSiteId: document.querySelector('#main-form input[name="siteId"]')?.value ?? null, title: document.title};`);
    check('the author cannot open the entry in a site they may not edit (Craft shows a site they may)', opened.editingSiteId !== String(content.secondSite.id), JSON.stringify(opened));
    check('no script errors in the author’s pages, besides refused autosaves', author.errors.every((error) => isAutosaveError(error) || isCraftIndexCancel(error)), author.errors.join('\n'));
  } else {
    check('a restricted author could be created for the permission checks', false, 'The host refused to save a test user.');
  }

  await linksPhase(browser, content);

  // The mutations refused on purpose above are logged by Craft as GraphQL user errors.
  const expectedErrors = ['Only http and https URLs can be linked to.', 'is not one of the entries this link can point at', 'Cannot query field "save_smartLinksCpPrivate_', '_traverseAndNormalizeArguments(): Argument #2 ($mutationArguments) must be of type array, null given'];
  // So are the inventory's refusals asked for above: a user without a permission, a GET for a
  // rebuild, malformed filters, and a guest's rebuild without a CSRF token.
  const expectedRefusals = ['User is not authorized to perform this action.', 'Post request required', 'The inventory can’t be sorted by “nonsense”.', 'There is no site “noSuchSite”.', 'The page must be a positive number.', '“great” is not a health state.', '“Not A Type” is not a link type handle.', 'There is no Smart Links field “title”.', 'Unable to verify your data submission.'];
  const logged = fixture('log-errors', JSON.stringify(logMark));
  const unexpected = logged.filter((line) => !((line.includes('[GraphQL\\Error\\UserError]') || line.includes('[GraphQL\\Error\\Error]') || line.includes('[TypeError]')) && expectedErrors.some((message) => line.includes(message)))
    && !(/\[yii\\web\\HttpException:40[035]\]/.test(line) && expectedRefusals.some((message) => line.includes(message))));
  check('Craft logged no errors or warnings while the test ran, besides the refusals asked for', unexpected.length === 0, unexpected.join('\n'));
}

main()
  .then(() => {
    console.log(`\n${results.length} checks, ${results.filter((result) => result.ok).length} passed, ${results.filter((result) => !result.ok).length} failed.`);
    process.exit(failed || results.length === 0 ? 1 : 0);
  })
  .catch((error) => {
    console.error(`\nThe control panel test could not run to the end: ${error.stack || error}`);
    process.exit(2);
  });
