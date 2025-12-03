@extends('layout')

@section('content')
<div class="content-box">
    <!-- Header Halaman -->
    <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
        <h3 class="m-0 text-dark">
            <i class="fas fa-parking text-primary me-2"></i> Real-time Parking Status
        </h3>
        <span class="text-muted">Last Update: {{ now()->format('H:i:s') }}</span>
    </div>

    <!-- Grid Row -->
    <div class="row justify-content-center">
        @forelse($slots as $slot)
        
            <!-- --- MULAI BAGIAN KOTAK PARKIR (DULU WIDGET) --- -->
            <div class="col-md-3 col-sm-6 mb-4">
                <div class="card shadow-sm h-100" style="border: 2px solid #333;">
                    
                    <!-- LOGIKA VISUAL: Putih kalau Full, Hijau kalau Empty -->
                    <div class="card-body p-0 d-flex align-items-center justify-content-center" 
                         style="height: 200px; 
                                background-color: {{ $slot->status == 'Full' ? '#ffffff' : '#00a65a' }}; 
                                position: relative;">
                        
                        @if($slot->status == 'Full')
                            <!-- GAMBAR MOBIL -->
                            <!-- Menggunakan gambar mobil dari CDN -->
                            <img src="https://cdn-icons-png.flaticon.com/512/3202/3202926.png" 
                                 alt="Car" 
                                 style="width: 60%; transform: rotate(90deg);">
                        @else
                            <!-- TEKS EMPTY -->
                            <h2 class="text-white fw-bold" style="letter-spacing: 2px;">EMPTY</h2>
                        @endif
            
                    </div>
            
                    <!-- LABEL BAWAH -->
                    <div class="card-footer bg-dark text-white text-center py-2">
                        <h5 class="m-0 fw-bold">{{ $slot->slot_name }}</h5>
                        <span class="badge {{ $slot->status == 'Full' ? 'bg-danger' : 'bg-success' }} mt-1">
                            {{ $slot->status }}
                        </span>
                    </div>
                </div>
            </div>
            <!-- --- SELESAI BAGIAN KOTAK PARKIR --- -->

        @empty
            <!-- Tampilan kalau database kosong -->
            <div class="col-12 text-center py-5">
                <div class="alert alert-warning">
                    <h4><i class="fas fa-exclamation-triangle"></i> Data Slot Kosong</h4>
                    <p>Silakan isi tabel <code>parking_slots</code> di Database terlebih dahulu.</p>
                </div>
            </div>
        @endforelse
    </div>
</div>
@endsection