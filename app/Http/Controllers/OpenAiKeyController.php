<?php

namespace App\Http\Controllers;

use App\Services\OpenAiKeyVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class OpenAiKeyController extends Controller
{
    public function update(Request $request, OpenAiKeyVerifier $verifier): RedirectResponse
    {
        $key = $request->input('openai_api_key');
        // Remove the secret before any validation exception can flash request input.
        $request->replace($request->except('openai_api_key'));
        $request->query->remove('openai_api_key');

        $validated = Validator::make([
            'openai_api_key' => is_string($key) ? trim($key) : $key,
        ], [
            'openai_api_key' => ['required', 'string', 'max:300', 'regex:/\Ask-[A-Za-z0-9_\-]{16,}\z/'],
        ], [
            'openai_api_key' => 'Enter an OpenAI API key. It starts with sk-.',
        ])->validate();

        $verifier->verify($validated['openai_api_key']);
        $request->user()->rememberOpenAiApiKey($validated['openai_api_key']);

        return to_route('profile.edit');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->forgetOpenAiApiKey();

        return to_route('profile.edit');
    }
}
