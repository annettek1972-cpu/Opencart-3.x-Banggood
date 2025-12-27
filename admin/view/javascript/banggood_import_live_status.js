/**
 * Banggood Import - Live status updates
 *
 * Goals:
 * - Stop the periodic full list refresh/re-render.
 * - Keep the list static and update only status badges in-place.
 * - Keep paging working (Prev/Next/limit) via explicit page loads.
 *
 * This file is loaded from the controller (no Twig markup changes).
 */
(function ($) {
  'use strict';

  function getStatusesUrl() {
    if (window.getFetchedProductsStatusesUrl) return window.getFetchedProductsStatusesUrl;
    if (window.getFetchedProductsListPagedUrl && typeof window.getFetchedProductsListPagedUrl === 'string') {
      return window.getFetchedProductsListPagedUrl.replace('getFetchedProductsListPaged', 'getFetchedProductsStatuses');
    }
    return null;
  }

  function syncPagerUi(page, pageTotal) {
    page = parseInt(page, 10);
    pageTotal = parseInt(pageTotal, 10);
    if (!page || page < 1) page = 1;
    if (!pageTotal || pageTotal < 1) pageTotal = 1;

    $('#bg-queue-page').text(String(page));
    $('#bg-queue-page-total').text(String(pageTotal));
    $('#bg-queue-prev').prop('disabled', page <= 1);
    $('#bg-queue-next').prop('disabled', page >= pageTotal);
  }

  function collectVisibleProductIds() {
    var ids = [];
    $('#banggood-products .bg-compact-row[data-bg-product-id]').each(function () {
      var id = $(this).attr('data-bg-product-id');
      if (id) ids.push(id);
    });
    // de-dupe
    var seen = Object.create(null);
    var out = [];
    for (var i = 0; i < ids.length; i++) {
      var v = String(ids[i]);
      if (!seen[v]) {
        seen[v] = true;
        out.push(v);
      }
    }
    return out;
  }

  function reorderRowsPendingFirst() {
    var $list = $('#banggood-products .bg-fetched-list');
    if (!$list.length) return;

    var $rows = $list.find('.bg-compact-row[data-bg-product-id]');
    if ($rows.length < 2) return;

    var items = $rows.get().map(function (el) {
      var $el = $(el);
      var status = String(($el.find('.bg-status-badge').attr('data-status') || '')).toLowerCase();
      var pending = status === 'pending';
      var fetchedTs = parseInt($el.attr('data-fetched-at-ts') || '0', 10) || 0;
      var rowId = parseInt($el.attr('data-row-id') || '0', 10) || 0;
      return { el: el, pending: pending, fetchedTs: fetchedTs, rowId: rowId };
    });

    items.sort(function (a, b) {
      // pending first
      if (a.pending !== b.pending) return a.pending ? -1 : 1;
      // then newest fetched_at
      if (a.fetchedTs !== b.fetchedTs) return b.fetchedTs - a.fetchedTs;
      // then newest id
      return b.rowId - a.rowId;
    });

    // Append in sorted order (moves nodes, no full refresh)
    for (var i = 0; i < items.length; i++) {
      $list.append(items[i].el);
    }
  }

  function updateBadgesInPlace(statuses) {
    if (!statuses) return;

    Object.keys(statuses).forEach(function (bgid) {
      var info = statuses[bgid];
      if (!info) return;

      var $row = $('#banggood-products .bg-compact-row[data-bg-product-id="' + bgid.replace(/"/g, '\\"') + '"]');
      if (!$row.length) return;

      var $badge = $row.find('.bg-status-badge').first();
      if (!$badge.length) return;

      var label = (info.label != null ? String(info.label) : '').trim();
      var status = (info.status != null ? String(info.status) : '').trim().toLowerCase();

      $badge.text(label);
      $badge.attr('data-status', status);
      if (info.badge_bg) $badge.css('background', String(info.badge_bg));
    });
  }

  function pollStatusesOnce() {
    var url = getStatusesUrl();
    if (!url) return;

    var ids = collectVisibleProductIds();
    if (!ids.length) return;

    $.ajax({
      url: url,
      type: 'post',
      dataType: 'json',
      data: { product_ids: ids },
      success: function (json) {
        if (!json || json.error || !json.statuses) return;
        updateBadgesInPlace(json.statuses);
        reorderRowsPendingFirst();
      }
    });
  }

  function loadQueuePage(page, limit) {
    if (!window.getFetchedProductsListPagedUrl) return;

    page = parseInt(page, 10) || 1;
    limit = parseInt(limit, 10) || 50;
    if (page < 1) page = 1;
    if (limit < 1) limit = 1;
    if (limit > 200) limit = 200;

    $.ajax({
      url: window.getFetchedProductsListPagedUrl,
      type: 'post',
      dataType: 'json',
      data: { page: page, limit: limit },
      success: function (json) {
        if (!json || json.error) return;

        if (typeof json.total_count !== 'undefined') {
          $('#bg-persisted-count').text('(' + (json.total_count || 0) + ')');
        }
        if (typeof json.page !== 'undefined' && typeof json.page_total !== 'undefined') {
          syncPagerUi(json.page, json.page_total);
          window.bgQueuePage = parseInt(json.page, 10) || window.bgQueuePage || 1;
        }

        if (typeof json.html === 'string') {
          $('#banggood-products').html(json.html);
        }

        // Trigger an immediate status poll after rendering
        setTimeout(pollStatusesOnce, 50);
      }
    });
  }

  $(function () {
    // Run AFTER the inline Twig script has bound handlers/started polling.
    setTimeout(function () {
      // Disable built-in polling by keeping mode out of 'persisted'
      try {
        window.bgProductsMode = 'other';
      } catch (e) {}

      // Replace pager handlers so they still work but do not re-enable auto-refresh.
      $('#bg-queue-prev').off('click').on('click', function (e) {
        e.preventDefault();
        var page = (window.bgQueuePage || 1) - 1;
        if (page < 1) page = 1;
        loadQueuePage(page, window.bgQueueLimit || 50);
      });

      $('#bg-queue-next').off('click').on('click', function (e) {
        e.preventDefault();
        var page = (window.bgQueuePage || 1) + 1;
        loadQueuePage(page, window.bgQueueLimit || 50);
      });

      $('#bg-queue-limit').off('change').on('change', function () {
        var v = parseInt($(this).val(), 10);
        if (!v || v < 1) v = 50;
        window.bgQueueLimit = v;
        window.bgQueuePage = 1;
        loadQueuePage(1, v);
      });

      // Start status polling (in-place updates only)
      setInterval(pollStatusesOnce, 2000);
      setTimeout(pollStatusesOnce, 250);
    }, 0);
  });
})(jQuery);

