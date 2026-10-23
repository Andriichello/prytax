<?php

namespace Tests\Feature;

use Core\Models\Organization;
use Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Concerns\ActsInProfiles;
use Tests\TestCase;

/**
 * Class LocalizationTest.
 *
 * The API answers in the signed-in user's own language (`uk` or `en`), whatever the request asks for, and before
 * signing in in the language of the request (`Accept-Language`), English without one: its own messages, Laravel's
 * validation and sign-in messages alike. Every message of the application has its Ukrainian text in `lang/uk.json`.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature
 */
class LocalizationTest extends TestCase
{
    use ActsInProfiles;
    use RefreshDatabase;

    /**
     * Test that a wrong password is told in Ukrainian, when the request asks for it, and in English otherwise.
     *
     * @return void
     */
    public function testTellsAWrongPasswordInTheLanguageOfTheRequest(): void
    {
        $user = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        // English first: setting a locale changes `app.locale` for the rest of the test's application
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'These credentials do not match our records.']);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'], ['Accept-Language' => 'uk-UA,uk;q=0.9'])
            ->assertUnprocessable()
            ->assertHeader('Content-Language', 'uk')
            ->assertJsonValidationErrors(['email' => 'Неправильна пошта або пароль.']);
    }

    /**
     * Test that the application's own messages and Laravel's validation messages come in the user's own language,
     * whatever the request asks for.
     *
     * @return void
     */
    public function testAnswersInTheUsersOwnLanguageOverTheRequests(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $user = $this->actAs($profile);
        $account = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297']]);
        $invoice = $this->createFrozenInvoice($profile, $account);
        $this->recordReceipt($profile, $invoice, $account, ['amount' => '3100.00']);
        $pay = $this->profilePath($profile, "invoices/{$invoice->id}/pay");

        $user->forceFill(['locale' => 'uk'])->save();
        $this->postJson($pay, [], ['Accept-Language' => 'en'])
            ->assertUnprocessable()
            ->assertHeader('Content-Language', 'uk')
            ->assertJsonValidationErrors(['status' => 'Надходження покривають 3100.00 з 3892.50 USD, тож інвойс ще не оплачено.']);
        $this->postJson($this->profilePath($profile, 'accounts'), [], ['Accept-Language' => 'en'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'Заповніть це поле.']);

        $user->forceFill(['locale' => 'en'])->save();
        $this->postJson($pay, [], ['Accept-Language' => 'uk'])
            ->assertUnprocessable()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonValidationErrors(['status' => 'The receipts cover 3100.00 of 3892.50 USD, so the invoice isn\'t paid yet.']);
    }

    /**
     * Test that every message, which the application passes through `__()` or `trans_choice()`, has its Ukrainian
     * text.
     *
     * @return void
     */
    public function testTranslatesEveryMessageOfTheApplication(): void
    {
        $translations = json_decode(file_get_contents(lang_path('uk.json')), true);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
        $missing = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            foreach ($this->messagesOf(file_get_contents($file->getPathname())) as $message) {
                if (!array_key_exists($message, $translations)) {
                    $missing[] = $message;
                }
            }
        }

        $this->assertSame([], $missing);
    }

    /**
     * Get the messages, which the code passes to `__()` or `trans_choice()` as a literal string.
     *
     * @param string $code
     *
     * @return array<int, string>
     */
    private function messagesOf(string $code): array
    {
        $tokens = array_values(array_filter(token_get_all($code), fn ($token) => !is_array($token) || $token[0] !== T_WHITESPACE));
        $messages = [];

        foreach ($tokens as $index => $token) {
            $literal = $tokens[$index + 2] ?? null;

            if (is_array($token) && $token[0] === T_STRING && in_array($token[1], ['__', 'trans_choice'], true) && ($tokens[$index + 1] ?? null) === '('
                && is_array($literal) && $literal[0] === T_CONSTANT_ENCAPSED_STRING) {
                $messages[] = stripcslashes(substr($literal[1], 1, -1));
            }
        }

        return $messages;
    }
}
