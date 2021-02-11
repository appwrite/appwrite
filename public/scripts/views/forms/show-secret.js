(function (window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-show-secret",
    controller: function (element, document) {
      let button = document.createElement('span');
      button.className = "link pull-end text-size-small margin-top-negative icon-eye";
      button.innerHTML = (element.type == 'password') ? 'Show Secret' : 'Hide Secret';
      button.style.visibility = (element.value == '') ? 'hidden' : 'visible';

      element.insertAdjacentElement("beforebegin", button);

      button.addEventListener("click", function (event) {
        switch (element.type) {
          case "password":
            element.type = "text";
            button.innerHTML = 'Hide Secret';
            break;
          case "text":
            element.type = "password";
            button.innerHTML = 'Show Secret';
            break;
          default:
            console.warn(
              "data-forms-show-secret: element.type NOT text NOR password"
            );
        }
      });

      let sync = function(event) {
        button.style.visibility = (element.value == '') ? 'hidden' : 'visible';
      };

      element.addEventListener("keyup", sync);
      element.addEventListener("change", sync);
    },
  });
})(window);
