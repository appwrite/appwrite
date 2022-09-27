(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-upload",
    controller: function(element, container, alerts, expression, env, search) {
      var scope = element.dataset["scope"];
      var project = expression.parse(element.dataset["project"] || "console");
      var labelButton = element.dataset["labelButton"] || "Upload";
      var labelLoading = element.dataset["labelLoading"] || "Uploading...";
      var previewWidth = element.dataset["previewWidth"] || 200;
      var previewHeight = element.dataset["previewHeight"] || 200;
      var previewAlt = element.dataset["previewAlt"] || 200;
      var accept = element.dataset["accept"] || "";
      var searchButton = (element.dataset["search"] || 0);
      var required = element.dataset["required"] || false;
      var className = element.dataset["class"] || "upload";
      var max = parseInt(element.dataset["max"] || 4);
      var sdk = scope === "sdk" ? container.get("sdk") : container.get("console");
      var output = element.value || null;

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

      upload.className = "button reverse margin-bottom-small";
      upload.innerHTML = '<i class="icon icon-upload"></i> ' + labelButton;
      upload.tabIndex = 0;

      preview.className = "preview";

      progress.className = "progress";
      progress.style.width = "0%";
      progress.style.display = "none";

      var onComplete = function(message) {
        alerts.remove(message);

        input.disabled = false;
        upload.classList.remove("disabled");
        progress.style.width = "0%";
        progress.style.display = "none";
      };

      var render = function(result) {
        try {
          result = JSON.parse(result);
        } catch(err) {
          // Not JSON = empty string. No image

        }
        preview.innerHTML = "";

        count.innerHTML = "0 / " + max;

        if(!result) {
          return;
        }

        var file = document.createElement("li");
        var image = document.createElement("img");

        image.src = image.src =
          env.API +
          "/storage/buckets/" +
          result.bucketId +
          "/files/" +
          result.fileId +
          "/preview?width=" +
          previewWidth +
          "&height=" +
          previewHeight +
          "&project="+project +
          "&mode=admin";

        image.alt = previewAlt;

        file.className = "file avatar";
        file.tabIndex = 0;
        file.appendChild(image);

        preview.appendChild(file);

        var remove = (function(result) {
          return function(event) {
            render(result.$id);
            element.value = '';
          };
        })(result);

        file.addEventListener("click", remove);
        file.addEventListener("keypress", remove);

        element.value = JSON.stringify(result);
      };

      input.addEventListener("change", function() {
        var message = alerts.add({ text: labelLoading, class: "" }, 0);
        var files = input.files;
        var permissions = JSON.parse(
            expression.parse(element.dataset["permissions"] || "[]")
        )

        sdk.storage.createFile('default', 'unique()', files[0], permissions).then(
          function(response) {
            onComplete(message);

            render({bucketId: response.bucketId, fileId: response.$id});
          },
          function(error) {
            alerts.add({ text: "An error occurred!", class: "" }, 3000); // File(s) uploaded.
            onComplete(message);
          }
        );

        input.disabled = true;
      });

      element.addEventListener("change", function() {
        if (!element.value) {
          return;
        }
        render(element.value);

        wrapper.scrollIntoView();
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

      if(searchButton) {
        let searchOpen = document.createElement("button");

        searchOpen.type = 'button';
        searchOpen.innerHTML = '<i class="icon icon-search"></i> Search';
        searchOpen.classList.add('reverse');

        let path = container.scope(searchButton);

        searchOpen.addEventListener('click', function() {
          search.selected = element.value;
          search.path = path;

          document.dispatchEvent(
            new CustomEvent("open-file-search", {
              bubbles: false,
              cancelable: true
            }));
        });

        wrapper.appendChild(searchOpen);
      }
    }
  });
})(window);
