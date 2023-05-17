<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Request;

class ForwarderController extends Controller
{
    public function forward(Request $request)
    {
        $request_all = $request->all();
        $method = $request_all['method'];
        $url = $request_all['url'];
        $data = $request_all['data'];
        $headers = $request_all['headers'] ?? [];


        // forward the request to the Space Traders API
        $response = Http::withHeaders($headers)->$method('https://api.spacetraders.io/v2'.$url, $data);
        info($response);

        return $response;
    }
}
