(function (window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-tags",
    controller: function (element, sdk) {
      let array = [];
      let tags = window.document.createElement("div");
      let preview = window.document.createElement("ul");
      let addContainer = window.document.createElement("div");
      let add = window.document.createElement("input");
      let recommendations = { users: [], teams: [] };
      let dropdown = window.document.createElement("div");
      let searchIcon = window.document.createElement("i");

      dropdown.className = "dropdown";
      searchIcon.className = "icon-search";

      let focus = function (event) {
        rerenderRecommendations();
      }

      let listen = function (event) {
        if (
          (event.key === "Enter" || event.key === " " || event.key === "Tab") &&
          add.value.length > 0
        ) {
          array.push(add.value);

          add.value = "";

          element.value = JSON.stringify(array);

          check();

          if (event.key !== "Tab") { // Don't lock accessibility
            event.preventDefault();
          };
        };
        if (
          (event.key === "Backspace" || event.key === "Delete") &&
          add.value === ""
        ) {
          array.splice(-1, 1);

          element.value = JSON.stringify(array);

          check();
        }

        autoComplete();

        return false;
      };

      let rerenderRecommendations = function () {
        let childNodesLength = dropdown.childNodes.length;

        while (childNodesLength--) {
          dropdown.removeChild(dropdown.lastChild);
        }

        recomendations.teams.forEach(element => {
          let child = window.document.createElement("div");
          child.innerText = `t: ${element.name}`;
          child.addEventListener('mousedown', function () {
            add.value = element.$id;
          });
          dropdown.appendChild(child);
        });

        recomendations.users.forEach(element => {
          let child = window.document.createElement("div");
          child.innerText = `u: ${element.name}`;
          child.addEventListener('mousedown', function () {
            add.value = element.$id;
          });
          dropdown.appendChild(child);
        });

        if (recomendations.users.length <= 0 && recomendations.teams.length <= 0) {
          let child = window.document.createElement("div");
          child.innerText = 'No Results';
          dropdown.appendChild(child);
        }

        addContainer.appendChild(dropdown);
      }

      let autoComplete = function () {
        if (add.value.length <= 0) {
          rerenderRecommendations();
          return;
        }

        recomendations = { users: [], teams: [] };

        // http://localhost/v1/teams?search=test&limit=15&orderType=DESC

        sdk.users.list(add.value, 5, 0, 'DESC').then((response) => {
          recomendations.users = [];
          response.users.forEach(element => {
            recomendations.users.push(element);
          });
          rerenderRecommendations();
        }, function (error) {
          console.log(error);
        });

        sdk.teams.list(add.value, 5, 0, 'DESC').then((response) => {
          recomendations.teams = [];
          response.teams.forEach(element => {
            recomendations.teams.push(element);
          });
          rerenderRecommendations();
        }, function (error) {
          console.log(error);
        });
      };

      let check = function () {
        // Issue.
        // try {
        //   array = JSON.parse(element.value) || [];
        // } catch (error) {
        //   array = [];
        // }

        if (!Array.isArray(array)) {
          array = [];
        };

        preview.innerHTML = "";

        for (let index = 0; index < array.length; index++) {
          let value = array[index];
          let tag = window.document.createElement("li");

          if (!value || value === ' ') {
            console.log('error');
            continue;
          };

          tag.className = "tag";
          tag.textContent = value;

          tag.addEventListener("click", function () {
            array.splice(index, 1);

            element.value = JSON.stringify(array);

            check();
          });

          preview.appendChild(tag);
        }

        if (element.required && array.length === 0) {
          add.setCustomValidity("Please add permissions");
        } else {
          add.setCustomValidity("");
        }
      };

      tags.className = "tags";

      preview.className = "tags-list";

      add.type = "text";
      add.className = "add";
      add.placeholder = element.placeholder;

      addContainer.className = "addContainer";

      // add.required = element.required;

      add.addEventListener('focus', function () {
        focus();
      })

      tags.addEventListener("click", function () {
        add.focus();
      });

      add.addEventListener("keyup", listen);
      add.addEventListener("focusout", function (event) {
        if (add.value !== '') {
          array.push(add.value);

          add.value = "";

          element.value = JSON.stringify(array);

          check();
        }

        dropdown.remove();
      });

      tags.appendChild(preview);
      addContainer.appendChild(add);
      addContainer.appendChild(searchIcon);
      tags.appendChild(addContainer);

      element.parentNode.insertBefore(tags, element);

      element.addEventListener("change", check);

      check();
    }
  });
})(window);
