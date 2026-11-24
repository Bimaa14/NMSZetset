@extends('layout')
@section('body')
<main class="main-container form-stack">
    <a class="text-button" href="/">Kembali ke dashboard</a>
    <h1>Dokumentasi Open API LUMINA NMS</h1>
    <p>Base URL: <code>{{ url('/api/v1') }}</code></p>
    <p>Kirim header <code>Authorization: Bearer YOUR_TOKEN</code> dan <code>Accept: application/json</code>. Buat dan cabut token melalui menu API. Seluruh data dibatasi pada tenant pemilik token.</p>
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>Method</th>
                    <th>Endpoint</th>
                    <th>Izin / fungsi</th>
                </tr>
            </thead>
            <tbody>
                <tr><td>GET</td><td>/olts</td><td>read: daftar OLT lengkap dengan jumlah ONU (online, offline, dying-gasp, LOS, power-off)</td></tr>
                <tr><td>GET</td><td>/monitoring/summary?top=10</td><td>read: ringkasan harian OLT/ONU, rincian status global, top OLT non-online terbanyak, &amp; Monitoring ONU</td></tr>
                <tr><td>GET</td><td>/monitoring/power?category=&amp;status=</td><td>read: status kategori &amp; target Monitoring ONU (Listrik, Site VIP, dll.)</td></tr>
                <tr><td>GET</td><td>/onus/search?q=kata_kunci</td><td>read: cari berdasarkan nama, SN, deskripsi, PPPoE username, atau tag (pagination 100 baris)</td></tr>
                <tr><td>GET</td><td>/onus/{id}</td><td>read: detail lengkap 1 ONU dari snapshot terbaru beserta grup pelanggannya</td></tr>
                <tr><td>GET</td><td>/onus/{id}/live</td><td>read: menjadwalkan pembacaan ke OLT, mengembalikan HTTP 202 + snapshot &amp; operation_id</td></tr>
                <tr><td>GET</td><td>/operations/{id}</td><td>read: status pekerjaan live (queued/running/completed/partial/failed)</td></tr>
                <tr><td>GET</td><td>/monitoring/bad-rx?threshold=-27</td><td>read: daftar ONU dengan redaman optik buruk</td></tr>
                <tr><td>POST</td><td>/onus/{id}/reboot</td><td>write: reboot ONU terpilih; tindakan dicatat dalam Audit</td></tr>
            </tbody>
        </table>
    </div>
    <h2>Contoh request</h2>
    <pre class="terminal-output"># Ringkasan harian OLT / ONU (format laporan WCare Daily / bot ringkasan)
curl -H "Authorization: Bearer YOUR_TOKEN" -H "Accept: application/json" "{{ url('/api/v1/monitoring/summary') }}"

# Cari ONU (nama, SN, deskripsi, PPPoE, atau tag)
curl -H "Authorization: Bearer YOUR_TOKEN" -H "Accept: application/json" "{{ url('/api/v1/onus/search') }}?q=Budi"

# Cek status Monitoring ONU (mis. kategori Listrik / ERABLUE yang gangguan)
curl -H "Authorization: Bearer YOUR_TOKEN" -H "Accept: application/json" "{{ url('/api/v1/monitoring/power') }}?status=down"</pre>
    <p>Periksa <code>source</code> dan cap waktu pengamatan pada respons. Respons live 202 berarti pembacaan sedang dijadwalkan di antrean OLT — poll <code>operation_url</code> sampai selesai, lalu panggil <code>GET /onus/{id}</code> untuk hasil terbaru. Token kedaluwarsa/dicabut menghasilkan 401; izin write yang tidak tersedia menghasilkan 403.</p>
</main>
@endsection
