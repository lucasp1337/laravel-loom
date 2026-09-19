<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Loom') · Loom</title>
    <link rel="stylesheet" href="{{ \Lucasp\Loom\Ui\Asset::CSS->url() }}">
</head>
<body>
<main class="loom-state">
    @yield('content')
</main>
<script src="{{ \Lucasp\Loom\Ui\Asset::JS->url() }}"></script>
</body>
</html>
