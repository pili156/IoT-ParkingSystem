@extends('layout')

@section('content')
<h2>Outgoing Car</h2>

<table>
    <tr>
        <th>ID</th>
        <th>Car No</th>
        <th>Date & Time</th>
        <th>Total Time</th>
        <th>Bill</th>
    </tr>

    @foreach ($outgoing as $car)
    <tr>
        <td>{{ $car->id }}</td>
        <td>{{ $car->car_no }}</td>
        <td>{{ $car->datetime }}</td>
        <td>{{ $car->total_time }} Jam</td>
        <td>RP.{{ number_format($car->bill, 2, ',', '.') }}</td>
    </tr>
    @endforeach
</table>
@endsection