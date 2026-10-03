<?php

namespace Tahadudhiya\SmartLinks\linktypes;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\services\ElementSources;
use GraphQL\Type\Definition\Type;
use Tahadudhiya\SmartLinks\enums\LinkFeature;
use Tahadudhiya\SmartLinks\enums\ResolutionStatus;
use Tahadudhiya\SmartLinks\enums\ValidationErrorCode as Code;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\helpers\LinkUrls;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\ResolvedLink;
use Tahadudhiya\SmartLinks\models\TargetIdentity;
use Tahadudhiya\SmartLinks\models\ValidationError;

/**
 * A link to a Craft element, by ID, resolved by Craft's own element queries.
 *
 * A link holds the element's ID, and for a localized element type optionally the site whose
 * version it links to (see {@see ElementLinkData}). Everything else (title, URL, status) is read
 * from the element when the link is resolved, so it is never out of date.
 *
 * Resolving follows Craft's own idea of each element type: an element is live when its type's
 * element query finds it with its default status (e.g. `live` for entries, `enabled` for users),
 * and its URL is the element's own. Links always point at canonical elements: an element query
 * never returns a draft or revision unless asked, and authors cannot choose one.
 *
 * Extend this class to link to any other element type, and register the subclass like any link
 * type.
 */
abstract class BaseElementLinkType implements ElementLinkTypeInterface, LinkResolverInterface, CraftLinkConverterInterface, GqlLinkTypeInterface
{
    private const KEYS = ['elementId', 'siteId'];

    /**
     * @return class-string<ElementInterface>
     */
    abstract public function elementType(): string;

    public function displayName(): string
    {
        return $this->elementType()::displayName();
    }

    public function supportedFeatures(): array
    {
        return array_values(array_filter(LinkFeature::cases(), static fn(LinkFeature $feature): bool => $feature !== LinkFeature::DOWNLOAD));
    }

    /**
     * Also checks the chosen element, as far as it exists: an ID that belongs to another kind of
     * element, or to a draft or revision, is refused. An ID that no longer exists is accepted,
     * so a link whose target was deleted can still be saved with the rest of the content, and
     * resolves as missing.
     */
    public function normalizeData(array $input): LinkTypeDataInterface
    {
        $errors = [];
        $data = $this->read($input, $errors);

        if ($data !== null) {
            array_push($errors, ...$this->targetProblems($data));
        }

        DataInput::throwIfAny($errors);

        /** @var ElementLinkData $data */
        return $data;
    }

    public function dataFromArray(array $stored): LinkTypeDataInterface
    {
        if (array_filter($stored, 'is_int') !== $stored) {
            throw new LinkValidationException([new ValidationError('', Code::NOT_CANONICAL, 'Stored element IDs are integers.')]);
        }

        // Reading what was stored never consults the element: one deleted since is still read,
        // and resolves as missing.
        $errors = [];
        $data = $this->read($stored, $errors);
        DataInput::throwIfAny($errors);

        /** @var ElementLinkData $data */
        return $data;
    }

    public function validateData(LinkTypeDataInterface $data): array
    {
        if (!$data instanceof ElementLinkData) {
            return [new ValidationError('', Code::WRONG_TYPE, 'This is not {type} link data.', ['type' => $this->handle()])];
        }

        $errors = [];

        if ($data->elementId <= 0) {
            $errors[] = new ValidationError('elementId', Code::INVALID, 'An element ID is a positive whole number.');
        }

        if ($data->siteId !== null && ($data->siteId <= 0 || !$this->isLocalized())) {
            $errors[] = $this->siteError($data->siteId <= 0);
        }

        return $errors;
    }

    public function elementId(LinkTypeDataInterface $data): int
    {
        /** @var ElementLinkData $data */
        return $data->elementId;
    }

    public function elementSiteId(LinkTypeDataInterface $data, int $siteId): ?int
    {
        /** @var ElementLinkData $data */
        return $this->isLocalized() ? ($data->siteId ?? $siteId) : null;
    }

    /**
     * None by default: an element type's GraphQL resolver must be named for GraphQL to show the
     * linked element, so that the schema's read permissions are applied to it.
     */
    public function gqlElementResolver(): ?string
    {
        return null;
    }

    /**
     * Never, by default, as there is no resolver to read the element through.
     */
    public function gqlCanQueryElements(): bool
    {
        return false;
    }

