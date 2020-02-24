(function(window) {
  window.ls.container.get("view").add({
    selector: "data-ls-ui-trigger",
    controller: function(element, document, expression) {
      let trigger = expression.parse(element.dataset["lsUiTrigger"] || '');
      let event = expression.parse(element.dataset["event"] || 'click');

      element.addEventListener(event, function() {
        document.dispatchEvent(new CustomEvent(trigger));
      });
    }
  });
})(window);
