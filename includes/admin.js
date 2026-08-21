jQuery(function ($) {
  $("#upload_icon").on("click", function (e) {
    e.preventDefault();

    var frame = wp.media({
      title: "Choose Image",
      button: { text: "Choose Image" },
      // Apple only renders a PNG as the pass icon; anything else is discarded.
      library: { type: "image/png" },
      multiple: false,
    });

    frame.on("select", function () {
      var attachment = frame.state().get("selection").first().toJSON();
      $("#icon_file").val(attachment.url);
      $("#icon_file_id").val(attachment.id);

      var $preview = $("#icon_file_preview");
      var $previewImage = $("#icon_file_preview_image");

      if ($preview.length && $previewImage.length) {
        $previewImage.attr("src", attachment.url);
        $preview.show();
      }
    });

    frame.open();
  });

  $(".tc-color-picker").wpColorPicker();
});
