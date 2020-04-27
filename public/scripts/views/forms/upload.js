(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-upload",
    controller: function(element, container, alerts, expression, env) {
      var scope = element.dataset["scope"];
      var project = expression.parse(element.dataset["project"] || "console");
      var labelButton = element.dataset["labelButton"] || "Upload";
      var labelLoading = element.dataset["labelLoading"] || "Uploading...";
      var previewWidth = element.dataset["previewWidth"] || 200;
      var previewHeight = element.dataset["previewHeight"] || 200;
      var accept = element.dataset["accept"] || "";
      var required = element.dataset["required"] || false;
      var className = element.dataset["class"] || "upload";
      var max = parseInt(element.dataset["max"] || 4);
      var sdk =
        scope === "sdk" ? container.get("sdk") : container.get("console");
      var output = element.value || null;
      var total = 0;

      var wrapper = document.createElement("div");
      var input = document.createElement("input");
      var upload = document.createElement("div"); // Fake button
      var preview = document.createElement("ul");
      var progress = document.createElement("div");
      var count = document.createElement("div");

      wrapper.className = className;

      input.type = "file";
      input.accept = accept;
      input.required = required;
      input.tabIndex = -1;

      count.className = "count";

      upload.className = "button reverse margin-bottom";
      upload.innerHTML = '<i class="icon icon-upload"></i> ' + labelButton;
      upload.tabIndex = 0;

      preview.className = "preview";

      progress.className = "progress";
      progress.style.width = "0%";
      progress.style.display = "none";

      var humanFileSize = function(bytes, si) {
        var thresh = si ? 1000 : 1024;

        if (Math.abs(bytes) < thresh) {
          return bytes + " B";
        }

        var units = si
          ? ["KB", "MB", "GB", "TB", "PB", "EB", "ZB", "YB"]
          : ["KiB", "MiB", "GiB", "TiB", "PiB", "EiB", "ZiB", "YiB"];

        var u = -1;

        do {
          bytes /= thresh;
          ++u;
        } while (Math.abs(bytes) >= thresh && u < units.length - 1);

        return bytes.toFixed(1) + " " + units[u];
      };

      var onComplete = function(message) {
        alerts.remove(message);

        input.disabled = false;
        upload.classList.remove("disabled");
        progress.style.width = "0%";
        progress.style.display = "none";
      };

      var render = function(result) {
        preview.innerHTML = "";

        count.innerHTML = "0 / " + max;

        if(!result) {
          return;
        }

        var file = document.createElement("li");
        var image = document.createElement("img");

        image.src = image.src =
          env.API +
          "/storage/files/" +
          result +
          "/preview?width=" +
          previewWidth +
          "&height=" +
          previewHeight +
          "&project="+project +
          "&mode=admin";

        file.className = "file avatar";
        file.tabIndex = 0;
        file.appendChild(image);

        preview.appendChild(file);

        var remove = (function(result) {
          return function(event) {
            render(result.$id);
          };
        })(result);

        file.addEventListener("click", remove);
        file.addEventListener("keypress", remove);

        element.value = result;
      };

      input.addEventListener("change", function() {
        var message = alerts.add({ text: labelLoading, class: "" }, 0);
        var files = input.files;
        var read = JSON.parse(
          expression.parse(element.dataset["read"] || "[]")
        );
        var write = JSON.parse(
          expression.parse(element.dataset["write"] || "[]")
        );

        sdk.storage.createFile(files[0], read, write, 1).then(
          function(response) {
            onComplete(message);

            render(response.$id);
          },
          function(error) {
            alerts.add({ text: "An error occurred!", class: "" }, 3000); // File(s) uploaded.
            onComplete(message);
          }
        );

        input.disabled = true;
      });

      element.addEventListener("change", function() {
        console.log('change', element);
        if (!element.value) {
          return;
        }
        render(output);
      });

      upload.addEventListener("keypress", function() {
        input.click();
      });

      element.parentNode.insertBefore(wrapper, element);

      wrapper.appendChild(preview);
      wrapper.appendChild(progress);
      wrapper.appendChild(upload);

      upload.appendChild(input);

      render(output);
    }
  });
})(window);
