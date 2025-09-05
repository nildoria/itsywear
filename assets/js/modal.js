jQuery(function ($) {
  var $modal = $("#eppdp-modal"),
    $save = $("#eppdp-submit-btn"),
    $empSel = $("#eppdp-employee-select"),
    $po = $("#eppdp-po-input"),
    $btns = $(".add_to_cart_button");

  function toggleBtns(on) {
    $btns.prop("disabled", !on).toggleClass("disabled", !on);
  }

  // on load…
  if (eppdpData.hasEmployee) {
    $modal.hide();
    toggleBtns(true);
  } else {
    $modal.css("display", "flex");
    $("html, body").addClass("eppdp-lock");
    toggleBtns(false);
  }

  // Save & Continue
  $save.on("click", function (e) {
    e.preventDefault();
    var emp = ($empSel.val() || "").trim();
    if (!emp) {
      alert("Please choose an employee.");
      return;
    }
    $.post(
      eppdpData.ajaxUrl,
      {
        action: "save_employee_po",
        nonce: eppdpData.nonce,
        employee: emp,
        po: ($po.val() || "").trim(),
      },
      function (res) {
        if (res.success) {
          $modal.fadeOut(200, function () {
            $("html, body").removeClass("eppdp-lock"); // ← unlock scroll
          });
          toggleBtns(true);
          // Optional: refresh to show banner immediately
          $modal.fadeOut(200, function () {
            $("html, body").removeClass("eppdp-lock");
          });
          toggleBtns(true);
          location.reload();
        } else {
          alert(res.data || "Error saving selection.");
        }
      }
    );
  });

  // destroy any previous init (just in case)
  if ($empSel.hasClass("select2-hidden-accessible")) {
    $empSel.select2("destroy");
  }

  // re-init with dropdownParent + z-index boost
  // $empSel.select2({
  //   placeholder: "Search employee…",
  //   allowClear: true,
  //   width: "100%",
  //   // dropdownParent: $(
  //   //   "#eppdp-modal .employee-popup-dialog"
  //   // ),
  //   dropdownPosition: "below",
  // });

  function empMatcher(params, data) {
    if ($.trim(params.term) === "") return data;
    if (typeof data.text === "undefined") return null;

    var term = params.term.toString().toLowerCase();
    var text = (data.text || "").toString().toLowerCase();

    var raw = $(data.element).attr("data-code");
    var code = raw ? String(raw).toLowerCase().trim() : "";

    if (text.indexOf(term) > -1 || (code && code.indexOf(term) > -1)) {
      return data;
    }
    return null;
  }

  function withCodeAndCountMarkup(data) {
    if (!data.element) return data.text;

    var $el = $(data.element);
    var code = ($el.attr("data-code") || "").toString().trim();
    var count = parseInt($el.attr("data-count") || "0", 10);

    var $wrap = $('<span class="eppdp-optwrap"></span>');
    var $label = $('<span class="eppdp-optlabel"></span>').text(data.text);

    $wrap.append($label);

    if (code) {
      $wrap.append($('<em class="eppdp-code"></em>').text(" (" + code + ")"));
    }
    if (count > 0) {
      $wrap.append($('<sup class="eppdp-badge"></sup>').text(count));
    }
    return $wrap;
  }

  // (optional) expose for footer JS to reuse
  window.eppdpHelpers = {
    empMatcher: empMatcher,
    withCodeAndCountMarkup: withCodeAndCountMarkup,
  };

  $empSel.select2({
    placeholder:
      eppdpData && eppdpData.i18nEmployeePlaceholder
        ? eppdpData.i18nEmployeePlaceholder
        : "Search employee…",
    allowClear: true,
    width: "100%",
    matcher: empMatcher,
    templateResult: withCodeAndCountMarkup,
    templateSelection: withCodeAndCountMarkup,
    dropdownPosition: "below",
  });


});
