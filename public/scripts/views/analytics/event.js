(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-analytics",
    controller: function(element) {
      let action = element.getAttribute("data-analytics-event") || "click";
      let doNotTrack = window.navigator.doNotTrack;

      if(doNotTrack == '1') {
        return;
      }

      element.addEventListener(action, function() {
        let category =
          element.getAttribute("data-analytics-category") || "undefined";
        let label = element.getAttribute("data-analytics-label") || "undefined";

        if (!ga) {
          console.error("Google Analytics ga object is not available");
        }

        ga("send", {
          hitType: "event",
          eventCategory: category,
          eventAction: action,
          eventLabel: label
        });
      });
    }
  });
})(window);
