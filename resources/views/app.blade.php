<!DOCTYPE html>
<html lang="uk">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
        <meta name="theme-color" content="#F4F3EE">

        {{-- The theme before anything is drawn, so a dark one doesn't flash white; composables/useTheme.ts takes over --}}
        <script>
            (function () {
                var theme = null;

                try {
                    theme = window.localStorage.getItem('prytax.theme');
                } catch (error) {}

                var dark = theme === 'dark' || (theme !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);

                document.documentElement.dataset.theme = dark ? 'dark' : 'light';

                if (dark) {
                    document.querySelector('meta[name="theme-color"]').setAttribute('content', '#191A1C');
                }
            })();
        </script>
        <style>
            html[data-theme='dark'] {
                background: #191a1c;
                color-scheme: dark;
            }
        </style>

        <title>{{ config('app.name') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.ts'])
    </head>
    <body>
        <div id="app"></div>
    </body>
</html>
