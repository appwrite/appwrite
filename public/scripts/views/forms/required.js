(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-required",
    controller: function(element, expression) {
      const isRequired = expression.parse(element.getAttribute('data-forms-required')) === "true";
      if (isRequired) {
        element.setAttribute("required", true);
      } else {
        element.removeAttribute("disabled");
      }
    }
  });
})(window);
