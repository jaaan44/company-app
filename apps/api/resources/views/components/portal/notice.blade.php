{{-- A short message in place of a page's content (Phase 37B, DEC-068): the record is gone, or there's nothing to show. One shape with x-portal.empty. --}}
<p {{ $attributes->merge(['class' => 'rounded-xl border border-line bg-raised p-5']) }}>{{ $slot }}</p>
