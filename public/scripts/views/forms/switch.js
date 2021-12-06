(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-switch",
    controller: function(element) {
      let input = window.document.createElement("input");
      input.type = "checkbox";
      input.className = "button switch " + element.className;

      let syncA = function() {
        let value = input.checked ? "true" : "false"
        let old = element.value;

        element.value = value;

        if(value !== old) {
          element.dispatchEvent(new Event('change'));
        }
      };

      let syncB = function() {
        input.checked = (element.value === "true");

        if (element.disabled) {
          input.setAttribute("disabled", true);
        } else {
          input.removeAttribute("disabled");
        }
      };


      input.addEventListener("input", syncA);
      input.addEventListener("change", syncA);

      element.addEventListener("input", syncB);
      element.addEventListener("change", syncB);

      syncA();

      element.parentNode.insertBefore(input, element);
    }
  });
})(window);
