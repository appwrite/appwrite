(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-analytics-pageview",
    controller: function(window, router) {
      if (!ga) {
        console.error("Google Analytics ga object is not available");
      }

      var project = router.params["project"] || 'None';

      ga("set", "page", window.location.pathname);

      ga("set", "dimension1", project);
      //ga('set', 'dimension2', '');

      ga("send", "pageview");
    }
  });
})(window);
