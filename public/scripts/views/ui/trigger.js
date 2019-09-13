(function(window) {
  window.ls.container.get("view").add({
    selector: "data-ls-ui-trigger",
    controller: function(element, document) {
      let trigger = element.dataset["lsUiTrigger"];
      let event = element.dataset["event"] || "click";

      element.addEventListener(event, function() {
        document.dispatchEvent(new CustomEvent(trigger));
      });
    }
  });
})(window);
