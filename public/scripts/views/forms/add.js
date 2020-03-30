(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-add",
    repeat: false,
    controller: function(element, view, container) {
      var button = document.createElement("button");
      let template = element.children[0].cloneNode(true);
      let as = element.getAttribute('data-ls-as');

      button.type = "button";
      button.innerText = "Add";
      button.classList.add("reverse");

      button.addEventListener('click', function() {
        container.set(as, null, true, true);

        let child = template.cloneNode(true);
        
        element.appendChild(child);

        view.render(child);

        element.style.visibility = 'visible';
      });

      element.after(button);
    }
  });
})(window);
