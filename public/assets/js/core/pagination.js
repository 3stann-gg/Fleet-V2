(function initializePaginationWindow(global) {
  "use strict";

  function getVisiblePages(currentPage, lastPage, maximumVisible = 7) {
    const normalizedLastPage = Math.max(0, Math.trunc(Number(lastPage) || 0));
    const normalizedMaximum = Math.max(
      0,
      Math.trunc(Number(maximumVisible) || 0),
    );

    if (normalizedLastPage < 1 || normalizedMaximum < 1) {
      return [];
    }

    const normalizedCurrentPage = Math.min(
      Math.max(1, Math.trunc(Number(currentPage) || 1)),
      normalizedLastPage,
    );
    const visibleCount = Math.min(normalizedMaximum, normalizedLastPage);
    const maximumValidStart = Math.max(
      1,
      normalizedLastPage - visibleCount + 1,
    );
    const startPage = Math.min(normalizedCurrentPage, maximumValidStart);
    const endPage = Math.min(
      normalizedLastPage,
      startPage + visibleCount - 1,
    );

    return Array.from(
      { length: endPage - startPage + 1 },
      (_, index) => startPage + index,
    );
  }

  const paginationWindow = Object.freeze({ getVisiblePages });

  global.FleetPagination = paginationWindow;

  if (typeof module !== "undefined" && module.exports) {
    module.exports = paginationWindow;
  }
})(typeof window !== "undefined" ? window : globalThis);