    public function gqlDataFields(): array
    {
        return [
            'elementId' => Type::nonNull(Type::int()),
            'siteId' => ['type' => Type::int(), 'description' => 'The site chosen for the link, or null when it follows the content’s site.'],
        ];
    }

    public function targetIdentity(LinkTypeDataInterface $data, int $sourceElementId, int $sourceSiteId): TargetIdentity
    {
        /** @var ElementLinkData $data */
        return TargetIdentity::forElement($this->handle(), $this->elementType(), $data->elementId, $this->elementSiteId($data, $sourceSiteId));
    }

    public function resolver(): LinkResolverInterface
    {
        return $this;
    }

    public function resolve(LinkValue $link, int $siteId): ResolvedLink
    {
        /** @var ElementLinkData $data */
        $data = $link->data;
        // A non-localized element is the same in every site; its URL is the resolving site's.
        $targetSiteId = $this->elementSiteId($data, $siteId) ?? $siteId;

        // A pinned site that has since been deleted has no version of anything.
        if (Craft::$app->getSites()->getSiteById($targetSiteId, true) === null) {
            return new ResolvedLink(ResolutionStatus::MISSING);
        }

        $class = $this->elementType();
        $element = $class::find()->id($data->elementId)->siteId($targetSiteId)->one();

        if ($element === null) {
            // Not live in that site. Whether it exists there at all decides between disabled and
            // missing (deleted, trashed, never in that site, or not this kind of element).
            $element = $class::find()->id($data->elementId)->siteId($targetSiteId)->status(null)->one();

            return $element === null
                ? new ResolvedLink(ResolutionStatus::MISSING)
                : new ResolvedLink(ResolutionStatus::DISABLED, defaultLabel: $this->defaultLabel($element));
        }

        $url = $this->url($element);

        if ($url === null) {
            return new ResolvedLink(ResolutionStatus::NO_URL, defaultLabel: $this->defaultLabel($element));
        }

        // An element is this site's own content, wherever its URL is served from.
        return new ResolvedLink(ResolutionStatus::RESOLVED, LinkUrls::applySuffix($url, $link->urlSuffix), false, $this->defaultLabel($element));
    }

    public function inputHtml(LinkTypeDataInterface|array|null $value, ?int $siteId): string
    {
        $view = Craft::$app->getView();
        $raw = $value instanceof ElementLinkData ? $value->toArray() : (is_array($value) ? $value : []);
        $elementId = DataInput::positiveId($raw['elementId'] ?? null);
        $pinnedSiteId = DataInput::positiveId($raw['siteId'] ?? null);
        $class = $this->elementType();
        // The site whose version the link leads to: the chosen one, else the content's.
        $targetSiteId = $this->isLocalized() ? ($pinnedSiteId ?? $siteId) : null;
        $elements = [];
        $notice = null;

        if ($elementId !== null) {
            [$elements, $notice] = $this->chosenElement($elementId, $targetSiteId);
        }

        $html = Cp::elementSelectFieldHtml([
            'label' => $this->displayName(),
            'id' => 'element',
            'elementType' => $class,
            'elements' => $elements,
            'single' => true,
            'sources' => $this->sources(),
            'criteria' => $this->selectionCriteria(),
            'modalSettings' => ['siteId' => $targetSiteId ?? $siteId],
        ]);

        // The element select keeps its own state; the ID is posted from this input, so a link
        // whose element can't be shown still keeps it.
        $html .= Html::hiddenInput('elementId', DataInput::inputValue($raw, 'elementId'), ['id' => 'elementId']);

        if ($notice !== null) {
            $html .= Html::tag('p', Html::encode($notice), ['class' => 'warning with-icon', 'data' => ['smartlinks-element-notice' => true]]);
        }

        $html .= $this->siteInputHtml($raw);

        $view->registerJsWithVars(fn(string $select, string $input) => <<<JS
(() => {
  const select = $('#' + $select).data('elementSelect');
  const \$input = $('#' + $input);
  if (!select) {
    return;
  }
  select.on('selectElements', (event) => {
    \$input.val(event.elements[0].id).trigger('change');
    \$input.siblings('[data-smartlinks-element-notice]').remove();
  });
  select.on('removeElements', () => \$input.val('').trigger('change'));
})();
JS, [$view->namespaceInputId('element'), $view->namespaceInputId('elementId')]);

        return $html;
    }

