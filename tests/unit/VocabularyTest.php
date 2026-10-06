<?php

namespace Tahadudhiya\SmartLinks\Tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\SmartLinks\enums\HealthFailure;
use Tahadudhiya\SmartLinks\enums\HealthState;

/**
 * The words Smart Links' health model is made of: what the evidence says about a target
 * (state), and why a check ended without an answer (failure). Health results are only as honest
 * as these distinctions, so they are asserted rather than left to whoever reads the enums next.
 */
class VocabularyTest extends TestCase
{
    public function testTheHealthStatesAreExactlyTheSixTheProductNames(): void
    {
        // A new state fails here first, and then has to take a side in `conclusiveness()`.
        self::assertSame(['healthy', 'redirect', 'broken', 'unavailable', 'blocked', 'unknown'], HealthState::values());
    }

    /**
     * Every state, whether it settles if the target works for a visitor, and why.
     *
     * @return array<string, array{HealthState, bool}>
     */
    public static function conclusiveness(): array
    {
        return [
            // The target was judged to work.
            'healthy settles that it works' => [HealthState::HEALTHY, true],
            // A redirect to a working answer is working, not broken.
            'a redirect settles that it works' => [HealthState::REDIRECT, true],
            // The target was judged not to have what was asked for.
            'broken settles that it does not' => [HealthState::BROKEN, true],
            // It could not be reached, or failed on its side: it may work a moment later.
            'unavailable leaves it open' => [HealthState::UNAVAILABLE, false],
            // A response came back, but it refused the checker, not necessarily a visitor.
            'blocked leaves it open, though a response came back' => [HealthState::BLOCKED, false],
            // Not checked yet, or the check learnt nothing. Unknown is never healthy.
            'unknown leaves it open' => [HealthState::UNKNOWN, false],
        ];
    }

    #[DataProvider('conclusiveness')]
    public function testAStateIsConclusiveOnlyWhenItSettlesWhetherTheTargetWorks(HealthState $state, bool $conclusive): void
    {
        self::assertSame($conclusive, $state->isConclusive());
    }

    public function testAFailureNeverClaimsMoreThanThatNoAnswerCame(): void
    {
        // No failure can make a target broken, healthy, redirected or blocked: those need an
        // answer from the target, and a failure means there was none.
        $states = [];

        foreach (HealthFailure::cases() as $failure) {
            $states[$failure->value] = $failure->state();
        }

        self::assertSame([
            'timeout' => HealthState::UNAVAILABLE,
            'connectionFailed' => HealthState::UNAVAILABLE,
            'dnsFailed' => HealthState::UNAVAILABLE,
            'refusedByPolicy' => HealthState::UNKNOWN,
            'tooManyRedirects' => HealthState::UNKNOWN,
        ], $states);

        // So a failure can never settle whether the target works.
        foreach ($states as $failure => $state) {
            self::assertFalse($state->isConclusive(), $failure);
        }
    }
}
