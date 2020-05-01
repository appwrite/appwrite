(function(window) {
  window.ls.container.get("view").add({
    selector: "data-ls-ui-trigger",
    controller: function(element, document, expression) {
      let trigger = expression.parse(element.dataset["lsUiTrigger"] || '').trim().split(',');
      let event = expression.parse(element.dataset["event"] || 'click');
      let debug = element.getAttribute('data-debug') || false;

      for (let index = 0; index < trigger.length; index++) {
        let name = trigger[index];
  
        element.addEventListener(event, function() {
          if(debug) {
            console.log('Debug: event triggered: ' + name);
          }
          document.dispatchEvent(new CustomEvent(name));
        });
      }
    }
  });
})(window);
