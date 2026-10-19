<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="/storefront/app.css">
    @yield('head')
</head>
<body @yield('body-attributes')>
    @yield('body')
</body>
</html>
