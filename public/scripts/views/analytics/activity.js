(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-analytics-activity",
    controller: function(window, element, appwrite, account) {
      let action = element.getAttribute("data-analytics-event") || "click";
      let activity = element.getAttribute("data-analytics-label") || "None";
      
      element.addEventListener(action, function() {
        let email = account?.email || element.elements['email'].value || '';
  
        appwrite.analytics.create(email, 'console', activity, window.location.href)
      });
    }
  });
})(window);
