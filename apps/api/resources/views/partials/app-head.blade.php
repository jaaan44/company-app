{{-- Shared <head> for the portal and the sign-in page (Phase 34F, F-6): installable as an app, no third-party resources. --}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f4f6fa" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0e1525" media="(prefers-color-scheme: dark)">
<meta name="application-name" content="Company App">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Company App">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="manifest" href="/manifest.json">
<link rel="icon" href="/icons/icon-192.png" type="image/png" sizes="192x192">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
@vite(['resources/css/app.css', 'resources/js/app.js'])
