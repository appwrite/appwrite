// Views

window.ls.container
  .get("view")
  .add({
    selector: "data-acl",
    controller: function(element, document, router, alerts) {
      document.body.classList.remove("console");
      document.body.classList.remove("home");

      document.body.classList.add(router.getCurrent().view.scope);

      if (!router.getCurrent().view.project) {
        document.body.classList.add("hide-nav");
        document.body.classList.remove("show-nav");
      } else {
        document.body.classList.add("show-nav");
        document.body.classList.remove("hide-nav");
      }

      // Special case for console index page

      if (router.getCurrent().path === "/console") {
        document.body.classList.add("index");
      } else {
        document.body.classList.remove("index");
      }
    }
  })
  .add({
    selector: "data-prism",
    controller: function(window, document, element, alerts) {
      Prism.highlightElement(element);

      let copy = document.createElement("i");

      copy.className = "icon-docs copy";
      copy.title = "Copy to Clipboard";
      copy.textContent = "Click Here to Copy";

      copy.addEventListener("click", function() {
        window.getSelection().removeAllRanges();

        let range = document.createRange();

        range.selectNode(element);

        window.getSelection().addRange(range);

        try {
          document.execCommand("copy");
          alerts.add({ text: "Copied to clipboard", class: "" }, 3000);
        } catch (err) {
          alerts.add({ text: "Failed to copy text ", class: "error" }, 3000);
        }

        window.getSelection().removeAllRanges();
      });

      element.parentNode.parentNode.appendChild(copy);
    }
  })
;
