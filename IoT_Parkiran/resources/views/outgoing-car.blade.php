@extends('layout')

@section('content')
<!-- DataTables CSS -->
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
                    <th>Entry Time</th>
                    <th>Exit Time</th>
                    <th>Total Time</th>
                    <th>Bill (IDR)</th>
                </tr>
            </thead>
            <tbody>
                @forelse($outgoing as $car)
                <tr>
                    <td>{{ $car->id }}</td>
                    <td class="fw-bold">{{ $car->car_no }}</td>
                    <td>{{ $car->entry_time->format('Y-m-d H:i:s') }}</td>
                    <td>{{ $car->exit_time->format('Y-m-d H:i:s') }}</td>
                    <td>{{ $car->total_time }} Jam</td>
                    <td class="fw-bold text-success">Rp {{ number_format($car->bill, 0, ',', '.') }}</td>
                </tr>
                @empty
    <tr>
    <td class="text-muted text-center">-</td>
    <td class="text-muted text-center">-</td>
    <td class="text-muted text-center">-</td>
    <td class="text-muted text-center">-</td>
    <td class="text-muted text-center">-</td>
    <td class="text-muted text-center">Belum ada data transaksi keluar.</td>
</tr>
@endempty

            </tbody>
        </table>
    </div>
</div>
<<<<<<< HEAD
@endsection

<script>
$(document).ready(function() {
    $('#outgoingTable').DataTable();
});
</script>
=======

<!-- jQuery + DataTables Script -->
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

<script>
$(document).ready(function() {
    $('#outgoingTable').DataTable({
        "order": [[ 3, "desc" ]], // Urutkan berdasarkan Exit Time (kolom ke-4)
        "language": {
            "search": "Search:",
            "lengthMenu": "Show _MENU_ entries",
            "zeroRecords": "No matching data found",
            "info": "Showing _START_ to _END_ of _TOTAL_ entries",
        }
    });
});
</script>

@endsection
>>>>>>> ijal
