<!DOCTYPE html>
<html>
<head>
    <title>Smart Parking Admin</title>

    <meta name="csrf-token" content="{{ csrf_token() }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="bg-light">

    <div class="d-flex">

        @include('layouts.sidebar')

        <main class="p-4 w-100">
            @yield('content')
        </main>

    </div>

</body>
</html>
