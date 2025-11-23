@extends('layouts.master')

@section('content')

<h2 class="mb-3">Data Kendaraan</h2>

<div class="card shadow-sm">
    <div class="card-body">

        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Plat</th>
                    <th>Masuk</th>
                    <th>Keluar</th>
                    <th>Biaya</th>
                </tr>
            </thead>

            <tbody>
                @foreach($vehicles as $v)
                <tr>
                    <td>{{ $v->plate }}</td>
                    <td>{{ $v->time_in }}</td>
                    <td>{{ $v->time_out ?? '-' }}</td>
                    <td>
                        {{ $v->fee ? 'Rp ' . number_format($v->fee, 0, ',', '.') : '-' }}
                    </td>
                </tr>
                @endforeach
            </tbody>

        </table>

    </div>
</div>

@endsection
