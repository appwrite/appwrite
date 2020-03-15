(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-copy",
    controller: function(element, alerts, document, window) {
      var button = window.document.createElement("i");

      button.type = "button";
      button.className = "icon-docs note copy";
      button.style.cursor = "pointer";

      element.parentNode.insertBefore(button, element.nextSibling);

      var copy = function(event) {
        let disabled = element.disabled;

        element.disabled = false;

        element.focus();
        element.select();

        document.execCommand("Copy");

        if (document.selection) {
          document.selection.empty();
        } else if (window.getSelection) {
          window.getSelection().removeAllRanges();
        }

        element.disabled = disabled;

        element.blur();

        alerts.add({ text: "Copied to clipboard", class: "" }, 3000);
      };

      button.addEventListener("click", copy);
    }
  });
})(window);
