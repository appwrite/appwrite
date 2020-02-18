(function(window) {
  window.ls.container.get("view").add({
    selector: "data-ls-ui-loader",
    controller: function(element, document) {
      document.addEventListener('account.get', function() {
        element.classList.add('loaded');
      });
    }
  });
})(window);
