<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Parking System</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome (Untuk Ikon) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body { background-color: #f4f6f9; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        /* Navbar Hitam ala Jurnal */
        .navbar-custom {
            background-color: #222d32;
            padding: 0;
            border-bottom: 3px solid #00a65a; /* Garis Hijau di bawah menu */
        }
        
        .nav-link {
            color: #b8c7ce !important;
            padding: 15px 20px;
            font-weight: 500;
            border-right: 1px solid #2c3b41;
            transition: all 0.3s;
        }

        .nav-link:hover {
            background-color: #1e282c;
            color: white !important;
        }

        /* Tab Aktif (Hijau) */
        .nav-link.active {
            background-color: #00a65a !important;
            color: white !important;
            font-weight: bold;
        }

        /* Container Putih di tengah */
        .content-box {
            background: white;
            padding: 20px;
            margin-top: 20px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            border-top: 3px solid #00c0ef; /* Garis biru hiasan */
            border-radius: 3px;
        }
    </style>
    
    <!-- Refresh Otomatis tiap 5 detik (Real-time) -->
    <meta http-equiv="refresh" content="5">
</head>
<body>

<!-- Navigasi Tab -->
<nav class="navbar navbar-expand-lg navbar-custom">
    <div class="container-fluid p-0">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0 flex-row w-100">
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('parking.slots') ? 'active' : '' }}" 
                   href="{{ route('parking.slots') }}">
                   <i class="fas fa-th-large me-2"></i> Parking Slots
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('parking.incoming') ? 'active' : '' }}" 
                   href="{{ route('parking.incoming') }}">
                   <i class="fas fa-car me-2"></i> Incoming Car Data
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link {{ request()->routeIs('parking.outgoing') ? 'active' : '' }}" 
                   href="{{ route('parking.outgoing') }}">
                   <i class="fas fa-file-invoice-dollar me-2"></i> Outgoing Car Data
                </a>
            </li>
        </ul>
    </div>
</nav>

<div class="container">
    <!-- Di sini nanti Screen (Halaman) akan muncul -->
    @yield('content')
</div>

</body>
</html>