<?php

use App\Support\PaginationWindow;

it('builds the requested contiguous page window', function (int $currentPage, int $lastPage, array $expected) {
    expect(PaginationWindow::pages($currentPage, $lastPage))->toBe($expected);
})->with([
    'fewer than seven pages' => [1, 3, [1, 2, 3]],
    'exactly seven pages' => [1, 7, [1, 2, 3, 4, 5, 6, 7]],
    'first of more than seven pages' => [1, 10, [1, 2, 3, 4, 5, 6, 7]],
    'second of more than seven pages' => [2, 10, [2, 3, 4, 5, 6, 7, 8]],
    'middle page moves forward' => [5, 12, [5, 6, 7, 8, 9, 10, 11]],
    'near end clamps backward' => [10, 12, [6, 7, 8, 9, 10, 11, 12]],
    'last page clamps backward' => [12, 12, [6, 7, 8, 9, 10, 11, 12]],
]);

it('normalizes page bounds without generating invalid pages', function () {
    expect(PaginationWindow::pages(-5, 10))
        ->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and(PaginationWindow::pages(99, 10))
        ->toBe([4, 5, 6, 7, 8, 9, 10])
        ->and(PaginationWindow::pages(1, 0))
        ->toBeEmpty();
});

it('never displays more than seven numeric pages', function () {
    $pages = PaginationWindow::pages(25, 100);

    expect($pages)
        ->toHaveCount(7)
        ->and(min($pages))->toBeGreaterThanOrEqual(1)
        ->and(max($pages))->toBeLessThanOrEqual(100);
});
