<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class OpenAiKeyVerifier
{
    public function verify(#[\SensitiveParameter] string $key): void
    {
        try {
            $response = Http::withToken($key)->timeout(10)->connectTimeout(10)->withoutRedirecting()
                ->get('https://api.openai.com/v1/models');
        } catch (ConnectionException) {
            throw ValidationException::withMessages([
                'openai_api_key' => 'Could not reach OpenAI to check this key, so it was not saved. Try again.',
            ]);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw ValidationException::withMessages([
                'openai_api_key' => 'OpenAI rejected this key. Check it in your OpenAI project and paste it again.',
            ]);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'openai_api_key' => 'Could not reach OpenAI to check this key, so it was not saved. Try again.',
            ]);
        }
    }
}
