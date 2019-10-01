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
    Sigue este enlace para verificar tu direcci&oacute;n de correo:
    <br />
    <a href="{{redirect}}">{{redirect}}</a>
    <br />
    <br />
    Si no has solicitado verificar esta direcci&oacute;n, puedes ignorar este mensaje.
    <br />
    <br />
    Gracias,
    <br />
    Equipo {{project}} 
</div>