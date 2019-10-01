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
    Sigue este enlace para reestablecer tu contrase&ntilde;a de {{project}}.
    <br />
    <a href="{{redirect}}">{{redirect}}</a>
    <br />
    <br />
    Si no has pedido reestablecer tu contrase&ntilde;a, puedes ignorar este mensaje.
    <br />
    <br />
    Gracias,
    <br />
    Equipo {{project}}
</div>
