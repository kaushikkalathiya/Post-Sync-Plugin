$(document).ready(function () {
  $("#psp-add-target").on("click", function (e) {
    e.preventDefault();
    var table = $("#psp-targets-table");
    var rows = table.find("tr");
    var idx = rows.length - 2; // approximate
    var key = "psp_" + Math.random().toString(36).substr(2, 16);
    var tr = $(
      "<tr>" +
        '<td><input style="width:400px;" type="text" name="<?php echo self::OPTION_KEY; ?>[targets][' +
        idx +
        '][url]"></td>' +
        '<td><input type="text" readonly name="<?php echo self::OPTION_KEY; ?>[targets][' +
        idx +
        '][key]" value="' +
        key +
        '"></td>' +
        '<td><a class="button psp-remove-target" href="#">Remove</a></td>' +
        "</tr>"
    );
    table.find("tr").last().before(tr);
    // Clear the input fields
    tr.find('input[type="text"]').val("");
  });

  $(document).on("click", ".psp-remove-target", function (e) {
    e.preventDefault();
    $(this).closest("tr").remove();
  });
});
