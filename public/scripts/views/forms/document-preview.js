(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-document-preview",
    controller: function(element, container, search) {
      
      element.addEventListener('change', function() {
        console.log(element.value);
      });

    }
  });
})(window);
