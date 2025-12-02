<!DOCTYPE html>
<html>
<head>
    <title>Parking System</title>
    <style>
        body { font-family: Arial; margin: 0; }
        .navbar { display: flex; background: #333; color: white; }
        .navbar a {
            flex: 1;
            padding: 20px;
            text-align: center;
            text-decoration: none;
            color: white;
            font-size: 20px;
        }
        .navbar a.active { background: #4CAF50; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; text-align: center; border: 1px solid #ddd; }
        th { background: #eee; font-size: 20px; }
    </style>
</head>
<body>

<div class="navbar">
    <a href="/parking-slot" class="{{ request()->is('parking-slot') ? 'active' : '' }}">Parking Slot</a>
    <a href="/incoming-car" class="{{ request()->is('incoming-car') ? 'active' : '' }}">Incoming Car</a>
    <a href="/outgoing-car" class="{{ request()->is('outgoing-car') ? 'active' : '' }}">Outgoing Car</a>
</div>

<div style="padding: 20px;">
    @yield('content')
</div>

</body>
</html>