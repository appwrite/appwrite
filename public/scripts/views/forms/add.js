(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-add",
    repeat: false,
    controller: function(element, view, container, document) {
      var button = document.createElement("button");
      let template = element.children[0].cloneNode(true);
      let as = element.getAttribute('data-ls-as');
      let counter = 0;

      button.type = "button";
      button.innerText = "Add";
      button.classList.add("reverse");

      button.addEventListener('click', function() {
        container.addNamespace(as, 'new-' + counter++);
        console.log(container.namespaces, container.get(as), as);
        container.set(as, null, true, true);

        let child = template.cloneNode(true);
        
        view.render(child);
        
        element.appendChild(child);

        element.style.visibility = 'visible';

        let inputs = child.querySelectorAll('input,textarea');

        for (let index = 0; index < inputs.length; ++index) {
            if(inputs[index].type !== 'hidden') {
              inputs[index].focus();
              break;
            }
        }
      });

      element.after(button);
    }
  });
})(window);
