<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErrorPageStyleTest extends TestCase
{
    public static function statuses(): array
    {
        return array_map(fn ($status) => [$status], [401, 402, 403, 404, 419, 429, 500, 503]);
    }

    #[DataProvider('statuses')]
    public function test_html_errors_use_the_dark_fallback_without_changing_status(int $status): void
    {
        config(['app.debug' => false]);
        Route::get('/_test/error-style', fn () => abort($status));

        $this->get('/_test/error-style')
            ->assertStatus($status)
            ->assertSee('class="app-error"', false)
            ->assertSee('color-scheme: dark', false)
            ->assertSee('Return to command')
            ->assertDontSee('<script', false);
    }

    public function test_json_errors_keep_the_json_response_contract(): void
    {
        config(['app.debug' => false]);

        $this->getJson('/missing-style-test-page')
            ->assertNotFound()
            ->assertJsonStructure(['message'])
            ->assertDontSee('class="app-error"', false);
    }
}
