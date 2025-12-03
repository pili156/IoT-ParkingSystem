@extends('layout')

@section('content')
<!-- Tambahkan CSS DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

<div class="content-box">
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-2">
        <h3 class="m-0 text-dark">
            <i class="fas fa-car text-success me-2"></i> Incoming Car Data
        </h3>
    </div>

    <!-- Tabel Data -->
    <div class="table-responsive">
        <!-- ID 'incomingTable' penting untuk Script di bawah -->
        <table id="incomingTable" class="table table-striped table-bordered" style="width:100%">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Car No (Plat)</th>
                    <th>Date & Time (Entry)</th>
                </tr>
            </thead>
            <tbody>
                @foreach($incoming as $car)
                <tr>
                    <td>{{ $car->id }}</td>
                    <td class="fw-bold">{{ $car->car_no }}</td>
                    <!-- Format tanggal biar rapi -->
                    <td>{{ \Carbon\Carbon::parse($car->datetime)->format('Y-m-d H:i:s') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<!-- Tambahkan Script jQuery & DataTables -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
    // Inisialisasi DataTables
    $(document).ready(function() {
        $('#incomingTable').DataTable({
            // Opsi tambahan biar mirip banget sama jurnal
            "order": [[ 2, "desc" ]], // Urutkan berdasarkan Waktu (Kolom ke-3) terbaru
            "language": {
                "search": "Search:", // Label search
                "lengthMenu": "Show _MENU_ entries" // Label show entries
            }
        });
    });
</script>
@endsection