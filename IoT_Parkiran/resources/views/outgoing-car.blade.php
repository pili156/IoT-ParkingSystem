@extends('layout')

@section('content')
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">

<div class="content-box">
    <div class="d-flex justify-content-between align-items-center mb-4 border-bottom pb-2">
        <h3 class="m-0 text-dark">
            <i class="fas fa-sign-out-alt text-danger me-2"></i> Outgoing Car Data
        </h3>
    </div>

    <div class="table-responsive">
        <table id="outgoingTable" class="table table-striped table-bordered" style="width:100%">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Car No</th>
                    <th>Date & Time</th> <th>Bill (IDR)</th>
                    <th>Total Time</th>
                </tr>
            </thead>
            <tbody>
                @foreach($outgoing as $car)
                <tr>
                    <td>{{ $car->id }}</td>
                    <td class="fw-bold">{{ $car->car_no }}</td>
                    
                    <td>{{ $car->exit_time ? \Carbon\Carbon::parse($car->exit_time)->format('Y-m-d H:i:s') : '-' }}</td>
                    
                    <td class="fw-bold text-success">Rp {{ number_format($car->bill, 0, ',', '.') }}</td>
                    <td>{{ $car->total_time }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
    // Inisialisasi DataTables
    $(document).ready(function() {
        $('#outgoingTable').DataTable({
            // Urutkan berdasarkan Date & Time (Kolom ke-3 / index 2) terbaru
            "order": [[ 2, "desc" ]], 
            "language": {
                "search": "Search:", // Label search
                "lengthMenu": "Show _MENU_ entries" // Label show entries
            }
        });
    });
</script>
@endsection