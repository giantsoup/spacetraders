<?php

namespace App\Http\Controllers;

use App\Services\SpaceTradersClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ForwarderController extends Controller
{
    public function forward(Request $request, SpaceTradersClient $client): Response
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(['get', 'post', 'put', 'patch', 'delete'])],
            'url' => ['required', 'string', 'max:2048', 'regex:~^/(?:[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*)?$~'],
            'data' => ['sometimes', 'array'],
            'headers' => ['sometimes', 'array:Authorization'],
            'headers.Authorization' => ['sometimes', 'string', 'regex:/\ABearer [A-Za-z0-9\-._~+\/]+=*\z/'],
        ]);

        abort_if($request->isMethod('get') && $data['method'] !== 'get', 405);

        $authorization = $data['headers']['Authorization'] ?? null;

        try {
            $upstream = $client->request(
                $data['method'],
                $data['url'],
                $data['data'] ?? [],
                $authorization === null ? null : substr($authorization, 7),
            );
        } catch (ConnectionException $exception) {
            return response([
                'error' => ['message' => 'Could not connect to SpaceTraders. Please try again later.'],
            ], 503, ['Cache-Control' => 'no-store']);
        }

        $headers = ['Cache-Control' => 'no-store'];

        foreach ([
            'Content-Type', 'Retry-After', 'X-RateLimit-Type', 'X-RateLimit-Limit',
            'X-RateLimit-Limit-Burst', 'X-RateLimit-Limit-Per-Second',
            'X-RateLimit-Remaining', 'X-RateLimit-Reset',
        ] as $header) {
            if ($upstream->hasHeader($header)) {
                $headers[$header] = $upstream->header($header);
            }
        }

        return response($upstream->body(), $upstream->status(), $headers);
    }
}
