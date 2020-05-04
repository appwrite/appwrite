(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-move-down",
    controller: function(element) {
      Array.prototype.slice
        .call(element.querySelectorAll("[data-move-down]"))
        .map(function(obj) {
          obj.addEventListener("click", function() {
            if (element.nextElementSibling) {
              element.parentNode.insertBefore(element.nextElementSibling, element);
              element.scrollIntoView(true);
            }
          });
        });
    }
  });
})(window);
