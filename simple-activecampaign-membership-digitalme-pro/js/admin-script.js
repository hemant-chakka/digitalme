(function ($) {
  $(document).ready(function () {
    $("#activememb_cache").on("click", function () {
      let $button = $(this);
      $button.val("Clearing...");
      $.ajax({
        url: sacdAdminAjax.ajax_url,
        type: "POST",
        data: {
          action: "clear_activememb_cache_action",
          nonce: sacdAdminAjax.nonce,
        },
        success: function (response) {
          if (response.success) {
            $button.val("Cache Cleared");
            setTimeout(function () {
              $button.val("Clear Cache");
            }, 2000);
          } else {
            $button.val("Error");
            setTimeout(function () {
              $button.val("Clear Cache");
            }, 2000);
          }
        },
        error: function (error) {
          console.log("AJAX error:", error);
          $button.val("Error");
          setTimeout(function () {
            $button.val("Clear Cache");
          }, 2000);
        },
      });
    });
  });
})(jQuery);
