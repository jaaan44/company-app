{{-- A short message in place of a page's content (Phase 37B, DEC-068): the record is gone, or there's nothing to show. One shape with x-portal.empty. --}}
<p {{ $attributes->merge(['class' => 'card p-5']) }}>{{ $slot }}</p>
