// Script to show dates using local timezone and locale.
$(document).ready(function () {
  $("*[data-type='date']").each(function (i) {
    const dateFormat = $(this).data("datefmt");
    const date = new Date($(this).data("date"));
    const hasTitle = typeof $(this).attr('title') !== undefined && $(this).attr('title') !== false;
    if (dateFormat === "time") {
      const text = date.toLocaleTimeString('ja-JP', {
        hour: "2-digit",
        minute: "2-digit",
      });
      $(this).text(text);
      if(hasTitle) $(this).attr('title', text);
    } else if (dateFormat === "date") {
      const text = date.toLocaleDateString('ja-JP', {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
      }).replace(/\//gi,'-'));
      $(this).text(text);
      if(hasTitle) $(this).attr('title', text);
    } else if (dateFormat === "datetime") {
      const text = date.toLocaleString('ja-JP', {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
      }).replace(/\//gi,'-'));
      $(this).text(text);
      if(hasTitle) $(this).attr('title', text);
    }
  });
});
