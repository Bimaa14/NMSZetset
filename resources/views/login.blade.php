@extends('layout')
@section('title', 'Masuk')
@section('body')
<main class="login-shell">
    <div class="login-card">
        <div class="brand-mark">@include('partials.lumina-logo')</div>
        <p class="eyebrow">OPTICAL NETWORK SYSTEM</p>
        <h1>LUMINA NMS</h1>
        <p class="muted">Masuk untuk mengelola jaringan Anda.</p>
        @if($errors->any()) <div class="notice danger" role="alert">{{ $errors->first() }}</div> @endif
        <form action="/login" method="post" class="form-stack">
            @csrf
            <label>Email<input type="email" name="email" value="{{ old('email') }}" autocomplete="username" required placeholder="nama@isp.id"></label>
            <label>Kata sandi<input type="password" name="password" autocomplete="current-password" required></label>
            <button class="btn primary" type="submit">Masuk ke dashboard <i data-lucide="arrow-right"></i></button>
        </form>
        @if(app()->environment('local') && config('nms.demo'))
        <div class="demo-login"><span class="eyebrow">COBA AKUN DEMO</span><p class="muted small">Pilih role untuk memeriksa hak akses dashboard.</p>
            <form action="/demo/login" method="post" class="demo-role-grid">@csrf
                @foreach(['isp_owner' => 'ISP Owner', 'noc' => 'NOC', 'cs' => 'CS', 'teknisi' => 'Teknisi'] as $role => $label)
                <button class="btn secondary" name="role" value="{{ $role }}">{{ $label }}</button>
                @endforeach
            </form>
        </div>
        @endif
        <p class="login-foot"><i data-lucide="shield-check"></i> Akses aman, terisolasi untuk setiap ISP.</p>
    </div>
</main>
@endsection
