(function(window) {
  window.ls.container.get("view").add({
    selector: "data-ls-ui-open",
    controller: function(element, window) {
      let def = element.classList.contains("open") ? "open" : "close";
      let buttonClass = element.dataset["buttonClass"] || "ls-ui-open";
      let buttonText = element.dataset["buttonText"] || "";
      let buttonIcon = element.dataset["buttonIcon"] || "";
      let buttonSelector = element.dataset["buttonSelector"] || "";
      let hover = element.hasAttribute("data-hover");
      let blur = element.hasAttribute("data-blur");
      let button = window.document.createElement("button");

      let isTouch = function() {
        return (
          "ontouchstart" in window || navigator.maxTouchPoints // works on most browsers
        ); // works on IE10/11 and Surface
      };

      button.innerText = buttonText;
      button.className = buttonClass;
      button.tabIndex = 1;
      button.type = "button";

      if (buttonIcon) {
        let icon = window.document.createElement("i");

        icon.className = buttonIcon;

        button.insertBefore(icon, button.firstChild);
      }

      if (def === "close") {
        element.classList.add("close");
        element.classList.remove("open");
      } else {
        element.classList.add("open");
        element.classList.remove("close");
      }

      button.addEventListener("click", function() {
        element.classList.toggle("open");
        element.classList.toggle("close");
      });

      if (hover && !isTouch()) {
        element.addEventListener("mouseover", function() {
          element.classList.add("open");
          element.classList.remove("close");
        });

        element.addEventListener("mouseout", function() {
          element.classList.add("close");
          element.classList.remove("open");
        });
      }

      let close = function() {
        element.classList.add("close");
        element.classList.remove("open");
      };

      let closeDelay = function() {
        window.setTimeout(function() {
          close();
        }, 400);
      };

      let findParent = function(tagName, el) {
        if (
          (el.nodeName || el.tagName).toLowerCase() === tagName.toLowerCase()
        ) {
          return el;
        }
        while ((el = el.parentNode)) {
          if (
            (el.nodeName || el.tagName).toLowerCase() === tagName.toLowerCase()
          ) {
            return el;
          }
        }
        return null;
      };

      if (blur) {
        button.addEventListener("blur", closeDelay);
      }

      if (buttonSelector) {
        let buttonElements = element.querySelectorAll(buttonSelector);

        buttonElements.forEach(node => {
          node.addEventListener("click", function() {
            element.classList.toggle("open");
            element.classList.toggle("close");
          });

          if (blur) {
            node.addEventListener("blur", closeDelay);
          }
        });
      }

      element.addEventListener('click', function(event) {
        let targetA = findParent('a', event.target);
        let targetB = findParent('button', event.target);

        if (!targetA && !targetB) {
          return false; // no target
        }
        
        if (targetA && !targetA.href) {
          // Just a normal click not an href
          return false;
        }

        if (targetB && !targetB.classList.contains('link')) {
          // Just a normal click not an href
          return false;
        }

        closeDelay();
      });

      element.insertBefore(button, element.firstChild);
    }
  });
})(window);
