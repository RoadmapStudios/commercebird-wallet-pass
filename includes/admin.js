jQuery(function ($) {
  $("#upload_icon").on("click", function (e) {
    e.preventDefault();

    var frame = wp.media({
      title: "Choose Image",
      button: { text: "Choose Image" },
      library: { type: "image" },
      multiple: false,
    });

    frame.on("select", function () {
      var attachment = frame.state().get("selection").first().toJSON();
      $("#icon_file").val(attachment.url);
      $("#icon_file_id").val(attachment.id);
    });

    frame.open();
  });

  $(".tc-color-picker").wpColorPicker();
});
