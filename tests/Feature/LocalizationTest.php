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
 * The API answers in Ukrainian, when the request asks for it (`Accept-Language: uk`, as the SPA does), whatever the
 * user's own locale, and in English otherwise: its own messages, Laravel's validation and sign-in messages alike.
 * Every message of the application has its Ukrainian text in `lang/uk.json`.
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
     * Test that the application's own messages and Laravel's validation messages come in Ukrainian, though the
     * user's own locale is English.
     *
     * @return void
     */
    public function testAnswersInUkrainianWhateverTheUsersOwnLocale(): void
    {
        Storage::fake('local');
        $profile = $this->createProfile();
        $this->actAs($profile)->forceFill(['locale' => 'en'])->save();
        $account = $this->createAccount($profile);
        $this->cacheRates(['USD' => ['2026-10-05' => '42.2297']]);
        $invoice = $this->createFrozenInvoice($profile, $account);
        $this->recordReceipt($profile, $invoice, $account, ['amount' => '3100.00']);
        $headers = ['Accept-Language' => 'uk'];

        $this->postJson($this->profilePath($profile, "invoices/{$invoice->id}/pay"), [], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'Надходження покривають 3100.00 з 3892.50 USD, тож інвойс ще не оплачено.']);

        $this->postJson($this->profilePath($profile, 'accounts'), [], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'Заповніть це поле.']);
    }

    /**
     * Test that every message, which the application passes through `__()`, has its Ukrainian text.
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
     * Get the messages, which the code passes to `__()` as a literal string.
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

            if (is_array($token) && $token[0] === T_STRING && $token[1] === '__' && ($tokens[$index + 1] ?? null) === '('
                && is_array($literal) && $literal[0] === T_CONSTANT_ENCAPSED_STRING) {
                $messages[] = stripcslashes(substr($literal[1], 1, -1));
            }
        }

        return $messages;
    }
}
