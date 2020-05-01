(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-run",
    repeat: false,
    controller: function(element, expression, container) {
      let action = expression.parse(element.dataset["formsRun"] || '');
      
      element.addEventListener('click', function () {
        return container.path(action)();
      });
    }
  });
})(window);
