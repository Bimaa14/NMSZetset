<div class="skeleton-surface" x-show="{{ $show }}" role="status" aria-label="Memuat data" @if(!($boot ?? false)) x-cloak @endif>
    <span class="sr-only">Sedang mengambil data…</span>
    <div aria-hidden="true">
        <span class="skeleton-block skeleton-heading"></span>
        <div class="skeleton-grid">@for($i=0;$i<4;$i++)<span class="skeleton-block skeleton-metric"></span>@endfor</div>
        @if(($variant ?? '') === 'chart')<span class="skeleton-block skeleton-chart"></span>
        @else<div><span class="skeleton-block skeleton-line"></span><span class="skeleton-block skeleton-line"></span><span class="skeleton-block skeleton-line"></span></div>@endif
    </div>
</div>
