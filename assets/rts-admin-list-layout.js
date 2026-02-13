(function () {
  'use strict';

  function isTargetScreen() {
    var body = document.body;
    if (!body) {
      return false;
    }

    return (
      body.classList.contains('rts-admin-scope') &&
      body.classList.contains('edit-php') &&
      (body.classList.contains('post-type-letter') ||
        body.classList.contains('post-type-rts_subscriber'))
    );
  }

  function ensureTableWrapper(postsFilter, table) {
    var wrapper = postsFilter.querySelector('.rts-list-table-wrap');
    if (!wrapper) {
      wrapper = document.createElement('div');
      wrapper.className = 'rts-list-table-wrap';
      table.parentNode.insertBefore(wrapper, table);
      wrapper.appendChild(table);
    } else if (table.parentNode !== wrapper) {
      wrapper.appendChild(table);
    }
  }

  function ensureControlsWrapper(postsFilter, topNav) {
    var controls = postsFilter.querySelector('.rts-list-controls');
    if (!controls) {
      controls = document.createElement('div');
      controls.className = 'rts-list-controls';
      postsFilter.insertBefore(controls, topNav);
    }

    if (topNav.parentNode !== controls) {
      controls.appendChild(topNav);
    }
  }

  function placeSearchInTopNav(postsFilter, topNav) {
    var searchBox = postsFilter.querySelector('.search-box');
    if (!searchBox) {
      return;
    }

    searchBox.classList.add('rts-nav-search');

    if (searchBox.parentNode !== topNav) {
      // Put it in the same visual row as filters/pagination.
      topNav.insertBefore(searchBox, topNav.firstChild);
    }
  }

  function stabilizeTopNav(topNav) {
    var clearBreak = topNav.querySelector('br.clear');
    if (clearBreak) {
      clearBreak.remove();
    }

    var child = topNav.firstElementChild;
    while (child) {
      if (child.classList.contains('bulkactions')) {
        child.classList.add('rts-nav-bulk');
      } else if (child.classList.contains('actions')) {
        child.classList.add('rts-nav-filters');
      } else if (child.classList.contains('tablenav-pages')) {
        child.classList.add('rts-nav-pages');
      }
      child = child.nextElementSibling;
    }
  }

  function ensureTableGap(postsFilter) {
    // If something (plugin/CSS) causes the controls block to visually overlap the table,
    // dynamically increase the table wrapper margin so the layout is always readable.
    var controls = postsFilter.querySelector('.rts-list-controls') || postsFilter.querySelector('.tablenav.top');
    var wrap = postsFilter.querySelector('.rts-list-table-wrap');
    if (!controls || !wrap) {
      return;
    }

    var baseGap = 18;
    var cRect = controls.getBoundingClientRect();
    var wRect = wrap.getBoundingClientRect();
    var actualGap = wRect.top - cRect.bottom;

    // Only ever increase spacing; never reduce user/plugin spacing.
    if (actualGap < baseGap) {
      var cs = window.getComputedStyle(wrap);
      var currentMargin = parseFloat(cs.marginTop || '0') || 0;
      var bump = baseGap - actualGap;
      var newMargin = Math.ceil(currentMargin + bump);
      wrap.style.setProperty('margin-top', newMargin + 'px', 'important');
    }
  }

  function applyLayout() {
    if (!isTargetScreen()) {
      return;
    }

    var postsFilter = document.getElementById('posts-filter');
    if (!postsFilter) {
      return;
    }

    var topNav = postsFilter.querySelector('.tablenav.top');
    var table = postsFilter.querySelector('.wp-list-table');
    if (!topNav || !table) {
      return;
    }

    ensureControlsWrapper(postsFilter, topNav);
    placeSearchInTopNav(postsFilter, topNav);
    stabilizeTopNav(topNav);
    ensureTableWrapper(postsFilter, table);

    postsFilter.classList.add('rts-list-layout-ready');

    // Give layout a tick to settle, then enforce a safe gap.
    requestAnimationFrame(function () {
      ensureTableGap(postsFilter);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyLayout);
  } else {
    applyLayout();
  }

  window.addEventListener('load', applyLayout);
  setTimeout(applyLayout, 120);

  window.addEventListener('resize', function () {
    var postsFilter = document.getElementById('posts-filter');
    if (postsFilter && postsFilter.classList.contains('rts-list-layout-ready')) {
      ensureTableGap(postsFilter);
    }
  });
})();
