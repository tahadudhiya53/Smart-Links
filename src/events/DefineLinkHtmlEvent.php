<?php

namespace Tahadudhiya\SmartLinks\events;

use Tahadudhiya\SmartLinks\models\LinkValue;
use Tahadudhiya\SmartLinks\models\RenderedLink;
use yii\base\Event;

/**
 * Lets the markup of one rendered link be replaced.
 */
class DefineLinkHtmlEvent extends Event
{
    /** The link being rendered. */
    public LinkValue $link;

    /** Its final href, text and attributes, unescaped. */
    public RenderedLink $rendered;

    /**
     * @var string The markup. Whatever replaces it must escape every value it writes, because
     * the rendered values are raw.
     */
    public string $html = '';
}
