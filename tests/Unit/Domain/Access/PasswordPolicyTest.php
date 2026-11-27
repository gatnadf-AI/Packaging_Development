<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Access;

use App\Domain\Access\PasswordPolicy;
use App\Rules\PasswordNotLikeIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * V-102 (FSD-104, FSD-201) — the strength rule the three password writers
 * share, checked against the four documented clauses and nothing else.
 *
 * Unit-level rather than through the screens on purpose: the feature tests
 * prove each screen calls this, this test proves what "this" accepts. A rule
 * that only exists behind a form cannot be shown to a reviewer.
 */
class PasswordPolicyTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function acceptedPasswords(): array
    {
        return [
            'ten characters, both cases, digits' => ['KemasanBaru2026', 'rnd.staf1@ppm.test'],
            'long sentence style' => ['TigaKotakKayu2026!', 'admin@npd-ppm.local'],
            'digits in the middle' => ['SdsLapis3Helai', 'sds.koordinator@ppm.test'],
        ];
    }

    #[DataProvider('acceptedPasswords')]
    public function test_the_documented_shape_is_accepted(string $password, string $email): void
    {
        $validator = Validator::make(
            ['password' => $password],
            ['password' => PasswordPolicy::rules($email)]
        );

        $this->assertFalse($validator->fails(), $validator->errors()->first('password'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusedPasswords(): array
    {
        return [
            'nine characters' => ['Kemasan26', 'rnd.staf1@ppm.test'],
            'eight characters' => ['Kemasan6', 'rnd.staf1@ppm.test'],
            'no uppercase' => ['kemasanbaru2026', 'rnd.staf1@ppm.test'],
            'no lowercase' => ['KEMASANBARU2026', 'rnd.staf1@ppm.test'],
            'no digit' => ['KemasanBaruHelai', 'rnd.staf1@ppm.test'],
            // Long, mixed, numeric: the identity rule is the only thing that
            // can refuse this one, so the case proves the four clauses are four.
            'the email local part' => ['RndStaf1Satu2026', 'rndstaf1satu2026@ppm.test'],
        ];
    }

    #[DataProvider('refusedPasswords')]
    public function test_every_other_shape_is_refused(string $password, string $email): void
    {
        $validator = Validator::make(
            ['password' => $password],
            ['password' => PasswordPolicy::rules($email)]
        );

        $this->assertTrue($validator->fails(), "'{$password}' should not have passed");
    }

    public function test_the_identity_rule_is_case_insensitive_equality_and_nothing_more(): void
    {
        $fails = function (string $password, string $email): bool {
            $validator = Validator::make(
                ['password' => $password],
                ['password' => [new PasswordNotLikeIdentity($email)]]
            );

            return $validator->fails();
        };

        // Stated scope, tested so that nobody later "improves" it into a
        // substring rule that rejects a legitimate password without warning.
        $this->assertTrue($fails('rnd.staf1', 'RND.Staf1@ppm.test'));
        $this->assertTrue($fails('  rnd.staf1  ', 'rnd.staf1@ppm.test'));
        $this->assertFalse($fails('Kemasanrnd.staf1Lapis', 'rnd.staf1@ppm.test'));
        $this->assertFalse($fails('KemasanBaru2026', 'not-an-email'));
        $this->assertFalse($fails('KemasanBaru2026', ''));
    }

    public function test_a_missing_or_blank_password_is_refused_rather_than_treated_as_unchanged(): void
    {
        $this->assertTrue(Validator::make([], ['password' => PasswordPolicy::rules('a@b.test')])->fails());
        $this->assertTrue(Validator::make(['password' => ''], ['password' => PasswordPolicy::rules('a@b.test')])->fails());
    }

    public function test_the_explanation_matches_the_rule_it_sits_under(): void
    {
        $description = PasswordPolicy::describe();

        $this->assertStringContainsString((string) PasswordPolicy::MIN_LENGTH, $description);
        $this->assertStringContainsString('huruf besar', $description);
        $this->assertStringContainsString('angka', $description);
        $this->assertStringContainsString('email', $description);

        // OQ-03: the sentence a user reads is Indonesian.
        $this->assertStringNotContainsString('password', strtolower($description));
    }

    public function test_the_minimum_length_constant_is_the_number_the_validator_uses(): void
    {
        $this->assertSame(10, PasswordPolicy::MIN_LENGTH);

        // Shaped so that length is the only clause in play: both carry an upper
        // letter, lower letters and a digit, so one of them can only fail on the
        // count.
        $nine = str_repeat('a', 7).'A1';
        $ten = str_repeat('a', 8).'A1';

        $this->assertSame(9, strlen($nine));
        $this->assertSame(10, strlen($ten));

        $this->assertTrue(Validator::make(['password' => $nine], ['password' => PasswordPolicy::rules()])->fails());
        $this->assertFalse(Validator::make(['password' => $ten], ['password' => PasswordPolicy::rules()])->fails());
    }
}
