<?php

// Run by LinkTypesTest in a process of its own, to see Smart Links as a project without Craft
// Commerce sees it, in one of two ways:
//
// - CRAFT_DISABLED_PLUGINS=commerce: Commerce is installed but disabled;
// - SMARTLINKS_COMMERCE_MISSING=1: Commerce's code is gone, as if its package were removed while
//   the project still lists it. Every attempt to load one of its classes is refused and recorded,
//   with whether Smart Links' own code made it.
//
// It reads one stored value (argv[1], JSON) with a field allowing every type, builds the GraphQL
// types, uses the other link types, and prints what it found as JSON.

use craft\helpers\Json;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Schema;
use Tahadudhiya\SmartLinks\fields\SmartLinkField;
use Tahadudhiya\SmartLinks\gql\SmartLinkGql;
use Tahadudhiya\SmartLinks\models\InvalidLinkValue;
use Tahadudhiya\SmartLinks\models\LinkCollection;
use Tahadudhiya\SmartLinks\SmartLinks;

$refused = [];

if (getenv('SMARTLINKS_COMMERCE_MISSING') === '1') {
    $loader = require dirname(__DIR__, 4) . '/vendor/autoload.php';
    $loader->unregister();
    $smartLinks = dirname(__DIR__, 2) . '/src/';

    spl_autoload_register(static function(string $class) use ($loader, &$refused, $smartLinks): void {
        if (stripos($class, 'craft\\commerce\\') === 0) {
            $bySmartLinks = false;

            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                $bySmartLinks = $bySmartLinks || str_starts_with($frame['file'] ?? '', $smartLinks);
            }

            $refused[] = ['class' => $class, 'bySmartLinks' => $bySmartLinks];

            return;
        }

        $loader->loadClass($class);
    }, true, true);
}

require __DIR__ . '/../integration-bootstrap.php';

$plugin = SmartLinks::getInstance();
$types = $plugin->getLinkTypes()->getTypeSet();
$stored = Json::decode($argv[1] ?? 'null');
// A field that allowed products before Commerce went away.
$field = new SmartLinkField(['handle' => 'commerceAbsentProbe', 'types' => array_merge($types->handles(), ['commerce-product'])]);
$value = $field->normalizeValue($stored, null);

// The other types still work.
$working = $plugin->getLinks()->getNormalizer()->normalize([
    ['type' => 'url', 'data' => ['url' => 'https://example.com/']],
    ['type' => 'email', 'data' => ['address' => 'hello@example.com']],
    ['type' => 'social', 'data' => ['network' => 'github', 'account' => 'craftcms']],
    ['type' => 'embed', 'data' => ['url' => 'https://youtu.be/dQw4w9WgXcQ']],
])->value;
$hrefs = $working instanceof LinkCollection
    ? array_map(static fn($link): ?string => $plugin->getLinks()->render($link, (int)Craft::$app->getSites()->getPrimarySite()->id)?->href, $working->links)
    : null;

// The GraphQL types build, and form a valid schema.
$schema = new Schema(['query' => new ObjectType(['name' => 'Query', 'fields' => ['link' => ['type' => SmartLinkGql::linkType()]]])]);
$schema->assertValid();

echo Json::encode([
    'commerceLoaded' => Craft::$app->getPlugins()->getPlugin('commerce') !== null,
    'handles' => $types->handles(),
    'kept' => $value instanceof InvalidLinkValue && $value->stored && $value->input === $stored,
    'storedAgain' => $field->serializeValue($value, null) === $stored,
    'settingsHtml' => $field->getSettingsHtml(),
    'hrefs' => $hrefs,
    'gqlDataTypes' => ($union = $schema->getType('SmartLinkData')) instanceof UnionType ? array_map(static fn($type): string => $type->name, $union->getTypes()) : [],
    // Every Commerce class PHP has loaded while Smart Links did all of the above.
    'commerceClasses' => array_values(preg_grep('/^craft\\\\commerce\\\\/i', get_declared_classes()) ?: []),
    'refused' => $refused,
]);
