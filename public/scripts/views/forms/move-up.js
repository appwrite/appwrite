(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-move-up",
    controller: function(element) {
      Array.prototype.slice
        .call(element.querySelectorAll("[data-move-up]"))
        .map(function(obj) {
          obj.addEventListener("click", function() {
            if (element.previousElementSibling) {
              console.log('up', element);
              element.parentNode.insertBefore(element, element.previousElementSibling);
              element.scrollIntoView({block: 'center'});
            }
          });
        });
    }
  });
})(window);
