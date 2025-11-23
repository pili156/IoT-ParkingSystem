@extends('layouts.master')

@section('content')
    <h2 class="mb-4">Dashboard</h2>

    <div class="row">

        <div class="col-md-4 mb-3">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5>Total Kendaraan</h5>
                    <h2>{{ $vehicles->count() }}</h2>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-3">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5>Kendaraan di Area</h5>
                    <h2>{{ $active }}</h2>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-3">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5>Total Pendapatan</h5>
                    <h2>Rp {{ number_format($income, 0, ',', '.') }}</h2>
                </div>
            </div>
        </div>

    </div>
@endsection