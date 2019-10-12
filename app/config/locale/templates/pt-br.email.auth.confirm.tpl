<style>
    * {
        font-family: sans-serif,Arial;
        -webkit-font-smoothing: antialiased;
        font-weight: lighter;
    }
</style>

<div style="direction: {{direction}}">
    Olá {{name}},
    <br />
    <br />
    Por favor, confirme o seu email acessando o link abaixo.
    <br />
    <a href="{{redirect}}">{{redirect}}</a>
    <br />
    <br />
    Caso a confirmação de email não foi solicitada por você, ignore esta mensagem.  
    <br />
    <br />
    Atenciosamente,
    <br />
    Equipe {{project}}
</div>
