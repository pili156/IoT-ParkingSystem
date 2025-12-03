@extends('layout')

@section('content')
<div class="content-box">
    <h3 class="mb-4">Incoming Car Data</h3>
    
    <div class="table-responsive">
        <table class="table table-bordered table-striped table-hover">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Car No (Plat)</th>
                    <th>Date & Time (Entry)</th>
                </tr>
            </thead>
            <tbody>
                @forelse($incoming as $car)
                <tr>
                    <td>{{ $car->id }}</td>
                    <td class="fw-bold text-primary">{{ $car->car_no }}</td>
                    <td>{{ $car->datetime->format('Y-m-d H:i:s') }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="3" class="text-center text-muted">Belum ada data mobil masuk.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection