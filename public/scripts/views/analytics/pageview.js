(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-analytics-pageview",
    controller: function(window, router) {
      if (!ga) {
        console.error("Google Analytics ga object is not available");
      }

      var company = router.params["company"] || null;

      if (!company) {
        //return;
      }

      ga("set", "page", window.location.pathname);

      ga("set", "dimension1", company);
      //ga('set', 'dimension2', '');

      ga("send", "pageview");
    }
  });
})(window);
