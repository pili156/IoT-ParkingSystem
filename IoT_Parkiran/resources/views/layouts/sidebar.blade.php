<div class="bg-dark text-white p-3" style="width: 250px; min-height: 100vh;">

    <h4 class="mb-4">🚗 Smart Parking</h4>

    <a href="{{ route('dashboard') }}" class="text-white d-block mb-3">📊 Dashboard</a>
    <a href="{{ route('slots') }}" class="text-white d-block mb-3">🅿️ Slot Parkir</a>
    <a href="{{ route('vehicles') }}" class="text-white d-block mb-3">🚘 Kendaraan</a>

    <form action="{{ route('logout') }}" method="POST">
        @csrf
        <button class="btn btn-danger mt-4 w-100">Logout</button>
    </form>

</div>
