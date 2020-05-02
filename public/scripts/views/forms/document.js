(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-document",
    controller: function(element, container, search) {
      var searchButton = (element.dataset["search"] || 0);

      if(searchButton) {
        let searchOpen = document.createElement("button");

        searchOpen.type = 'button';
        searchOpen.innerHTML = '<i class="icon icon-search"></i> Search';
        searchOpen.classList.add('reverse');
        searchOpen.classList.add('small');

        let path = container.scope(searchButton);

        searchOpen.addEventListener('click', function() {
          search.selected = element.value;
          search.path = path;

          document.dispatchEvent(
            new CustomEvent("open-document-serach", {
              bubbles: false,
              cancelable: true
            }));
        });

        element.parentNode.insertBefore(searchOpen, element.nextSibling)

      }
    }
  });
})(window);
