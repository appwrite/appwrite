(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-color",
    controller: function(element) {
      var preview = document.createElement("div");
      var picker = document.createElement("input");

      picker.type = "color";
      preview.className = "color-preview";

      preview.appendChild(picker);

      picker.addEventListener("change", syncA);
      picker.addEventListener("input", syncA);
      element.addEventListener("input", update);
      element.addEventListener("change", update);

      function update() {
        if (element.validity.valid) {
          preview.style.background = element.value;
          syncB();
        }
      }

      function syncA() {
        element.value = picker.value;
        update();
      }

      function syncB() {
        picker.value = element.value;
      }

      element.parentNode.insertBefore(preview, element);

      update();
      syncB();
    }
  });
})(window);
