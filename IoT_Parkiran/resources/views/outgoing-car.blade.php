@extends('layout')

@section('content')
<div class="content-box">
    <h3 class="mb-4">Outgoing Car Data</h3>
    
    <div class="table-responsive">
        <table id="outgoingTable" class="table table-bordered table-striped">
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
                    <td>{{ $car->entry_time->format('H:i:s') }}</td>
                    <td>{{ $car->exit_time->format('H:i:s') }}</td>
                    <td>{{ $car->total_time }} Jam</td>
                    <td class="fw-bold text-success">
                        Rp {{ number_format($car->bill, 0, ',', '.') }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="text-center text-muted">Belum ada data transaksi keluar.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

<script>
$(document).ready(function() {
    $('#outgoingTable').DataTable();
});
</script>
