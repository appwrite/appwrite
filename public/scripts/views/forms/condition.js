(function (window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-condition",
    controller: function (element) {
      element.form.addEventListener("change", () => {
        const targets = element.dataset["formsConditionValues"].split(",");
        const condition = targets.some(target => "true" === element.form.elements[target].value);

        if (condition) {
          element.setAttribute("disabled", true);
        } else {
          element.removeAttribute("disabled");
        }
      });
    }
  });
})(window);
