<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Session;
use Luster\Support\MemberSignIn;
use Luster\Tests\Unit\Support\RunsMemberAuthHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Update form's fallback token can stand in for a failed CSRF check only when
 * there's no sign-in. UpdateController::submit() ignores the token when there is one,
 * so any text in the field used to let a forged request change or delete a signed-in fan.
 *
 * The embed boots from the live config, so PHPUnit can't run it. The same cases run
 * through the real file on MariaDB in the release checks.
 */
final class UpdateFormCsrfTest extends TestCase
{
    use RunsMemberAuthHarness;

    private const LISTING = 1;
    private const FAN_A   = ['listing' => 1, 'email' => 'a@x.test', 'name' => 'Fan A', 'password' => 'a-password-1'];

    /** @var array<string, mixed> */
    private array $sessionBefore = [];

    protected function setUp(): void
    {
        $this->sessionBefore = $_SESSION ?? [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBefore;
    }

    // ------------------------------------------------------------------
    // The decision
    // ------------------------------------------------------------------

    /** @return array<string, array{string, array<string, mixed>, bool}> */
    public static function decisions(): array
    {
        return [
            'no sign-in, update token'      => ['none', ['_form_token_update' => 'x'], true],
            'no sign-in, delete token'      => ['none', ['_form_token_delete' => 'x'], true],
            'no sign-in, both tokens'       => ['none', ['_form_token_update' => 'x', '_form_token_delete' => 'y'], true],
            'no sign-in, no token'          => ['none', [], false],
            'no sign-in, empty tokens'      => ['none', ['_form_token_update' => '', '_form_token_delete' => ''], false],
            'no sign-in, a list as a token' => ['none', ['_form_token_update' => ['x'], '_form_token_delete' => ['y']], false],
            'no sign-in, one list, one real' => ['none', ['_form_token_update' => ['x'], '_form_token_delete' => 'y'], true],

            'signed in, update token' => ['signed in', ['_form_token_update' => 'x'], false],
            'signed in, delete token' => ['signed in', ['_form_token_delete' => 'x'], false],
            'signed in, both tokens'  => ['signed in', ['_form_token_update' => 'x', '_form_token_delete' => 'y'], false],
            'signed in, no token'     => ['signed in', [], false],

            // submit() goes by the address alone, so a bad stamp is still a sign-in here.
            'stale stamp, token' => ['stale stamp', ['_form_token_update' => 'x'], false],
            'no stamp, token'    => ['no stamp', ['_form_token_update' => 'x'], false],

            'signed in to another listing, token' => ['other listing', ['_form_token_update' => 'x'], true],
        ];
    }

    /** @param array<string, mixed> $post */
    #[DataProvider('decisions')]
    public function testWhenATokenExcusesAFailedCsrfCheck(string $state, array $post, bool $expected): void
    {
        $session = new Session();
        $stamp   = 'lstr_update_authed_stamp_' . self::LISTING;

        match ($state) {
            'none'          => null,
            'signed in'     => MemberSignIn::remember($session, self::LISTING, 'a@x.test', 'a-stored-hash'),
            'stale stamp'   => [MemberSignIn::remember($session, self::LISTING, 'a@x.test', 'a-stored-hash'), $session->set($stamp, 'stale')],
            'no stamp'      => [MemberSignIn::remember($session, self::LISTING, 'a@x.test', 'a-stored-hash'), $session->remove($stamp)],
            'other listing' => MemberSignIn::remember($session, self::LISTING + 1, 'a@x.test', 'a-stored-hash'),
        };

        self::assertSame($expected, MemberSignIn::formTokenExcusesCsrf($session, self::LISTING, $post));
    }

    // ------------------------------------------------------------------
    // What submit() does with whatever gets past that
    // ------------------------------------------------------------------

    public function testADummyTokenWithNoSignInChangesNothing(): void
    {
        $scenarios = [];
        foreach (['owned', 'enth'] as $layout) {
            foreach ([
                'update' => ['new_name' => 'Changed By Forgery', '_form_token_update' => 'x'],
                'delete' => ['delete_membership' => '1', '_form_token_delete' => 'x'],
            ] as $action => $post) {
                $scenarios["{$layout}, {$action}"] = ['layout' => $layout, 'members' => [self::FAN_A], 'steps' => [
                    ['do' => 'signed_in'],
                    ['do' => 'lose_session'],
                    ['do' => 'submit', 'post' => $post],
                ]];
            }
        }

        foreach ($this->runScenarios($scenarios) as $why => $r) {
            self::assertNull($r['error'], $why);
            self::assertCount(1, $r['members'][1], $why . ': still a member');
            self::assertSame('Fan A', $r['members'][1][0]['name'], $why);
            self::assertSame('', $r['mail'], $why);
            foreach ($r['form_tokens'] as $token) {
                self::assertNull($token['used_at'], $why . ': the real tokens stay usable');
            }
        }
    }

    public function testARealTokenForTheOtherActionChangesNothing(): void
    {
        $update = ['new_name' => 'Chosen'];
        $delete = ['delete_membership' => '1'];
        $cases  = [
            'a delete with the update token in its own field'   => [$delete, ['_form_token_update' => 'update']],
            'a delete with the update token in the delete field' => [$delete, ['_form_token_delete' => 'update']],
            'an update with the delete token in its own field'  => [$update, ['_form_token_delete' => 'delete']],
            'an update with the delete token in the update field' => [$update, ['_form_token_update' => 'delete']],
        ];

        $scenarios = [];
        foreach (['owned', 'enth'] as $layout) {
            foreach ($cases as $label => [$post, $fields]) {
                $scenarios["{$layout}: {$label}"] = ['layout' => $layout, 'members' => [self::FAN_A], 'steps' => [
                    ['do' => 'signed_in'],
                    ['do' => 'lose_session'],
                    ['do' => 'submit', 'post' => $post, 'tokens_in' => $fields],
                ]];
            }
        }

        foreach ($this->runScenarios($scenarios) as $why => $r) {
            self::assertNull($r['error'], $why);
            self::assertCount(1, $r['members'][1], $why . ': still a member');
            self::assertSame('Fan A', $r['members'][1][0]['name'], $why);
            self::assertCount(2, $r['form_tokens'], $why);
            foreach ($r['form_tokens'] as $token) {
                self::assertNull($token['used_at'], $why . ': neither token is used up');
            }
        }
    }

    public function testTheRightTokenStillRecoversALostSession(): void
    {
        $scenarios = [];
        foreach (['owned', 'enth'] as $layout) {
            $scenarios["{$layout}, update"] = ['layout' => $layout, 'members' => [self::FAN_A], 'steps' => [
                ['do' => 'signed_in'],
                ['do' => 'lose_session'],
                ['do' => 'submit', 'post' => ['new_name' => 'Chosen'], 'tokens_in' => ['_form_token_update' => 'update']],
            ]];
            $scenarios["{$layout}, delete"] = ['layout' => $layout, 'members' => [self::FAN_A], 'steps' => [
                ['do' => 'signed_in'],
                ['do' => 'lose_session'],
                ['do' => 'submit', 'post' => ['delete_membership' => '1'], 'tokens_in' => ['_form_token_delete' => 'delete']],
            ]];
        }

        foreach ($this->runScenarios($scenarios) as $why => $r) {
            self::assertNull($r['error'], $why);
            if (str_ends_with($why, 'update')) {
                self::assertSame('Chosen', $r['members'][1][0]['name'], $why);
            } else {
                self::assertSame([], $r['members'][1], $why);
            }
        }
    }

    // ------------------------------------------------------------------
    // The embed
    // ------------------------------------------------------------------

    public function testTheEmbedLeavesTheDecisionToMemberSignIn(): void
    {
        $embed = (string) file_get_contents(dirname(__DIR__, 2) . '/public/embed/update.php');

        self::assertStringContainsString('MemberSignIn::formTokenExcusesCsrf($_lusterApp->getSession(), $_lusterListingId, $_POST)', $embed);
        self::assertStringNotContainsString("\$_POST['_form_token_", $embed, "the embed doesn't look at the token fields itself");
    }
}
