(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-analytics-pageview",
    controller: function(window, router, env) {
      let doNotTrack = window.navigator.doNotTrack;

      if(doNotTrack == '1') {
        return;
      }

      let project = router.params["project"] || 'None';

      fetch('http://localhost:2080/v1/analytics', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          action: 'pageview',
          url: window.location.href
        })
      });
    }
  });
})(window);
