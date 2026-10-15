@extends('layouts.app')
@section('title', 'New Claim')
@section('subtitle', 'Upload your receipt and let OCR do the magic.')
@section('actions')
    @include('claims._steps', ['step' => 1])
@endsection

@section('content')
<div class="grid gap-5 lg:grid-cols-5">
    <form method="POST" action="{{ route('claims.store') }}" enctype="multipart/form-data" id="upload-form" class="card min-w-0 self-start overflow-hidden lg:col-span-3" data-loading="Reading your receipt…">
        @csrf
        {{-- 两个 input：一个在手机上开相机，一个选现有图片 / PDF，最后都合并到 receipts[] 提交 --}}
        <input type="file" id="camera-input" accept="image/*" capture="environment" class="hidden">
        <input type="file" id="files-input" multiple accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" class="hidden">
        <input type="file" name="receipts[]" id="receipts" multiple class="hidden">

        {{-- 拍照 / 上传区，可拖放文件 --}}
        <div class="p-5 sm:p-6">
            <div id="drop-zone" class="flex flex-col items-center rounded-2xl border-2 border-dashed border-accent-200 bg-accent-50/40 px-4 py-10 text-center transition sm:py-14">
                <span class="grid h-16 w-16 place-items-center rounded-2xl bg-white text-accent-600 shadow-sm ring-1 ring-accent-100"><x-icon name="camera" class="h-8 w-8" /></span>
                <p class="mt-4 text-base font-semibold text-slate-900">Take Photo or Upload Receipt</p>
                <p class="mt-1 text-sm text-slate-500">Supports JPG, PNG, PDF (max {{ config('claims.upload.max_kb') / 1024 }}MB) · or drag files here</p>
                <div class="mt-5 flex flex-wrap justify-center gap-3">
                    <button type="button" onclick="document.getElementById('camera-input').click()" class="btn-primary"><x-icon name="camera" class="h-5 w-5" /> Take Photo</button>
                    <button type="button" onclick="document.getElementById('files-input').click()" class="btn-secondary"><x-icon name="upload" class="h-5 w-5" /> Choose File</button>
                </div>
            </div>

            <div id="previews" class="mt-5 hidden grid-cols-3 gap-3 sm:grid-cols-5"></div>
            <p class="mt-4 text-xs text-slate-500">Tip: lay the receipt flat in good light so the whole receipt fills the photo.</p>
        </div>

        <div class="flex flex-col-reverse gap-3 border-t border-slate-100 bg-slate-50/60 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <p class="text-xs text-slate-500">Nothing is sent for approval yet — you'll check everything on the next step.</p>
            <button id="read-btn" class="btn-primary btn-lg" disabled>Continue <x-icon name="arrow-right" class="h-5 w-5" /></button>
        </div>
    </form>

    <div class="min-w-0 space-y-5 lg:col-span-2">
        {{-- 预告下一步 OCR 会自动填的栏位 --}}
        <div class="card p-5">
            <div class="flex items-center justify-between">
                <h2 class="card-title"><x-icon name="sparkles" class="h-5 w-5 text-accent-500" /> OCR Processing</h2>
                <span class="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700">Auto-filled</span>
            </div>
            <p class="mt-2 text-sm text-slate-500">After you continue, these are read from the receipt and filled in for you to check:</p>
            <dl class="mt-4 space-y-2.5 text-sm">
                @foreach (['Merchant', 'Address', 'Date & time', 'Items, qty & price', 'Tax / service charge', 'Total', 'Category'] as $f)
                    <div class="flex items-center justify-between gap-3 border-b border-dashed border-slate-100 pb-2.5 last:border-0">
                        <dt class="text-slate-500">{{ $f }}</dt>
                        <dd class="h-2.5 w-24 rounded-full bg-gradient-to-r from-slate-100 to-slate-200"></dd>
                    </div>
                @endforeach
            </dl>
        </div>

        {{-- 没有收据：直接建空白 claim 手动填 --}}
        <div class="card flex items-center justify-between gap-3 p-5">
            <div>
                <div class="font-semibold text-slate-900">No receipt?</div>
                <div class="text-sm text-slate-500">Create the claim and type in the details yourself.</div>
            </div>
            <form method="POST" action="{{ route('claims.store') }}">
                @csrf
                <button class="btn-outline whitespace-nowrap"><x-icon name="pencil" class="h-4 w-4" /> Enter manually</button>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    (() => {
        const target = document.getElementById('receipts');
        const previews = document.getElementById('previews');
        const readBtn = document.getElementById('read-btn');
        // 已选文件统一放在 picked，每次变动后同步到隐藏的 receipts[] input
        const picked = new DataTransfer();

        // 重画缩略图，顺便更新按钮文字
        function render() {
            target.files = picked.files;
            previews.innerHTML = '';
            previews.classList.toggle('hidden', picked.files.length === 0);
            previews.classList.toggle('grid', picked.files.length > 0);
            readBtn.disabled = picked.files.length === 0;
            readBtn.firstChild.textContent = picked.files.length > 1 ? `Read ${picked.files.length} receipts ` : 'Continue ';

            [...picked.files].forEach((file, i) => {
                const tile = document.createElement('div');
                tile.className = 'relative aspect-[3/4] overflow-hidden rounded-xl border border-slate-200 bg-slate-100';
                if (file.type.startsWith('image/')) {
                    const img = document.createElement('img');
                    img.src = URL.createObjectURL(file);
                    img.className = 'h-full w-full object-cover';
                    tile.appendChild(img);
                } else {
                    tile.innerHTML = '<div class="grid h-full place-items-center p-2 text-center text-xs font-semibold text-slate-600">PDF<br>' + file.name.replace(/</g, '') + '</div>';
                }
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'absolute top-1.5 right-1.5 grid h-7 w-7 place-items-center rounded-full bg-slate-900/70 text-white';
                remove.innerHTML = '&times;';
                // DataTransfer 不能删单个文件，只能重建一份
                remove.onclick = () => {
                    const keep = new DataTransfer();
                    [...picked.files].forEach((f, j) => j !== i && keep.items.add(f));
                    picked.items.clear();
                    [...keep.files].forEach((f) => picked.items.add(f));
                    render();
                };
                tile.appendChild(remove);
                previews.appendChild(tile);
            });

            // 最后一格 "+"：再加一张收据
            if (picked.files.length) {
                const more = document.createElement('button');
                more.type = 'button';
                more.className = 'grid aspect-[3/4] place-items-center rounded-xl border-2 border-dashed border-slate-200 text-3xl text-slate-400 hover:border-brand-400 hover:text-brand-600';
                more.textContent = '+';
                more.onclick = () => document.getElementById('files-input').click();
                previews.appendChild(more);
            }
        }

        function add(files) {
            [...files].forEach((f) => picked.items.add(f));
            render();
        }

        document.getElementById('camera-input').addEventListener('change', (e) => add(e.target.files));
        document.getElementById('files-input').addEventListener('change', (e) => add(e.target.files));

        // 拖放上传
        const zone = document.getElementById('drop-zone');
        ['dragenter', 'dragover'].forEach((ev) => zone.addEventListener(ev, (e) => { e.preventDefault(); zone.classList.add('border-brand-500'); }));
        ['dragleave', 'drop'].forEach((ev) => zone.addEventListener(ev, (e) => { e.preventDefault(); zone.classList.remove('border-brand-500'); }));
        zone.addEventListener('drop', (e) => add(e.dataTransfer.files));
    })();
</script>
@endpush
