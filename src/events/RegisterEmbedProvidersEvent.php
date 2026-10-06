<?php

namespace Tahadudhiya\SmartLinks\events;

use Tahadudhiya\SmartLinks\linktypes\embed\EmbedProviderInterface;
use yii\base\Event;

/**
 * Collects the providers Embed links can point at media on. It starts with the built-in ones,
 * which a handler may remove or replace as well as add to.
 */
class RegisterEmbedProvidersEvent extends Event
{
    /** @var list<EmbedProviderInterface> In the order a pasted URL is matched against them. */
    public array $providers = [];
}
