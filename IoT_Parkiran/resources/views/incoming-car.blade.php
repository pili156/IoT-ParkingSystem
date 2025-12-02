@extends('layout')

@section('content')
<h2>Incoming Car</h2>

<table>
    <tr>
        <th>ID</th>
        <th>Car No</th>
        <th>Date & Time</th>
    </tr>

    @foreach ($incoming as $car)
    <tr>
        <td>{{ $car->id }}</td>
        <td>{{ $car->car_no }}</td>
        <td>{{ $car->datetime }}</td>
    </tr>
    @endforeach
</table>
@endsection