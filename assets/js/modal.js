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
          $modal.fadeOut(200, function(){
            $("html, body").removeClass("eppdp-lock"); // ← unlock scroll
          });
          toggleBtns(true);
          // Optional: refresh to show banner immediately
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
  $empSel.select2({
    placeholder: "Search employee…",
    allowClear: true,
    width: "100%",
    // dropdownParent: $(
    //   "#eppdp-modal .employee-popup-dialog"
    // ),
    dropdownPosition: "below",
  });

});
