(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-parent-up",
    controller: function(element) {
      var target = element.dataset["target"] || null;

      target = target ? element.closest(target) : element.parentNode;

      element.addEventListener("click", function() {
        if (target.previousElementSibling) {
          target.parentNode.insertBefore(target, target.previousElementSibling);
          element.scrollIntoView({ behavior: "smooth" });
        }
      });
    }
  });
})(window);
