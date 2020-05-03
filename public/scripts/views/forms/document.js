(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-document",
    controller: function(element, container, search) {
      var formsDocument = (element.dataset["formsDocument"] || '');
      var searchButton = (element.dataset["search"] || 0);

      let path = container.scope(searchButton);

      element.addEventListener('click', function() {
        search.selected = element.value;
        search.path = path;

        document.dispatchEvent(
          new CustomEvent(formsDocument, {
            bubbles: false,
            cancelable: true
          }));
      });

    }
  });
})(window);
