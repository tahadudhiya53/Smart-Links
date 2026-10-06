<?php

namespace Tahadudhiya\SmartLinks\services;

use craft\base\Component;
use craft\helpers\Html;
use craft\helpers\Template;
use Tahadudhiya\SmartLinks\errors\LinkResolutionException;
use Tahadudhiya\SmartLinks\errors\LinkValidationException;
use Tahadudhiya\SmartLinks\events\DefineLinkHtmlEvent;
use Tahadudhiya\SmartLinks\links\LinkNormalizer;
use Tahadudhiya\SmartLinks\links\LinkRenderer;
use Tahadudhiya\SmartLinks\links\LinkResolver;
use Tahadudhiya\SmartLinks\links\LinkSerializer;
use Tahadudhiya\SmartLinks\links\LinkValidator;
use Tahadudhiya\SmartLinks\linktypes\LinkTypeSet;
use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\RenderedLink;
use Tahadudhiya\SmartLinks\models\ResolvedLink;
use Tahadudhiya\SmartLinks\SmartLinks;
use Twig\Markup;

/**
 * The link core, wired to the registered link types, and what is done with a link beyond its
 * value: resolving it in a site and rendering it.
 *
 * Every part is built from the same set of link types, so normalizing, validating, storing,
 * resolving and rendering always agree on which types exist.
 */
class Links extends Component
{
    /**
     * @event DefineLinkHtmlEvent Lets the markup {@see html()} writes for a link be replaced.
     */
    public const EVENT_DEFINE_LINK_HTML = 'defineLinkHtml';

    private ?LinkValidator $validator = null;
    private ?LinkNormalizer $normalizer = null;
    private ?LinkSerializer $serializer = null;
    private ?LinkResolver $resolver = null;
    private ?LinkRenderer $renderer = null;

    public function getValidator(): LinkValidator
    {
        return $this->validator ??= new LinkValidator($this->types());
    }

    public function getNormalizer(): LinkNormalizer
    {
        return $this->normalizer ??= new LinkNormalizer($this->types(), $this->getValidator());
    }

    public function getSerializer(): LinkSerializer
    {
        return $this->serializer ??= new LinkSerializer($this->types(), $this->getValidator());
    }

    /**
     * @throws LinkResolutionException if the link cannot be resolved at all.
     */
    public function resolve(LinkValue $link, int $siteId): ResolvedLink
    {
        return ($this->resolver ??= new LinkResolver($this->types()))->resolve($link, $siteId);
    }

    /**
     * The link's final href, text and attributes in a site, or null when it leads nowhere.
     *
     * @throws LinkResolutionException if the link cannot be resolved at all.
     * @throws LinkValidationException if the link is invalid.
     */
    public function render(LinkValue $link, int $siteId): ?RenderedLink
    {
        return $this->renderFor($link, $this->resolve($link, $siteId));
    }

    /**
     * The link as an `<a>` tag in a site, or null when it leads nowhere. Every value is
     * HTML-encoded here, and nowhere before, so none is encoded twice or not at all.
     *
     * Templates that need other markup can build it from {@see render()} instead, and
     * {@see EVENT_DEFINE_LINK_HTML} can replace this markup everywhere.
     *
     * @throws LinkResolutionException if the link cannot be resolved at all.
     * @throws LinkValidationException if the link is invalid.
     */
    public function html(LinkValue $link, int $siteId): ?Markup
    {
        return $this->htmlFor($link, $this->resolve($link, $siteId));
    }

    /**
     * What a link renders as, for where it has already been resolved, or null when it leads
     * nowhere.
     *
     * @throws LinkValidationException if the link is invalid.
     */
    public function renderFor(LinkValue $link, ResolvedLink $resolved): ?RenderedLink
    {
        return ($this->renderer ??= new LinkRenderer($this->getValidator()))->render($link, $resolved);
    }

    /**
     * The link as an `<a>` tag, for where it has already been resolved, or null when it leads
     * nowhere.
     *
     * @throws LinkValidationException if the link is invalid.
     */
    public function htmlFor(LinkValue $link, ResolvedLink $resolved): ?Markup
    {
        $rendered = $this->renderFor($link, $resolved);

        if ($rendered === null) {
            return null;
        }

        $event = new DefineLinkHtmlEvent([
            'link' => $link,
            'rendered' => $rendered,
            'html' => Html::tag('a', Html::encode($rendered->text), ['href' => $rendered->href] + $rendered->attributes),
        ]);

        $this->trigger(self::EVENT_DEFINE_LINK_HTML, $event);

        return Template::raw($event->html);
    }

    private function types(): LinkTypeSet
    {
        return SmartLinks::getInstance()->getLinkTypes()->getTypeSet();
    }
}
