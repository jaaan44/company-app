@props(['icon', 'tone' => 'blue', 'size' => 'md', 'round' => false])
{{-- An icon in a tinted rounded square (Phase 37D): what kind of thing a row is (a project, a report, an announcement). Decorative: the row's text says what it is. --}}
@php
    $colours = match ($tone) {
        'green' => 'bg-tint-green text-icon-green',
        'amber' => 'bg-tint-amber text-icon-amber',
        'rose' => 'bg-tint-rose text-icon-rose',
        'violet' => 'bg-tint-violet text-icon-violet',
        'slate' => 'bg-tint-slate text-icon-slate',
        default => 'bg-tint-blue text-icon-blue',
    };
    [$box, $glyph] = match (true) {
        $round => ['size-11 rounded-full', 'size-6'], // in place of an avatar (a group chat)
        $size === 'lg' => ['size-12 rounded-2xl', 'size-6'],
        $size === 'sm' => ['size-8 rounded-lg', 'size-4'], // inside a compact card
        default => ['size-10 rounded-xl', 'size-5'],
    };
@endphp
<span {{ $attributes->merge(['class' => "inline-flex $box shrink-0 items-center justify-center $colours"]) }} aria-hidden="true"><x-portal.icon :name="$icon" :class="$glyph" /></span>
