<?php

// Renders the Smart Links field's editor with Craft's real templates, and the field controller's
// real responses to a paste, for the browser test (tests/browser/run.php). It needs the host
// project's Craft, so it runs inside DDEV; it writes only into tests/browser/.build/.

use craft\events\RegisterComponentTypesEvent;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\fields\LinkEditor;
use Tahadudhiya\SmartLinks\fields\SmartLinkField;
use Tahadudhiya\SmartLinks\models\InvalidLinkValue;
use Tahadudhiya\SmartLinks\models\LinkCollection;
use Tahadudhiya\SmartLinks\services\LinkTypes;
use Tahadudhiya\SmartLinks\SmartLinks;
use Tahadudhiya\SmartLinks\Tests\_support\linktypes\FakeEmailType;
use Tahadudhiya\SmartLinks\Tests\_support\linktypes\FakeEntryType;
use Tahadudhiya\SmartLinks\Tests\_support\linktypes\FakeUrlType;
use yii\base\Event;

require __DIR__ . '/../integration-bootstrap.php';

// The test-only types stand in for the built-in types of the same handles.
Event::on(LinkTypes::class, LinkTypes::EVENT_REGISTER_LINK_TYPES, static function(RegisterComponentTypesEvent $event): void {
    $event->types = [FakeUrlType::class, FakeEntryType::class, FakeEmailType::class];
});

$build = __DIR__ . '/.build';

if (!is_dir($build) && !mkdir($build, 0777, true) && !is_dir($build)) {
    fwrite(STDERR, "Could not create $build.\n");
    exit(1);
}

// A UID, as a saved field has, so the editor carries a context and offers copying and pasting.
$field = new SmartLinkField(['handle' => 'links', 'name' => 'Links', 'uid' => StringHelper::UUID(), 'types' => ['url', 'entry', 'email'], 'maxLinks' => 4]);
$value = SmartLinks::getInstance()->getLinks()->getNormalizer()->normalize([
    ['uid' => '11111111-1111-4111-8111-111111111111', 'type' => 'url', 'data' => ['url' => 'https://example.com/a'], 'label' => 'First', 'attributes' => ['custom' => ['data-x' => '1']]],
    ['uid' => '22222222-2222-4222-8222-222222222222', 'type' => 'email', 'data' => ['address' => 'b@example.com'], 'label' => 'Second'],
    ['uid' => '33333333-3333-4333-8333-333333333333', 'type' => 'url', 'data' => ['url' => 'https://example.com/c'], 'label' => 'Third'],
])->value;

if (!$value instanceof LinkCollection) {
    fwrite(STDERR, "The editor's value is not valid.\n");
    exit(1);
}

$view = Craft::$app->getView();
$view->startJsBuffer();
$html = $view->namespaceInputs(fn() => $field->getInputHtml($value, null), 'fields');

// A field with one link type, which has no type selector.
$single = new SmartLinkField(['handle' => 'one', 'name' => 'One', 'uid' => StringHelper::UUID(), 'types' => ['url'], 'maxLinks' => 2]);
$html .= $view->namespaceInputs(fn() => $single->getInputHtml(new LinkCollection(), null), 'fields');

// A field whose stored value cannot be read, which the editor keeps whole until it is cleared.
$unreadable = new SmartLinkField(['handle' => 'kept', 'name' => 'Kept', 'uid' => StringHelper::UUID(), 'types' => ['url']]);
$stored = ['version' => 9, 'links' => [['anything' => 'else']]];

try {
    SmartLinks::getInstance()->getLinks()->getSerializer()->deserialize($stored);
    fwrite(STDERR, "The unreadable value was read.\n");
    exit(1);
} catch (LinkValidationException $exception) {
    $html .= $view->namespaceInputs(fn() => $unreadable->getInputHtml(InvalidLinkValue::fromStorage($stored, $exception->errors), null), 'fields');
}

$js = (string)$view->clearJsBuffer(false);

// What the controller renders for a pasted link, for the same editor.
$view->setNamespace('fields');
$editor = LinkEditor::forField($field, null);
$view->setNamespace(null);
$pasted = SmartLinks::getInstance()->getLinks()->getNormalizer()->normalize([['type' => 'url', 'data' => ['url' => 'https://example.com/pasted'], 'label' => 'Pasted']])->value;

if (!$pasted instanceof LinkCollection) {
    fwrite(STDERR, "The pasted value is not valid.\n");
    exit(1);
}

// One ID across every file, so the runner can tell it has this render's files and not the last
// one's: DDEV syncs files to the host a moment after they are written.
$buildId = StringHelper::UUID();
file_put_contents("$build/editor.html", "$html\n<!-- build $buildId -->");
file_put_contents("$build/init.js", "$js\n// build $buildId");
file_put_contents("$build/responses.json", Json::encode([
    'build' => $buildId,
    // Rendered for paste number 9; the page's stand-in for the server renames it for the paste
    // number each request carries, as the controller would render it.
    'paste' => ['links' => $editor->newLinksHtml($pasted, ['new9-1'])],
    'refused' => ['errors' => ['Link 1: Entry links are not allowed in this field.'], 'codes' => [['path' => 'links[0].type', 'code' => 'notSupported']]],
    'clipboard' => Json::encode(['version' => 1, 'links' => [['type' => 'url', 'data' => ['url' => 'https://example.com/pasted'], 'label' => 'Pasted']]]),
]));

// The runner waits for exactly this render's files.
echo "Rendered build $buildId into $build.\n";
