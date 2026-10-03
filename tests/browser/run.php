<?php

// Runs the link editor's browser test in headless Chrome: Craft's real editor markup (rendered
// by render.php inside DDEV, which this starts), Craft's own jQuery, jQuery UI and Garnish (what
// the control panel loads for the editor), and the field's script. Exits 0 when every check
// passes, 1 when any fails, and 2 when it could not run at all, so a test that did not run is
// never reported as passed.

$root = dirname(__DIR__, 2);
$build = __DIR__ . '/.build';
$assets = dirname($root, 2) . '/vendor/craftcms/cms/src/web/assets';
$chrome = getenv('CHROME_BIN') ?: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

// The editor is rendered inside DDEV, with Craft, and the files reach the host a moment later.
// The runner starts the render itself and waits for that render's own files, so it can never
// test the files an earlier render left behind.
$render = getenv('SMARTLINKS_RENDER') ?: 'ddev exec -d /var/www/html/plugins/Smart-Links php tests/browser/render.php';
$output = (string)shell_exec($render . ' 2>&1');

if (!preg_match('/Rendered build ([0-9a-f-]{36}) /', $output, $rendered)) {
    fwrite(STDERR, "The editor could not be rendered:\n$output\n");
    exit(2);
}

$buildId = $rendered[1];
$isThisBuild = static function() use ($build, $buildId): bool {
    $responses = json_decode((string)@file_get_contents("$build/responses.json"), true);

    return is_array($responses) && ($responses['build'] ?? null) === $buildId
        && str_contains((string)@file_get_contents("$build/editor.html"), "<!-- build $buildId -->")
        && str_contains((string)@file_get_contents("$build/init.js"), "// build $buildId");
};

for ($wait = 0; !$isThisBuild() && $wait < 40; $wait++) {
    usleep(500000);
    clearstatcache();
}

if (!$isThisBuild()) {
    fwrite(STDERR, "The files of render $buildId did not arrive. Run the test again.\n");
    exit(2);
}

if (!is_executable($chrome)) {
    fwrite(STDERR, "Chrome was not found at $chrome. Set CHROME_BIN to run the browser test.\n");
    exit(2);
}

foreach (['jquery/dist/jquery.js' => 'jquery.js', 'jqueryui/dist/jquery-ui.js' => 'jquery-ui.js', 'velocity/dist/velocity.js' => 'velocity.js', 'garnish/dist/garnish.js' => 'garnish.js'] as $from => $to) {
    if (!copy("$assets/$from", "$build/$to")) {
        fwrite(STDERR, "Could not copy Craft's $from.\n");
        exit(2);
    }
}

copy("$root/src/web/assets/field/dist/field.js", "$build/field.js");
copy("$root/src/web/assets/field/dist/field.css", "$build/field.css");
copy(__DIR__ . '/editor.test.js', "$build/editor.test.js");

$json = static fn(mixed $value): string => json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$editor = (string)file_get_contents("$build/editor.html");
$init = (string)file_get_contents("$build/init.js");
$responses = json_decode((string)file_get_contents("$build/responses.json"), true, flags: JSON_THROW_ON_ERROR);

