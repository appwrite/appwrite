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

      fetch('https://growth.appwrite.io/v1/analytics', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          action: 'pageview',
          url: window.location.href,
          data: {
            "screenWidth": window.screen.width,
            "screenHeight": window.screen.height,
            "viewportSize": window.innerWidth + 'x' + window.innerHeight,
            "referrer": document.referrer,
          },
        })
      });
    }
  });
})(window);
