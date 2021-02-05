(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-code",
    controller: function(element, alerts) {
      let lang = element.dataset["formsCode"] || "json";
      let div = document.createElement("div");
      let pre = document.createElement("pre");
      let code = document.createElement("code");
      let copy = document.createElement("i");

      div.appendChild(pre);
      div.appendChild(copy);
      pre.appendChild(code);

      element.parentNode.appendChild(div);
      element.style.display = 'none';

      div.className = "ide";
      div.dataset['langLabel'] = element.dataset["langLabel"] || 'JSON';
      div.dataset['lang'] = element.dataset["lang"] || 'json';
      pre.className = "line-numbers";
      code.className = "prism language-" + lang;
      copy.className = "icon-docs copy";
      copy.textContent = "Click Here to Copy";
      copy.title = "Copy to Clipboard";

      copy.addEventListener("click", function() {
        window.getSelection().removeAllRanges();

        let range = document.createRange();

        range.selectNode(code);

        window.getSelection().addRange(range);

        try {
          document.execCommand("copy");
          alerts.add({ text: "Copied to clipboard", class: "" }, 3000);
        } catch (err) {
          alerts.add({ text: "Failed to copy text ", class: "error" }, 3000);
        }

        window.getSelection().removeAllRanges();
      });

      let check = function() {
        if (!element.value) {
          return;
        }

        let value = null;

        try {
          value = JSON.stringify(JSON.parse(element.value), null, 4);
        } catch (error) {
          value = element.value;
        }

        code.innerHTML = value;

        Prism.highlightElement(code);

        div.scrollTop = 0;
      };

      element.addEventListener("change", check);

      check();
    }
  });
})(window);
