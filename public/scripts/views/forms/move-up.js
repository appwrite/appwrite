(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-move-up",
    controller: function(element) {
      Array.prototype.slice
        .call(element.querySelectorAll("[data-move-up]"))
        .map(function(obj) {
          obj.addEventListener("click", function() {
            if (element.previousElementSibling) {
              element.parentNode.insertBefore(element, element.previousElementSibling);
              element.scrollIntoView(true);
            }
          });
        });
    }
  });
})(window);
