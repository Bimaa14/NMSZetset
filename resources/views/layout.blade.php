<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="Dashboard pemantauan dan manajemen jaringan FTTH ZTE OLT.">
    <meta name="color-scheme" content="dark light">
    <title>@yield('title', 'Dashboard') · LUMINA NMS</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <script>try { if(localStorage.getItem('nms-theme-version')!=='2'){localStorage.setItem('nms-theme','light');localStorage.setItem('nms-theme-version','2');} const t = localStorage.getItem('nms-theme') || 'light'; document.documentElement.dataset.theme = t === 'system' ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : t; } catch {}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    @yield('body')
</body>
</html>
