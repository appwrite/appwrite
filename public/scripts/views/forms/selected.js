(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-selected",
    controller: function(element, expression) {
      const isSelected = expression.parse(element.getAttribute('data-forms-selected')) === element.getAttribute('value');

      if (isSelected) {
        element.setAttribute("selected", true);
      } else {
        element.removeAttribute("selected");
      }
    }
  });
})(window);
