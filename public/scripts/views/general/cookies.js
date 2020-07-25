(function(window) {
    window.ls.container.get("view").add({
        selector: "data-cookies",
        controller: function(element, alerts, cookie, env) {
          if (!cookie.get("cookie-alert")) {
            let text = element.dataset["cookies"] || "";
    
            alerts.add(
              {
                text: text,
                class: "cookie-alert",
                link: env.HOME + "/policy/cookies",
                label: 'Learn More',
                callback: function() {
                  cookie.set("cookie-alert", "true", 365 * 10); // 10 years
                }
              },
              0
            );
          }
        }
      });
  })(window);  