# Plan: store the OpenAI API key encrypted, per instance

Status: approved by Taylor 2026-11-12, to be built now, ahead of ADV04.

## Goal

The operator can set, replace and forget an OpenAI API key from the Profile page, on
any device, including the iPad over Tailscale. The key is stored encrypted on the
user record, the same way as the saved SpaceTraders account token. The app never sends
it back to the browser. Production and the dev site each hold their own key, or none,
because they have separate databases. Nothing calls OpenAI yet apart from checking the
key when it's saved. ADV04 will read the key through `User::openAiApiKey()`.

## Model to copy

Mirror the saved SpaceTraders account token (commits `9463f0e`, `349ad31`):

- `app/Models/User.php`: the `spacetraders_account_token` `encrypted` cast, `$hidden`,
  `savedAccountToken()` (it forgets the token on `DecryptException`), `rememberAccountToken()`
  and `forgetAccountToken()`.
- `database/migrations/2026_11_12_000002_add_saved_account_token_to_users.php`
- `tests/Feature/SavedAccountTokenTest.php` for test style.

## Backend

1. **Migration** `2026_11_12_000003_add_openai_api_key_to_users.php`: add a nullable `text`
   column `openai_api_key` and a nullable `timestamp` column `openai_api_key_saved_at`.
   `down()` drops both.
2. **`User`**:
   - Add both columns to `$hidden`. Cast them `encrypted` and `immutable_datetime`.
   - `openAiApiKey(): ?string`: returns the decrypted key, or forgets it and returns
     null on `DecryptException`.
   - `hasOpenAiApiKey(): bool`.
   - `rememberOpenAiApiKey(string $key): void`, which also sets `saved_at`.
   - `forgetOpenAiApiKey(): void`.
   - `openAiApiKeyHint(): ?string`, which returns the last 4 characters only.
3. **`App\Services\OpenAiKeyVerifier`**, the only class that talks to OpenAI in this change:
   - `verify(string $key): void` sends `GET https://api.openai.com/v1/models` with a
     Bearer token through Laravel `Http`, with a 10-second timeout and no retries.
     Listing models is free.
   - On 401 or 403, it throws `ValidationException` on `openai_api_key` with
     "OpenAI rejected this key. Check it in your OpenAI project and paste it again."
   - On a connection error, a timeout, 429 or 5xx, it throws `ValidationException` with
     "Could not reach OpenAI to check this key, so it was not saved. Try again."
   - Any 2xx means the key is valid.
   - Never log or include the key in exception messages or context.
4. **`App\Http\Controllers\OpenAiKeyController`** (auth routes):
   - `PUT /profile/openai-key`, named `profile.openai-key.update`:
     - Validate `openai_api_key` as required, a string, max 300, and
       `regex:/\Ask-[A-Za-z0-9_\-]{16,}\z/`, with the message "Enter an OpenAI API key.
       It starts with sk-." Trim it first.
     - Then verify it and remember it.
     - Redirect back to `profile.edit` with no flash containing the key.
   - `DELETE /profile/openai-key`, named `profile.openai-key.destroy`: forgets the key and
     redirects back.
5. **`ProfileController::edit`**: pass the prop
   `openAiKey => ['saved' => bool, 'savedAt' => ISO-8601|null, 'hint' => 'last4'|null]`.
   The full key must never appear in any Inertia prop, page HTML, log or error.

## Frontend: `resources/js/Pages/Profile/Partials/UpdateOpenAiKeyForm.vue`

Add it to `Profile/Edit.vue` in its own `app-panel`, between Update Password and Delete
Account. Build it the same way as `UpdatePasswordForm.vue`, and copy the token field from
`Agent/CreateAgent.vue` (the `account_token` field):

- **Header:** `<h2 class="text-lg font-medium text-ink">OpenAI API key</h2>`, followed by
  `<p class="mt-1 text-sm text-muted">`: "Used only by the adviser on this server. Stored
  encrypted and never shown again. Use a key from an OpenAI project with a spending limit."
- **Status line** (`text-sm text-muted`):
  - When saved: "Key ending in …{hint}, saved {formatLocalTime(savedAt)}." Use
    `formatLocalTime` from `@/dateTime.js`.
  - Otherwise: "No key saved."
- **Field:** `InputLabel` labelled "API key" (or "New API key" when one is saved). Use
  `TextInput` with id `openai_api_key`, `type` password or text through a Show/Hide toggle,
  and copy all of these from `account_token`: `data-1p-ignore`, `data-lpignore="true"`,
  `data-bwignore`, `data-form-type="other"`, `spellcheck="false"`, `autocapitalize="off"`
  and `autocomplete="new-password"`. Also copy its `aria-invalid` and `aria-describedby`
  wiring.
- **Show/Hide button:** the same `app-link mt-2 text-sm` button, with `aria-pressed`,
  `aria-controls` and an `aria-label` of "Show API key" or "Hide API key".
- **Forget key:** an `app-link ml-4 mt-2 text-sm` button, shown only when a key is saved. It
  calls `router.delete(route('profile.openai-key.destroy'), { preserveScroll: true })`.
- **Errors:** `InputError` with id `openai_api_key-error`.
- **Actions row:** `flex items-center gap-4` with a `PrimaryButton` labelled "Save key"
  (or "Replace key" when one is saved), disabled while processing. Add the same "Saved."
  transition as `UpdatePasswordForm`.
- **After any response:** clear the field and hide it again, using `onFinish` to reset.

## Tests: `tests/Feature/OpenAiKeyTest.php`

Use `Http::preventStrayRequests()` and fakes. Cover these cases:

- **A valid key is saved:**
  - The verifier sends one GET to `/v1/models` with the Bearer header.
  - The raw column (`getRawOriginal`) differs from the key, and decrypts to it.
  - `saved_at` is set.
- **Invalid format:** rejected with no HTTP request sent.
- **401 response:** not saved, with the rejection message.
- **Connection exception:** not saved, with the unreachable message.
- **429 response:** not saved.
- **Replace:** a new key overwrites the old one.
- **Forget:** clears both columns.
- **The key never leaks:**
  - The Inertia props for `profile.edit` include `saved`, `savedAt` and `hint`.
  - The full key string appears nowhere in the response content, before or after saving.
  - `toArray()` / `toJson()` of the user omit both columns.
- **Corrupt ciphertext:** written straight to the raw column, it shows as not saved, and
  `openAiApiKey()` clears it.
- **Guests:** they get redirected from both routes.
- **Other users' keys:** another user's key is unaffected. The routes act only on
  `$request->user()`.

Run the full suite with `scripts\windows\php.ps1 vendor/bin/phpunit`, and build with
`pnpm build`. Note: `public/build` is tracked and stale. Don't commit build output.

## Docs

- `docs/openai-automation-adviser-plan.md`, ADV04 section: replace "Keep the API key in
  server configuration" with "Read the API key from the operator's encrypted setting via
  `User::openAiApiKey()`. It is set on the Profile page. Recommend a dedicated OpenAI
  project with a spending limit." Keep the in-app $5 per day cap.
- `README.md`: add one sentence where the saved account token is described.

## Out of scope

Any model call besides key verification, the $5 ledger, ADV04 itself, and storing other
secrets.
