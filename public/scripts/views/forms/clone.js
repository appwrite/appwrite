(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-clone",
    controller: function(element, document, view, expression) {
      element.removeAttribute('data-forms-clone');
      view.render(element);
      var template = element.innerHTML.toString();
      var label = element.dataset["label"] || "Add";
      var icon = element.dataset["icon"] || null;
      var target = expression.parse(element.dataset["target"] || null);
      var first = parseInt(element.dataset["first"] || 1);
      var button = document.createElement("button");
      var debug = element.dataset["debug"] || false;

      button.type = "button";
      button.innerText = " " + label + " ";
      button.classList.add("margin-end");
      button.classList.add("margin-bottom-small");
      button.classList.add("reverse");

      if (icon) {
        var iconElement = document.createElement("i");
        iconElement.className = icon;

        button.insertBefore(iconElement, button.firstChild);
      }

      if (target) {
        target = document.getElementById(target);
      }

      button.addEventListener("click", function() {
        var clone = document.createElement(element.tagName);

        if (element.name) {
          clone.name = element.name;
        }

        clone.innerHTML = template;
        clone.className = element.className;

        var input = clone.querySelector("input, select, textarea");

        view.render(clone);

        if(debug) {
          console.log('Debug: clone: ', clone);
          console.log('Debug: target: ', target);
        }

        if (target) {
          target.appendChild(clone);
        } else {
          button.parentNode.insertBefore(clone, button);
        }

        if(input) {
          input.focus();
        }

        Array.prototype.slice
          .call(clone.querySelectorAll("[data-remove]"))
          .map(function(obj) {
            obj.addEventListener("click", function() {
              clone.parentNode.removeChild(clone);
              obj.scrollIntoView({ behavior: "smooth" });
            });
          });

        Array.prototype.slice
          .call(clone.querySelectorAll("[data-up]"))
          .map(function(obj) {
            obj.addEventListener("click", function() {
              if (clone.previousElementSibling) {
                clone.parentNode.insertBefore(
                  clone,
                  clone.previousElementSibling
                );
                obj.scrollIntoView({ behavior: "smooth" });
              }
            });
          });

        Array.prototype.slice
          .call(clone.querySelectorAll("[data-down]"))
          .map(function(obj) {
            obj.addEventListener("click", function() {
              if (clone.nextElementSibling) {
                clone.parentNode.insertBefore(clone.nextElementSibling, clone);
                obj.scrollIntoView({ behavior: "smooth" });
              }
            });
          });
      });

      element.parentNode.insertBefore(button, element.nextSibling);

      element.parentNode.removeChild(element);


      button.form.addEventListener('reset', function (event) {
        target.innerHTML = '';

        if (first) {
          button.click();
        }
      });

      if (first) {
        button.click();
      }
    }
  });
})(window);
