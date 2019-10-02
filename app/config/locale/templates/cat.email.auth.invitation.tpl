<style>
    * {
        font-family: sans-serif,Arial;
        -webkit-font-smoothing: antialiased;
        font-weight: lighter;
    }
</style>

<div style="direction: {{direction}}">
    Hola,
    <br />
    <br />
    T'hem enviat aquest correu perquè <b>{{owner}}</b> et vol convidar a formar part
    de l'equip <b>{{team}}</b> a {{project}}.
    <br />
    <br />
    Segueix aquest enllaç per unir-te a l'equip <b>{{team}}</b>:
    <br />
    <a href="{{redirect}}">{{redirect}}</a>
    <br />
    <br />
    Si no estàs interessat, pots ignorar aquest missatge.
    <br />
    <br />
    Gràcies,
    <br />
    Equip {{project}}
</div>
