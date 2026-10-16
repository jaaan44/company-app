{{-- A report's files and history (Phase 36C): $report, $kind ('service' | 'incidents'), $timezone. --}}
@php use App\Support\Portal\Format; @endphp
<section aria-labelledby="files" class="mt-6 rounded-xl border border-line bg-raised p-5">
    <h2 id="files" class="mb-2 font-semibold">Photos and files</h2>
    @if (($report['attachments'] ?? []) === [])
        <p class="text-muted">None.</p>
    @else
        <ul class="divide-y divide-line">
            @foreach ($report['attachments'] as $file)
                <li class="py-2">
                    <a href="{{ route('portal.report-file', [$kind, $report['public_id'], $file['public_id']]) }}" data-no-navigate target="_blank" rel="noopener"
                       class="inline-flex min-h-11 items-center break-all text-accent-ink underline underline-offset-4">{{ $file['original_filename'] }}</a>
                    <span class="text-sm text-muted">· {{ Format::fileSize((int) $file['size_bytes']) }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</section>
<section aria-labelledby="history" class="mt-6 rounded-xl border border-line bg-raised p-5">
    <h2 id="history" class="mb-2 font-semibold">History</h2>
    @if (($report['history'] ?? []) === [])
        <p class="text-muted">No history yet.</p>
    @else
        <ol class="divide-y divide-line">
            @foreach ($report['history'] as $step)
                <li class="py-2">
                    <span class="block">{{ Format::reportAction($step['action']) }}@if ($step['acted_by']['display_name'] ?? null) by {{ $step['acted_by']['display_name'] }}@endif @if ($step['created_at'] ?? null) · {{ Format::dateTime($step['created_at'], $timezone) }} @endif</span>
                    @if (filled($step['note'] ?? null))
                        <span class="block whitespace-pre-line text-sm text-muted">{{ $step['note'] }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</section>
