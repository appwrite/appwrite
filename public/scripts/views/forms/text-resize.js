(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-text-resize",
    controller: function(element, window) {
      function resize() {
        var scrollLeft =
          window.pageXOffset ||
          (
            window.document.documentElement ||
            window.document.body.parentNode ||
            window.document.body
          ).scrollLeft;
        var scrollTop =
          window.pageYOffset ||
          (
            window.document.documentElement ||
            window.document.body.parentNode ||
            window.document.body
          ).scrollTop;

        var offset = element.offsetHeight - element.clientHeight;

        element.style.height = "auto";
        element.style.height = element.scrollHeight + offset + "px";

        window.scrollTo(scrollLeft, scrollTop);
      }

      element.addEventListener("keyup", resize);
      element.addEventListener("change", resize);
      element.addEventListener("cut", resize);
      element.addEventListener("paste", resize);
      element.addEventListener("drop", resize);

      window.addEventListener("resize", resize);

      resize();
    }
  });
})(window);
