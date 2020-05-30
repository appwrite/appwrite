(function(window) {
  "use strict";

  window.ls.container.get("view").add({
    selector: "data-forms-oauth-apple",
    controller: function(element) {
      let container = document.createElement("div");
      let row = document.createElement("div");
      let col1 = document.createElement("div");
      let col2 = document.createElement("div");
      let keyID = document.createElement("input");
      let keyLabel = document.createElement("label");
      let teamID = document.createElement("input");
      let teamLabel = document.createElement("label");
      let p8 = document.createElement("textarea");
      let p8Label = document.createElement("label");

      keyLabel.textContent = 'Key ID';
      teamLabel.textContent = 'Team ID';
      p8Label.textContent = 'P8 File';

      row.classList.add('row');
      row.classList.add('thin');
      container.appendChild(row);
      container.appendChild(p8Label);
      container.appendChild(p8);
      
      row.appendChild(col1);
      row.appendChild(col2);

      col1.classList.add('col');
      col1.classList.add('span-6');
      col1.appendChild(keyLabel);
      col1.appendChild(keyID);
      
      col2.classList.add('col');
      col2.classList.add('span-6');
      col2.appendChild(teamLabel);
      col2.appendChild(teamID);

      keyID.type = 'text';
      keyID.placeholder = 'SHAB13ROFN';
      teamID.type = 'text';
      teamID.placeholder = 'ELA2CD3AED';
      p8.accept = '.p8';
      p8.classList.add('margin-bottom-no');

      element.parentNode.insertBefore(container, element.nextSibling);
      
      element.addEventListener('change', sync);
      keyID.addEventListener('change', update);
      teamID.addEventListener('change', update);
      p8.addEventListener('change', update);

      function update() {
        let json = {};

        json.keyID = keyID.value;
        json.teamID = teamID.value;
        json.p8 = p8.value;

        element.value = JSON.stringify(json);
      }

      function sync() {
        console.log('sync');
        if(!element.value) {
          return;
        }

        let json = {};

        try {
          json = JSON.parse(element.value);
        } catch (error) {
          console.error('Failed to parse secret key');
        }

        teamID.value = json.teamID || '';
        keyID.value = json.keyID || '';
        p8.value = json.p8 || '';
      }

      // function syncB() {
      //   picker.value = element.value;
      // }

      // element.parentNode.insertBefore(preview, element);

      // update();
      sync();
    }
  });
})(window);
