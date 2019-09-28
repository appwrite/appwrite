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
    Te hemos enviamos este correo porque <b>{{owner}}</b> quiere invitarte a formar parte del equipo <b>{{team}}</b> en {{project}}.
    <br />
    <br />
    Sigue este enlace para unirte al equipo <b>{{team}}</b>:
    <br />
    <a href="{{redirect}}">{{redirect}}</a>
    <br />
    <br />
    Si no estás interesado, puedes ignorar este mensaje.
    <br />
    <br />
    Gracias,
    <br />
    Equipo {{project}}
</div>