    /**
     * What the editor shows of a chosen element: the element itself only to a user Craft lets
     * view it, in a site that user may work in; otherwise only what is true of the link.
     *
     * @return array{list<ElementInterface>, string|null} The element to show, if any, and a notice.
     */
    private function chosenElement(int $elementId, ?int $targetSiteId): array
    {
        $class = $this->elementType();
        $params = ['type' => $class::lowerDisplayName(), 'id' => $elementId];
        $query = $class::find()->id($elementId)->status(null);
        $element = ($targetSiteId !== null ? (clone $query)->siteId($targetSiteId) : $query)->one();

        if ($element !== null) {
            return $this->canShow($element)
                ? [[$element], null]
                // Its title, or anything else about it, is not this user's to see.
                : [[], Craft::t('smart-links', 'You can’t view the {type} this link points at (ID {id}).', $params)];
        }

        // Not in the site the link leads to. Whether it exists anywhere decides what is said.
        $elsewhere = $targetSiteId !== null ? $query->site('*')->unique()->one() : null;

        if ($elsewhere === null) {
            return [[], Craft::t('smart-links', 'The {type} this link points at (ID {id}) no longer exists. Choose another, or remove the link.', $params)];
        }

        $site = Craft::$app->getSites()->getSiteById((int)$targetSiteId, true);
        $params['site'] = $site !== null ? Craft::t('site', $site->getName()) : (string)$targetSiteId;

        return [[], Craft::t('smart-links', 'The {type} this link points at (ID {id}) is not in the {site} site, so the link leads nowhere there.', $params)];
    }

    private function canShow(ElementInterface $element): bool
    {
        if (!Craft::$app->getElements()->canView($element)) {
            return false;
        }

        // A localized element is shown only in a site the user may work in.
        return !$element::isLocalized() || in_array((int)$element->siteId, array_map('intval', Craft::$app->getSites()->getEditableSiteIds()), true);
    }

    public function craftLinkTypes(): array
    {
        $refHandle = $this->elementType()::refHandle();

        return $refHandle !== null ? [$refHandle] : [];
    }

    /**
     * Reads Craft's element reference tag (`{entry:12@1:url}`). A site in it is kept: Craft
     * links that site's version, as a pinned site does here. Without one, Craft prefers the
     * current site, which is what no site means here.
     */
    public function dataFromCraftLink(string $value): array
    {
        $refHandle = $this->elementType()::refHandle();

        if ($refHandle === null || !preg_match('/^\{' . preg_quote($refHandle, '/') . ':(\d+)(?:@(\d+))?:url\}$/', $value, $match)) {
            throw new LinkValidationException([new ValidationError('elementId', Code::INVALID, 'This is not a link to {type}.', ['type' => $this->elementType()::pluralLowerDisplayName()])]);
        }

        return isset($match[2]) ? ['elementId' => (int)$match[1], 'siteId' => (int)$match[2]] : ['elementId' => (int)$match[1]];
    }

    /**
     * Whether links name a site: only a localized element has a version per site.
     */
    protected function isLocalized(): bool
    {
        return $this->elementType()::isLocalized();
    }

    /**
     * The element's URL. Craft's own, which honours `Element::EVENT_DEFINE_URL`.
     */
    protected function url(ElementInterface $element): ?string
    {
        return $element->getUrl();
    }

    /**
     * What a link shows when its author gave no label: the element's title.
     */
    protected function defaultLabel(ElementInterface $element): ?string
    {
        $title = $element->title ?? null;

        // A title that is not text without control characters cannot be a label; the link then
        // shows its URL, as any unlabelled link does.
        return is_string($title) && DataInput::isText($title) ? $title : null;
    }

    /**
     * The element sources authors choose from: the native sources of the user's own element
     * index, which Craft limits to what the user may view (sections, groups, product types). So
     * the picker never lists an element the editor would then refuse to show, as Craft's own
     * entry link type does by default.
     *
     * @return string|list<string>
     */
    protected function sources(): string|array
    {
        $sources = Craft::$app->getElementSources()->getSources($this->elementType(), ElementSources::CONTEXT_INDEX);

        return array_values(array_map(
            static fn(array $source): string => (string)$source['key'],
            array_filter($sources, static fn(array $source): bool => ($source['type'] ?? null) === ElementSources::TYPE_NATIVE && isset($source['key'])),
        ));
    }

