(function (window) {
  "use strict";
  window.ls.container.get("view").add({
    selector: "data-duplications",
    controller: function (element, sdk, console, window) {
      element.addEventListener('change', function (event) {
        let duplication = 0;
        let form = event.target.form;

        for (let i = 0; i < form.elements.length; i++) {
          let field = form.elements[i];

          if(field.name === event.target.name && field.value === event.target.value) {
            duplication++;
          }
        }

        if(duplication > 1) { // self + another element with same name and value
          event.target.setCustomValidity("Duplicated value");
        }
        else {
          event.target.setCustomValidity("");
        }
      });
    }
  });
})(window);