// Craft's own `.hidden` rule, which the editor relies on to hide what is not available.
$page = <<<HTML
<!doctype html>
<html><head><meta charset="utf-8">
<style>.hidden { display: none !important; } body { width: 900px; }</style>
<script>
// Headless Chrome dumping the DOM never fires animation frames, which Garnish's and Velocity's
// animations finish on; frames are driven by timers instead, as a page in view would get them.
window.requestAnimationFrame = (callback) => setTimeout(() => callback(performance.now()), 16);
window.cancelAnimationFrame = (id) => clearTimeout(id);
</script>
<link rel="stylesheet" href="field.css">
<script src="jquery.js"></script><script src="jquery-ui.js"></script><script src="velocity.js"></script><script src="garnish.js"></script>
<script>
window.responses = {$json($responses)};
window.initJs = {$json($init)};
try { window.localStorage.clear(); } catch (e) {}
// How Craft's requests fail: no response at all, or Craft's own JSON for an HTTP error.
window.failures = {
  network: {request: {}},
  csrf: {response: {status: 400, data: {name: 'Bad Request', message: 'Unable to verify your data submission.', code: 0, status: 400}}},
  forbidden: {response: {status: 403, data: {name: 'Forbidden', message: 'User is not authorized to perform this action.', code: 0, status: 403}}},
  server: {response: {status: 500, data: {}}},
  notFound: {response: {status: 404, data: '<html>Not Found</html>'}},
  timeout: {code: 'ECONNABORTED', request: {}},
};
window.Craft = {
  announced: [], errors: [], notices: [], appended: [], requests: [], nextPaste: 'accept', nextCopy: 'accept', nextFailure: null, hold: false, pending: [],
  t: (category, message, params) => message.replace(/\{(\w+)\}/g, (all, key) => params && key in params ? params[key] : all),
  cp: {
    announce: (message) => Craft.announced.push(message),
    displayNotice: (message) => Craft.notices.push(message),
    displayError: (message) => Craft.errors.push(message),
  },
  initUiElements: () => {},
  appendBodyHtml: (html) => { Craft.appended.push(html); },
  ensureEndsWith: (string, end) => string.endsWith(end) ? string : string + end,
  // While `hold` is on, answers wait until the test releases them, oldest first, so requests can
  // be left in flight while the author keeps working.
  release: () => {
    const next = Craft.pending.shift();
    next && next();

    return new Promise((resolve) => setTimeout(resolve, 50));
  },
  sendActionRequest: (method, action, options) => {
    const params = [...options.data.entries()];
    Craft.requests.push({method: method, action: action, params: params, timeout: options.timeout});
    const answer = () => {
      const failure = Craft.nextFailure;
      Craft.nextFailure = null;
      if (failure) {
        return Promise.reject(window.failures[failure]);
      }
      if (action === 'smart-links/field/copy') {
        return Promise.resolve({data: Craft.nextCopy === 'empty' ? {count: 1} : {clipboard: window.responses.clipboard, count: 1}});
      }
      if (Craft.nextPaste === 'refuse') {
        return Promise.reject({response: {status: 400, data: window.responses.refused}});
      }
      // The paste was rendered for paste number 9; renamed for this request's own number, as
      // the controller names pasted links, or for another one, as a stale answer would be.
      const operation = (params.find((pair) => pair[0] === 'operation') || [])[1];
      const key = Craft.nextPaste === 'mismatch' ? 'new999999-1' : 'new' + operation + '-1';
      const rename = (text) => text.split('new9-1').join(key);
      if (Craft.nextPaste === 'malformed') {
        return Promise.resolve({data: {links: [{key: key, html: '<p>Not a link</p>', js: ''}]}});
      }
      return Promise.resolve({data: {links: window.responses.paste.links.map((link) => ({key: rename(link.key), html: rename(link.html), js: rename(link.js)}))}});
    };
    if (!Craft.hold) {
      return answer();
    }
    return new Promise((resolve, reject) => Craft.pending.push(() => answer().then(resolve, reject)));
  },
};
</script>
<script src="field.js"></script>
</head><body>
<form id="form">{$editor}</form>
<pre id="result"></pre>
<script src="editor.test.js"></script>
</body></html>
HTML;

file_put_contents("$build/editor-test.html", $page);

$command = sprintf(
    '%s --headless=new --disable-gpu --no-first-run --no-default-browser-check --allow-file-access-from-files --virtual-time-budget=20000 --dump-dom %s 2>/dev/null',
    escapeshellarg($chrome),
    escapeshellarg('file://' . $build . '/editor-test.html'),
);
$dom = (string)shell_exec($command);

if (!preg_match('/<pre id="result">(.*?)<\/pre>/s', $dom, $match) || trim($match[1]) === '') {
    fwrite(STDERR, "The browser test did not report any results.\n");
    exit(2);
}

$results = json_decode(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5), true);

if (!is_array($results) || $results === []) {
    fwrite(STDERR, "The browser test's results could not be read.\n");
    exit(2);
}

$failed = 0;

foreach ($results as $result) {
    $failed += $result['pass'] ? 0 : 1;
    $mark = $result['pass'] ? '  ✔ ' : '  ✘ ';
    $detail = $result['pass'] || $result['detail'] === null ? '' : ' — ' . json_encode($result['detail'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo $mark . $result['name'] . $detail . "\n";
}

printf("\n%d checks, %d passed, %d failed.\n", count($results), count($results) - $failed, $failed);
exit($failed === 0 ? 0 : 1);
