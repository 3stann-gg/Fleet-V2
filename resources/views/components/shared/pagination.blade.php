@props([
    'paginator',
    'buttonClass' => null,
])

@php
    $pages = \App\Support\PaginationWindow::pages(
        $paginator->currentPage(),
        $paginator->lastPage(),
    );
@endphp

<nav {{ $attributes->class(['pagination']) }}>
    @if($paginator->onFirstPage())
        <button type="button" @class([$buttonClass]) aria-label="Previous page" disabled>
            <i class="ph ph-caret-left" aria-hidden="true"></i>
        </button>
    @else
        <a href="{{ $paginator->previousPageUrl() }}" @class([$buttonClass]) aria-label="Previous page">
            <i class="ph ph-caret-left" aria-hidden="true"></i>
        </a>
    @endif

    @foreach($pages as $page)
        @if($paginator->currentPage() === $page)
            <button
                type="button"
                @class([$buttonClass, 'active'])
                aria-label="Page {{ $page }}"
                aria-current="page"
            >
                {{ $page }}
            </button>
        @else
            <a href="{{ $paginator->url($page) }}" @class([$buttonClass]) aria-label="Page {{ $page }}">
                {{ $page }}
            </a>
        @endif
    @endforeach

    @if($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}" @class([$buttonClass]) aria-label="Next page">
            <i class="ph ph-caret-right" aria-hidden="true"></i>
        </a>
    @else
        <button type="button" @class([$buttonClass]) aria-label="Next page" disabled>
            <i class="ph ph-caret-right" aria-hidden="true"></i>
        </button>
    @endif
</nav>
