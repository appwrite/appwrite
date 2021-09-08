(function(window) {
    window.ls.container.get("view").add({
        selector: "data-version",
        controller: function(element, alerts, env, cookie) {
          let cookieName = "version-update-" + env.VERSION.replace(/\./g, "_");

          var translationChunk1 = element.getAttribute("data-version-translation-chunk1") || 'Appwrite version';
          var translationChunk2 = element.getAttribute("data-version-translation-chunk2") || 'is available, check the';
          var translationChunk3 = element.getAttribute("data-version-translation-chunk3") || 'release notes';

            if (!cookie.get(cookieName)) {
            var xhr = new XMLHttpRequest();

            xhr.open('GET', '/console/version', true);

            xhr.onload = function () {
              if (this.readyState == 4 && this.status == 200) {
                let data = JSON.parse(this.responseText);
                let text = translationChunk1 + ' ' + data.version + ' ' + translationChunk2;

                if(isNewerVersion(env.VERSION, data.version)) {
                  alerts.add({
                    text: text,
                    class: "success",
                    link: "https://github.com/appwrite/appwrite/releases",
                    label: translationChunk3,
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