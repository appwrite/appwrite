(function(window) {
  window.ls.container.get("view").add({
    selector: "data-ls-ui-trigger",
    controller: function(element, document, expression) {
      let trigger = expression.parse(element.dataset["lsUiTrigger"] || '').trim().split(',');
      let event = expression.parse(element.dataset["event"] || 'click');

      for (let index = 0; index < trigger.length; index++) {
        let name = trigger[index];
  
        element.addEventListener(event, function() {
          document.dispatchEvent(new CustomEvent(name));
        });
      }
    }
  });
})(window);
