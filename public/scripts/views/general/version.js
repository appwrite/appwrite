(function(window) {
    window.ls.container.get("view").add({
        selector: "data-version",
        controller: function(alerts, env, cookie) {
          let cookieName = "version-update-" + env.VERSION.replace(/\./g, "_");

          if (!cookie.get(cookieName)) {
            var xhr = new XMLHttpRequest();

            xhr.open('GET', '/console/version', true);

            xhr.onload = function () {
              if (this.readyState == 4 && this.status == 200) {
                let data = JSON.parse(this.responseText);
                let text = 'Appwrite version ' + data.version + ' is available, check the';

                if(isNewerVersion(env.VERSION, data.version)) {
                  alerts.add({
                    text: text,
                    class: "success",
                    link: "https://github.com/appwrite/appwrite/releases",
                    label: 'release notes',
                    callback: function() {
                        cookie.set(cookieName, "true", 365 * 10); // 10 years
                    }
                  }, 0);
                }
              }
            };

            xhr.send(null);

            function isNewerVersion (oldVer, newVer) {
                const oldParts = oldVer.split('.')
                const newParts = newVer.split('.')
                for (var i = 0; i < newParts.length; i++) {
                  const a = parseInt(newParts[i]) || 0
                  const b = parseInt(oldParts[i]) || 0
                  if (a > b) return true
                  if (a < b) return false
                }
                return false
              }
          }
        }
      });
  })(window);  
