<div class="col-md-3 col-sm-6 mb-4">
    <!-- Card Kotak -->
    <div class="card shadow-sm h-100" style="border: 2px solid #333;">
        
        <!-- BAGIAN VISUAL (Warna & Gambar) -->
        <div class="card-body p-0 d-flex align-items-center justify-content-center" 
             style="height: 200px; background-color: {{ $slot->status == 'Full' ? '#ffffff' : '#00a65a' }}; position: relative;">
            
            @if($slot->status == 'Full')
                <!-- Tampilkan Mobil (Gunakan CDN gambar mobil kuning dari atas) -->
                <!-- Kita rotate 90 derajat agar seperti tampak atas parkiran -->
                <img src="https://cdn-icons-png.flaticon.com/512/3202/3202926.png" 
                     alt="Car" 
                     style="width: 60%; transform: rotate(90deg);">
            @else
                <!-- Tampilkan Teks EMPTY -->
                <h2 class="text-white fw-bold" style="letter-spacing: 2px;">EMPTY</h2>
            @endif

        </div>

        <!-- BAGIAN LABEL BAWAH -->
        <div class="card-footer bg-dark text-white text-center py-2">
            <h5 class="m-0 fw-bold">{{ $slot->slot_name }}</h5>
            <!-- Badge Status Kecil -->
            <span class="badge {{ $slot->status == 'Full' ? 'bg-danger' : 'bg-success' }} mt-1">
                {{ $slot->status }}
            </span>
        </div>
    </div>
</div>