<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="dark">
        <meta name="theme-color" content="#0a111c">
        <title>@yield('title') | SpaceTraders</title>
        {{-- Error screens remain usable when the JavaScript bundle is unavailable. --}}
        <style>
            :root { color-scheme: dark; background: #0a111c; color: #e4edf7; font: 15px/1.6 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
            * { box-sizing: border-box; }
            body { margin: 0; }
            a { color: #a6f3d0; text-underline-offset: 4px; }
            a:focus-visible { outline: 3px solid #f7dc91; outline-offset: 4px; }
            header { padding: 20px 28px; border-bottom: 2px solid #34455d; background: #101b2b; }
            header a { display: inline-flex; align-items: center; min-height: 44px; font-weight: 800; letter-spacing: .08em; text-decoration: none; }
            main { max-width: 760px; margin: 64px auto; padding: 0 24px; overflow-wrap: anywhere; }
            section { padding: 28px; border: 2px solid #34455d; background: #101b2b; box-shadow: 5px 5px 0 #05090f; }
            .code { margin: 0; color: #a6f3d0; font-size: 13px; letter-spacing: .12em; }
            h1 { margin: 12px 0; font-size: clamp(28px, 5vw, 40px); line-height: 1.3; }
            p { color: #aebed2; }
            .return { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; margin-top: 16px; padding: 10px 16px; border: 1px solid #a6f3d0; background: #a6f3d0; color: #0a241d; font-size: 13px; font-weight: 700; text-decoration: none; }
            .return:hover { background: #c4ffe4; }
            @media (max-width: 600px) { header { padding: 16px; } main { margin: 32px auto; padding: 0 16px; } section { padding: 20px 16px; } }
        </style>
    </head>
    <body class="app-error">
        <header><a href="/">ST // AGENT COMMAND</a></header>
        <main>
            <section aria-labelledby="error-heading">
                <p class="code">REQUEST STATUS // @yield('code')</p>
                <h1 id="error-heading">@yield('title')</h1>
                <p>This request could not be completed. Return to the command terminal to continue.</p>
                <a href="/" class="return">Return to command →</a>
            </section>
        </main>
    </body>
</html>
