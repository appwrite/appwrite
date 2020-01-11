<style>
    * {
        font-family: sans-serif,Arial;
        -webkit-font-smoothing: antialiased;
        font-weight: lighter;
    }
</style>

<div style="direction: {{direction}}">
    Hola {{name}},
    <br />
    <br />
    Segueix aquest enllaç per verificar la teva direcció de correu:
    <br />
    <a href="{{redirect}}">{{redirect}}</a>
    <br />
    <br />
    Si no has solicitat verificar aquesta direcció, pots ignorar aquest missatge.
    <br />
    <br />
    Gràcies,
    <br />
    Equip {{project}} 
</div>
