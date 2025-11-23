<!DOCTYPE html>
<html>
<head>
    <title>Login – Smart Parking</title>
    @vite(['resources/css/app.css'])
</head>

<body class="bg-light d-flex justify-content-center align-items-center" style="height: 100vh;">

    <div class="card p-4 shadow" style="width: 350px;">
        <h3 class="text-center mb-3">Admin Login</h3>

        @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        <form action="{{ route('doLogin') }}" method="POST">
            @csrf

            <div class="mb-3">
                <label>Email</label>
                <input type="email" name="email" class="form-control" required>
            </div>

            <div class="mb-3">
                <label>Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>

            <button class="btn btn-primary w-100">Login</button>

        </form>
    </div>

</body>
</html>
