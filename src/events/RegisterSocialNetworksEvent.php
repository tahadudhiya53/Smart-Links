<?php

namespace Tahadudhiya\SmartLinks\events;

use Tahadudhiya\SmartLinks\linktypes\social\SocialNetworkInterface;
use yii\base\Event;

/**
 * Collects the social networks Social links can point at. It starts with the built-in ones,
 * which a handler may remove or replace as well as add to.
 */
class RegisterSocialNetworksEvent extends Event
{
    /** @var list<SocialNetworkInterface> In the order authors are offered them. */
    public array $networks = [];
}