    /**
     * Narrows what authors can choose: elements with URLs, which the user may work with (Craft's
     * `editable`, e.g. other authors' entries only with the permission to view them).
     *
     * @return array<string, mixed>
     */
    protected function selectionCriteria(): array
    {
        return ['uri' => ':notempty:', 'editable' => true];
    }

    /**
     * @param array<mixed> $input
     * @param list<ValidationError> $errors
     */
    private function read(array $input, array &$errors): ?ElementLinkData
    {
        array_push($errors, ...DataInput::unknownKeys($input, self::KEYS));
        $rawId = $input['elementId'] ?? null;
        $elementId = DataInput::positiveId($rawId);

        if ($rawId === null || $rawId === '') {
            $errors[] = new ValidationError('elementId', Code::MISSING, 'Choose the {type} to link to.', ['type' => $this->elementType()::lowerDisplayName()]);
        } elseif ($elementId === null) {
            $errors[] = new ValidationError('elementId', Code::INVALID, 'An element ID is a positive whole number.');
        }

        $rawSite = $input['siteId'] ?? null;
        $siteId = null;

        if ($rawSite !== null && $rawSite !== '') {
            $siteId = DataInput::positiveId($rawSite);

            if ($siteId === null || !$this->isLocalized()) {
                $errors[] = $this->siteError($siteId === null);
            }
        }

        return $errors === [] && $elementId !== null ? new ElementLinkData($elementId, $siteId) : null;
    }

    private function siteError(bool $malformed): ValidationError
    {
        return $malformed
            ? new ValidationError('siteId', Code::INVALID, 'A site ID is a positive whole number.')
            : new ValidationError('siteId', Code::NOT_SUPPORTED, '{type} are the same in every site, so a link to one cannot name a site.', ['type' => ucfirst($this->elementType()::pluralLowerDisplayName())]);
    }

    /**
     * What is wrong with the element and site an author chose, as far as they exist.
     *
     * @return list<ValidationError>
     */
    private function targetProblems(ElementLinkData $data): array
    {
        $errors = [];

        if ($data->siteId !== null && Craft::$app->getSites()->getSiteById($data->siteId, true) === null) {
            $errors[] = new ValidationError('siteId', Code::INVALID, 'There is no site with the ID {id}.', ['id' => $data->siteId]);
        }

        $row = (new Query())
            ->select(['type', 'draftId', 'revisionId'])
            ->from(Table::ELEMENTS)
            ->where(['id' => $data->elementId])
            ->one();

        if ($row === null) {
            return $errors;
        }

        if (!is_a((string)$row['type'], $this->elementType(), true)) {
            $errors[] = new ValidationError('elementId', Code::INVALID, 'Element {id} is not one of the {type} this link can point at.', ['id' => $data->elementId, 'type' => $this->elementType()::pluralLowerDisplayName()]);
        } elseif ($row['draftId'] !== null || $row['revisionId'] !== null) {
            $errors[] = new ValidationError('elementId', Code::INVALID, 'Element {id} is a draft or revision. Link to the {type} itself.', ['id' => $data->elementId, 'type' => $this->elementType()::lowerDisplayName()]);
        }

        return $errors;
    }

    /**
     * Which site's version to link to, for a localized element type in a multi-site install.
     * Elsewhere, a site already chosen is kept as it is, never dropped.
     *
     * @param array<mixed> $raw
     */
    private function siteInputHtml(array $raw): string
    {
        $value = DataInput::inputValue($raw, 'siteId');

        if (!$this->isLocalized()) {
            return '';
        }

        if (!Craft::$app->getIsMultiSite()) {
            return $value !== '' ? Html::hiddenInput('siteId', $value) : '';
        }

        $options = [['label' => Craft::t('smart-links', 'The site the link appears in'), 'value' => '']];

        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            $options[] = ['label' => Craft::t('site', $site->getName()), 'value' => (string)$site->id];
        }

        if ($value !== '' && !in_array($value, array_column($options, 'value'), true)) {
            $options[] = ['label' => Craft::t('smart-links', 'A site that no longer exists ({id})', ['id' => $value]), 'value' => $value];
        }

        return Cp::selectFieldHtml([
            'label' => Craft::t('smart-links', 'Site'),
            'instructions' => Craft::t('smart-links', 'Which site’s version of the {type} to link to.', ['type' => $this->elementType()::lowerDisplayName()]),
            'id' => 'siteId',
            'name' => 'siteId',
            'options' => $options,
            'value' => $value,
        ]);
    }
}
