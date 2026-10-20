<?php

namespace App\Support;

class PaginationWindow
{
    /**
     * @return list<int>
     */
    public static function pages(int $currentPage, int $lastPage, int $maximumVisible = 7): array
    {
        if ($lastPage < 1 || $maximumVisible < 1) {
            return [];
        }

        $currentPage = min(max(1, $currentPage), $lastPage);
        $visibleCount = min($maximumVisible, $lastPage);
        $maximumValidStart = max(1, $lastPage - $visibleCount + 1);
        $startPage = min($currentPage, $maximumValidStart);
        $endPage = min($lastPage, $startPage + $visibleCount - 1);

        return range($startPage, $endPage);
    }
}
