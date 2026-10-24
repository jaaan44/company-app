@props(['name', 'status' => null, 'size' => 'md'])
{{--
    A person's avatar (Phase 37D): their initials on a tint chosen from their
    name (there are no photos), and, when known, a dot in their operational
    status's colour. Decorative: the name, and the status in words, are
    always beside it.
--}}
@php
    use App\Support\Portal\Format;

    $tones = ['bg-tint-blue text-icon-blue', 'bg-tint-green text-icon-green', 'bg-tint-amber text-icon-amber', 'bg-tint-rose text-icon-rose', 'bg-tint-violet text-icon-violet'];
    $colours = $tones[crc32($name) % count($tones)];
    [$box, $text, $dot] = match ($size) {
        'lg' => ['size-16', 'text-xl', 'size-4'],
        'sm' => ['size-9', 'text-sm', 'size-2.5'],
        default => ['size-11', 'text-base', 'size-3'],
    };
    $dotColour = match (Format::operationalTone($status)) {
        'green' => 'bg-icon-green', 'amber' => 'bg-icon-amber', 'rose' => 'bg-icon-rose', 'violet' => 'bg-icon-violet',
        default => 'bg-icon-slate',
    };
@endphp
<span {{ $attributes->merge(['class' => "relative inline-flex $box shrink-0 items-center justify-center rounded-full font-semibold $text $colours"]) }} aria-hidden="true">
    {{ Format::initials($name) }}
    @if ($status !== null)
        <span class="absolute right-0 bottom-0 {{ $dot }} rounded-full {{ $dotColour }} ring-2 ring-raised"></span>
    @endif
</span>
