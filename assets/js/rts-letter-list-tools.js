(function () {
  'use strict';

  function init() {
    var form = document.getElementById('posts-filter');
    if (!form) {
      return;
    }

    // WP default has the search box outside of `.tablenav`.
    // Our custom list controls can end up nesting it inside `.tablenav.top`,
    // which causes stacking/overlap when combined with filters + pagination.
    var listControls = form.querySelector('.rts-list-controls') || form;
    var topTablenav = form.querySelector('.tablenav.top');
    if (topTablenav) {
      var nestedSearch = topTablenav.querySelector('.search-box');
      if (nestedSearch && listControls && nestedSearch.parentNode === topTablenav) {
        listControls.insertBefore(nestedSearch, topTablenav);
        nestedSearch.classList.add('rts-search-row');
      }
    }

    var searchInput = form.querySelector('#post-search-input');
    if (!searchInput) {
      return;
    }

    if (!searchInput.getAttribute('placeholder')) {
      searchInput.setAttribute('placeholder', 'Search letters...');
    }

    searchInput.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        form.submit();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
