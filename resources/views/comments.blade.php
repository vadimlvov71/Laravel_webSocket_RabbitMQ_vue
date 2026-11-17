<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comments</title>
    <script>
        window.__config = @json(['recaptchaSiteKey' => config('services.recaptcha.site_key')]);
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 text-gray-900 antialiased">
    <div id="app"></div>
</body>
</html>