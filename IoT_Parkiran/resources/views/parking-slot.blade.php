@extends('layout')

@section('content')
<h2>Parking Slot</h2>

<table>
    <tr>
        <th>ID</th>
        <th>Parking Slot</th>
        <th>Status</th>
    </tr>

    @foreach ($slots as $slot)
    <tr>
        <td>{{ $slot->id }}</td>
        <td>{{ $slot->slot_name }}</td>
        <td>{{ $slot->status }}</td>
    </tr>
    @endforeach
</table>
@endsection