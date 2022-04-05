(function (window) {
  "use strict";
  window.ls.container.get("view").add({
    selector: "data-duplications",
    controller: function (element) {
      let validate = function (element) {
        let duplication = 0;
        let form = element.form;

        for (let i = 0; i < form.elements.length; i++) {
          let field = form.elements[i];

          if(field.name === element.name && field.value === element.value) {
            duplication++;
          }
        }

        if(duplication > 1) { // self + another element with same name and value
          element.setCustomValidity("Duplicated value");
        }
        else {
          element.setCustomValidity("");
        }
      };

      element.addEventListener('change', function(event) {
        validate(event.target)
      });

      element.addEventListener('focus', function(event) {
        validate(event.target)
      });

      element.addEventListener('blur', function(event) {
        validate(event.target)
      });
    }
  });
})(window);
