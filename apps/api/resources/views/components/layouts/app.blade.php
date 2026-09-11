@props(['title' => 'Company App — Admin'])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title ?? 'Company App — Admin' }}</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: #f5f5f5;
                color: #1f2933;
                margin: 0;
            }
            .container {
                max-width: 420px;
                margin: 8vh auto;
                padding: 2rem;
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            }
            h1 {
                font-size: 1.25rem;
                margin-bottom: 1.5rem;
            }
            label {
                display: block;
                font-size: 0.875rem;
                font-weight: 600;
                margin-bottom: 0.25rem;
            }
            input[type="email"],
            input[type="password"] {
                width: 100%;
                padding: 0.5rem;
                margin-bottom: 1rem;
                border: 1px solid #cbd2d9;
                border-radius: 4px;
                box-sizing: border-box;
                font-size: 1rem;
            }
            button {
                width: 100%;
                padding: 0.625rem;
                background: #3730a3;
                color: #fff;
                border: none;
                border-radius: 4px;
                font-size: 1rem;
                cursor: pointer;
            }
            button:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            .error {
                color: #b91c1c;
                font-size: 0.875rem;
                margin-bottom: 1rem;
            }
        </style>
    </head>
    <body>
        <div class="container">
            {{ $slot }}
        </div>
    </body>
</html>
