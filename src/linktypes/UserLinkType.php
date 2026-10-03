<?php

namespace Tahadudhiya\SmartLinks\linktypes;

use Craft;
use craft\base\ElementInterface;
use craft\elements\User;

/**
 * A link to a user.
 *
 * Craft gives users no URLs of their own, so a user link leads nowhere (no URL) unless the site
 * defines one with `Element::EVENT_DEFINE_URL`, e.g. for author pages. A user is live when enabled,
 * as Craft's default user query has it, whatever the account's status (e.g. suspended).
 * Its default label is the user's full name only, never a username or email address, which a
 * page should not reveal unless its author writes them in.
 */
class UserLinkType extends BaseElementLinkType
{
    public function handle(): string
    {
        return 'user';
    }

    public function elementType(): string
    {
        return User::class;
    }

    protected function defaultLabel(ElementInterface $element): ?string
    {
        /** @var User $element */
        $name = $element->fullName;

        return $name !== null && DataInput::isText($name) ? $name : null;
    }

    public function craftLinkTypes(): array
    {
        // Craft's own Link field has no user links.
        return [];
    }

    protected function selectionCriteria(): array
    {
        // Users have no URIs.
        return [];
    }

    /**
     * Only someone who may view users chooses from them, as only they are shown a chosen one.
     */
    protected function sources(): string|array
    {
        $user = Craft::$app->getUser()->getIdentity();

        return $user !== null && ($user->admin || $user->can('viewUsers')) ? ['*'] : [];
    }

    public function gqlElementResolver(): ?string
    {
        return \craft\gql\resolvers\elements\User::class;
    }

    public function gqlCanQueryElements(): bool
    {
        return \craft\helpers\Gql::canQueryUsers();
    }
}
