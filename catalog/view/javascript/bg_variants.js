(function (window, $) {
  'use strict';

  if (!$ || !$.ajax) return;
  if (window.BG_VARIANT_STOCK_SCRIPT) return;
  window.BG_VARIANT_STOCK_SCRIPT = true;

  function getProductId() {
    var v =
      $('input[name="product_id"]').first().val() ||
      $('input[name="product_id"]').eq(1).val();
    var n = parseInt(v, 10);
    return isNaN(n) ? 0 : n;
  }

  function collectPovIds() {
    var ids = [];

    $('select[name^="option["]').each(function () {
      var v = $(this).val();
      if (v) ids.push(String(v));
    });

    $('input[type="radio"][name^="option["]:checked, input[type="checkbox"][name^="option["]:checked').each(function () {
      var v = $(this).val();
      if (v) ids.push(String(v));
    });

    // de-dupe
    return ids.filter(function (v, i, a) {
      return a.indexOf(v) === i;
    });
  }

  function selectionComplete() {
    var ok = true;

    // selects must be chosen
    $('select[name^="option["]').each(function () {
      if (!$(this).val()) ok = false;
    });
    if (!ok) return false;

    // every radio group must have a checked option
    var radioNames = {};
    $('input[type="radio"][name^="option["]').each(function () {
      radioNames[$(this).attr('name')] = true;
    });
    for (var name in radioNames) {
      if (!Object.prototype.hasOwnProperty.call(radioNames, name)) continue;
      if ($('input[type="radio"][name="' + name.replace(/"/g, '\\"') + '"]:checked').length === 0) return false;
    }

    return true;
  }

  function $statusEl() {
    // Create a stable element so other theme scripts can't overwrite our output.
    var $orig = $('#bg-poa-status');
    if ($orig.length) {
      var $stable = $('#bg-poa-status-variant');
      if (!$stable.length) {
        $stable = $('<span id="bg-poa-status-variant" style="display:inline; white-space:normal;"></span>');
        // Keep original in DOM but hide it (some themes may still target it).
        try { $orig.hide(); } catch (e) {}
        $orig.after($stable);
      }
      return $stable;
    }

    // Fallback: some themes place stock inside data-ro="product-stock"
    var $fallback = $('[data-ro="product-stock"]').first();
    return $fallback.length ? $fallback : null;
  }

  function setAddToCartEnabled(enabled) {
    try {
      var $btns = $('#button-cart, #button-cart1');
      if (!$btns.length) return;
      $btns.prop('disabled', !enabled);
      if (enabled) $btns.removeClass('disabled');
      else $btns.addClass('disabled');
    } catch (e) {}
  }

  function unlockAllOptions() {
    // Some themes dynamically disable OOS options (causing the "no" cursor).
    // We want ALL options selectable; we only disable Add To Cart when qty == 0.
    try {
      // Select options
      $('#product select option').prop('disabled', false).removeAttr('disabled').removeAttr('aria-disabled');
      // Radio/checkbox options
      $('#product input[name^="option["]').prop('disabled', false).removeAttr('disabled').removeAttr('aria-disabled');
      // Remove "availability" flags some themes use to block clicks
      $('#product [data-available]').attr('data-available', '1').removeAttr('data-available');
      $('#product [data-oos]').removeAttr('data-oos');

      // Remove common "disabled" classes on wrappers (visual + cursor)
      $('#product .radio, #product .checkbox, #product label, #product .option-content-box')
        .removeClass('disabled option-unavailable');

      // Force pointer cursor where themes use not-allowed
      if (!document.getElementById('bg-variants-unlock-style')) {
        var style = document.createElement('style');
        style.id = 'bg-variants-unlock-style';
        style.textContent =
          '#product .radio, #product .checkbox, #product label, #product .option-content-box { cursor: pointer !important; pointer-events: auto !important; }' +
          '#product input[name^="option["], #product select[name^="option["] { pointer-events: auto !important; }' +
          '#product .disabled, #product [aria-disabled="true"] { pointer-events: auto !important; cursor: pointer !important; }' +
          // Defeat theme rules like [data-available="0"] { pointer-events:none; cursor:not-allowed }
          '#product [data-available="0"], #product [data-oos="1"] { pointer-events: auto !important; cursor: pointer !important; }';
        document.head.appendChild(style);
      }
    } catch (e) {}
  }

  function enableControl(el) {
    if (!el) return;
    try {
      // Enable the actual form control
      if (el.tagName === 'OPTION') {
        el.disabled = false;
        el.removeAttribute('disabled');
        el.removeAttribute('aria-disabled');
        el.removeAttribute('data-available');
        el.removeAttribute('data-oos');
        return;
      }
      if (el.disabled) el.disabled = false;
      el.removeAttribute('disabled');
      el.removeAttribute('aria-disabled');
      // Remove theme flags that can be used to block click handlers/CSS
      if (el.getAttribute && el.getAttribute('data-available') === '0') {
        el.setAttribute('data-available', '1');
      }
      el.removeAttribute('data-available');
      el.removeAttribute('data-oos');
    } catch (e) {}
  }

  function enableClosestOptionControl(target) {
    try {
      if (!target) return;
      // If user clicked on a wrapper/span/img, find the underlying input/select
      var input =
        (target.closest && target.closest('#product input[name^="option["]')) ||
        null;
      if (input) {
        enableControl(input);
        return;
      }

      var select = (target.closest && target.closest('#product select[name^="option["]')) || null;
      if (select) {
        enableControl(select);
        // also ensure its selected option is enabled
        try {
          var opt = select.options && select.options[select.selectedIndex];
          if (opt) enableControl(opt);
        } catch (e) {}
        return;
      }

      // Some themes wrap input in label/div; try find within
      var wrap = (target.closest && target.closest('#product .radio, #product .checkbox, #product label')) || null;
      if (wrap && wrap.querySelector) {
        var inp2 = wrap.querySelector('input[name^="option["]');
        if (inp2) enableControl(inp2);
      }
    } catch (e) {}
  }

  var xhr = null;
  var changeTimer = null;
  var loadingTimer = null;
  var requestSeq = 0;
  var lastText = null;
  var lastNonEmptyStatusText = '';
  var autoPickDone = false;

  function parseIdsFromKey(key) {
    key = String(key || '').trim();
    if (!key) return [];
    return key
      .split(/[,\|\s;]+/)
      .map(function (x) { return String(x).trim(); })
      .filter(function (x) { return x !== ''; })
      .map(function (x) { return parseInt(x, 10); })
      .filter(function (n) { return !isNaN(n) && n > 0; });
  }

  function applyPovSelection(povIds) {
    if (!Array.isArray(povIds) || !povIds.length) return;
    // Selects
    $('select[name^="option["]').each(function () {
      var $sel = $(this);
      var matched = null;
      $sel.find('option').each(function () {
        var v = parseInt($(this).val(), 10);
        if (v && povIds.indexOf(v) !== -1) matched = String(v);
      });
      if (matched) $sel.val(matched);
      else {
        // fallback: choose first non-empty option
        var firstNonEmpty = $sel.find('option').filter(function () { return $(this).val(); }).first().val();
        if (firstNonEmpty) $sel.val(firstNonEmpty);
      }
    });

    // Radios
    var radioNames = {};
    $('input[type="radio"][name^="option["]').each(function () {
      radioNames[$(this).attr('name')] = true;
    });
    Object.keys(radioNames).forEach(function (name) {
      var picked = null;
      $('input[type="radio"][name="' + name.replace(/"/g, '\\"') + '"]').each(function () {
        var v = parseInt($(this).val(), 10);
        if (v && povIds.indexOf(v) !== -1) picked = this;
      });
      if (picked) $(picked).prop('checked', true);
      else {
        // fallback: choose first radio in group
        var $first = $('input[type="radio"][name="' + name.replace(/"/g, '\\"') + '"]').first();
        if ($first.length) $first.prop('checked', true);
      }
    });

    // Trigger downstream updates
    $('select[name^="option["], input[type="radio"][name^="option["]').trigger('change');
  }

  function autoPickFirstAvailableVariant() {
    if (autoPickDone) return;
    autoPickDone = true;

    // Only auto-pick when nothing is selected yet.
    var pov = collectPovIds();
    if (pov && pov.length) return;

    var productId = getProductId();
    if (!productId) return;

    var url = window.bgVariantStockUrl || 'index.php?route=product/product_variant';

    $.ajax({
      url: url,
      type: 'post',
      dataType: 'json',
      data: { product_id: productId, auto_select: 1 },
      success: function (json) {
        if (!json || !json.found) return;
        var ids = Array.isArray(json.pov) ? json.pov : parseIdsFromKey(json.option_key);
        if (!ids || !ids.length) return;
        applyPovSelection(ids);
        scheduleUpdate();
      }
    });
  }

  function getBgStatusMap() {
    try {
      if (typeof window !== 'undefined' && window.bg_status_map_js) return window.bg_status_map_js;
    } catch (e) {}
    return null;
  }

  function getBgWhMap() {
    try {
      if (typeof window !== 'undefined' && window.bg_wh_map_js) return window.bg_wh_map_js;
    } catch (e) {}
    return null;
  }

  function buildPovToOvMap() {
    var map = {};
    try {
      // selects
      $('#product select option').each(function () {
        var pov = $(this).val();
        var ov = $(this).data('ov');
        if (pov && ov) map[String(pov)] = String(ov);
      });
      // radios/checkboxes
      $('#product input[name^="option["]').each(function () {
        var pov2 = $(this).val();
        var ov2 = $(this).data('ov');
        if (pov2 && ov2) map[String(pov2)] = String(ov2);
      });
    } catch (e) {}
    return map;
  }

  function firstMapValue(m, key) {
    if (!m) return '';
    try {
      if (Object.prototype.hasOwnProperty.call(m, key)) {
        var v = m[key];
        if (Array.isArray(v)) return v.length ? String(v[0]) : '';
        return v !== null && typeof v !== 'undefined' ? String(v) : '';
      }
    } catch (e) {}
    return '';
  }

  function extractStatusPrefixFromLabel(text) {
    text = String(text || '').trim();
    if (!text) return '';
    // Examples:
    // - "ships in 24 hours 9 in stock"
    // - "Stock Expected In 3 days 0 in stock"
    // If it starts with a number, there's no status prefix.
    if (/^\d+\s+in\s+stock/i.test(text)) return '';
    var m = text.match(/^(.*?)(\s+\d+\s+in\s+stock)$/i);
    if (m && m[1]) return String(m[1]).trim();
    return '';
  }

  function stockTokenToText(token) {
    if (token === null || typeof token === 'undefined') return '';
    token = String(token).trim();
    if (!token) return '';
    var upper = token.toUpperCase();

    if (upper.indexOf('LC_STOCK_MSG_EXPECT') === 0) {
      // EXPECT == sold out/backorder messaging; request wants Sold Out
      var m = upper.match(/^LC_STOCK_MSG_EXPECT_(\d+)$/);
      if (m) {
        var d = parseInt(m[1], 10);
        if (!isNaN(d) && d > 0) return 'Stock Expected In ' + d + ' ' + (d === 1 ? 'day' : 'days');
      }
      return 'Sold Out';
    }

    if (upper.indexOf('LC_STOCK_MSG_SOLD') === 0 || upper.indexOf('SOLD_OUT') !== -1 || upper.indexOf('OUT_OF_STOCK') !== -1) {
      return 'Sold Out';
    }

    var md = upper.match(/^LC_STOCK_MSG_(\d+)_DAYS$/);
    if (md) {
      var days = parseInt(md[1], 10);
      if (!isNaN(days) && days > 0) return 'ships in ' + days * 24 + ' hours';
    }
    var mh = upper.match(/^LC_STOCK_MSG_(\d+)_HOURS$/);
    if (mh) {
      var hours = parseInt(mh[1], 10);
      if (!isNaN(hours) && hours > 0) return 'ships in ' + hours + ' hours';
    }

    // Also handle the doc example ("In stock, usually dispatched in 1 business day")
    // Normalize some common English phrases into the "ships in X hours" style.
    var t = token;
    var m2 = t.match(/dispatched in\s+(\d+)\s+business\s+day/i);
    if (m2) {
      var bd = parseInt(m2[1], 10);
      if (!isNaN(bd) && bd > 0) return 'ships in ' + bd * 24 + ' hours';
    }
    var m3 = t.match(/dispatched in\s+(\d+)\s+hours?/i);
    if (m3) {
      var hh = parseInt(m3[1], 10);
      if (!isNaN(hh) && hh > 0) return 'ships in ' + hh + ' hours';
    }
    // Remove a leading "In stock," prefix so we don't suppress useful detail.
    t = t.replace(/^in\s*stock\s*,?\s*/i, '').trim();
    return t || token;
  }

  function formatStatusQty(statusText, qty) {
    qty = parseInt(qty, 10);
    if (isNaN(qty)) qty = 0;
    statusText = (statusText || '').trim();

    if (qty <= 0) {
      var oos = statusText || 'Out of Stock';
      return oos + ' 0 in stock';
    }

    // qty > 0
    if (!statusText) return qty + ' in stock';
    // Avoid true duplication only when the status is just "In Stock"
    if (/^in\s*stock$/i.test(statusText)) return qty + ' in stock';
    if (/sold\s*out|out\s*of\s*stock/i.test(statusText)) return qty + ' in stock';
    return statusText + ' ' + qty + ' in stock';
  }

  function setTextSmooth($el, next) {
    next = String(next || '');
    if (lastText === next) return;
    lastText = next;
    $el.text(next);
  }

  function updateVariantStockNow() {
    var $el = $statusEl();
    if (!$el) return;

    // Always keep options clickable (some themes re-disable after ajax/price updates)
    unlockAllOptions();

    var productId = getProductId();
    if (!productId) return;

    var pov = collectPovIds();
    if (!pov.length || !selectionComplete()) {
      // Avoid flicker: don't spam helper text if we already have something.
      if (!$el.text().trim()) setTextSmooth($el, 'Select options to see variant stock');
      // Don't disable cart until a full selection is made.
      setAddToCartEnabled(true);
      return;
    }

    try {
      if (xhr && xhr.readyState !== 4) xhr.abort();
    } catch (e) {}

    requestSeq += 1;
    var seq = requestSeq;

    // Only show "Checking..." if request takes long enough (prevents jumpiness)
    try { if (loadingTimer) clearTimeout(loadingTimer); } catch (e) {}
    loadingTimer = setTimeout(function () {
      if (seq !== requestSeq) return;
      setTextSmooth($el, 'Checking stock...');
    }, 250);

    // Default to the compatibility endpoint shipped in this repo.
    // You can override by setting window.bgVariantStockUrl.
    var url =
      window.bgVariantStockUrl ||
      'index.php?route=product/product_variant';

    xhr = $.ajax({
      url: url,
      type: 'post',
      dataType: 'json',
      data: { product_id: productId, pov: pov },
      success: function (json) {
        if (seq !== requestSeq) return;
        try { if (loadingTimer) clearTimeout(loadingTimer); } catch (e) {}

        if (json && json.found) {
          var qty = json.quantity === null || typeof json.quantity === 'undefined' ? null : parseInt(json.quantity, 10);
          if (qty !== null && !isNaN(qty)) {
            // Prefer backend-provided status text/token.
            var statusText =
              (json.stock_status_text && String(json.stock_status_text).trim()) ||
              stockTokenToText(json.stock_status_token);

            // If DB has no stock_status_token yet, fall back to the product-page maps (bg_status_map_js / bg_wh_map_js)
            // and keep the last-known status rather than dropping to "X in stock".
            if (!statusText) {
              // try to derive from maps using option_value_id (via data-ov)
              var map = buildPovToOvMap();
              var statusMap = getBgStatusMap();
              var whMap = getBgWhMap();
              for (var i = 0; i < pov.length; i++) {
                var ov = map[String(pov[i])] || '';
                if (!ov) continue;
                var tok = firstMapValue(statusMap, ov);
                if (tok) { statusText = stockTokenToText(tok); break; }
                var wh = firstMapValue(whMap, ov);
                if (wh) { statusText = String(wh).trim(); break; }
              }
            }

            // Preserve last-known non-empty prefix so UI doesn't "drop" the status mid-click
            if (!statusText) {
              var existingPrefix = extractStatusPrefixFromLabel($el.text());
              statusText = existingPrefix || lastNonEmptyStatusText || '';
            }
            if (statusText) lastNonEmptyStatusText = statusText;

            setTextSmooth($el, formatStatusQty(statusText, qty));
            setAddToCartEnabled(qty > 0);
            return;
          }
          setTextSmooth($el, 'Stock info available');
          setAddToCartEnabled(true);
          return;
        }
        setTextSmooth($el, 'No variant stock information available');
        setAddToCartEnabled(true);
      },
      error: function () {
        if (seq !== requestSeq) return;
        try { if (loadingTimer) clearTimeout(loadingTimer); } catch (e) {}
        setTextSmooth($el, 'No variant stock information available');
        setAddToCartEnabled(true);
      },
    });
  }

  function scheduleUpdate() {
    try { if (changeTimer) clearTimeout(changeTimer); } catch (e) {}
    changeTimer = setTimeout(function () {
      updateVariantStockNow();
    }, 150);
  }

  // Bind
  $(document).on(
    'change',
    'select[name^="option["], input[type="radio"][name^="option["], input[type="checkbox"][name^="option["]',
    function () {
      scheduleUpdate();
    }
  );

  $(function () {
    autoPickFirstAvailableVariant();
    scheduleUpdate();

    // Capture-phase "unlock on interact" to defeat any scripts that re-disable OOS options.
    // This makes options selectable even if something toggles disabled just before click.
    try {
      document.addEventListener(
        'pointerdown',
        function (e) {
          enableClosestOptionControl(e.target);
        },
        true
      );
      document.addEventListener(
        'mousedown',
        function (e) {
          enableClosestOptionControl(e.target);
        },
        true
      );
      document.addEventListener(
        'click',
        function (e) {
          enableClosestOptionControl(e.target);
        },
        true
      );
    } catch (e) {}

    // Also observe DOM changes and re-unlock, in case theme scripts re-disable options.
    try {
      var root = document.getElementById('product') || document.body;
      var mo = new MutationObserver(function () {
        unlockAllOptions();
      });
      mo.observe(root, { subtree: true, childList: true, attributes: true, attributeFilter: ['disabled', 'class', 'aria-disabled'] });
    } catch (e) {}
  });
})(window, window.jQuery);

