<style>
    * {
        font-family: sans-serif,Arial;
        -webkit-font-smoothing: antialiased;
        font-weight: lighter;
    }
</style>

<div style="direction: {{direction}}">
    Kamusta,
    <br />
    <br />
    Ipinadala ang email na ito sa iyo dahil nais kang anyayahan ni <b>{{ owner }} upang maging isang kasapi ng pangkat ng <b>{{ team }} sa {{ project }}.
    <br />
    <br />
    Sundan ang link na ito upang sumali sa pangkat ng <b>{{team}}</b>:
    <br />
    <a href="{{redirect}}">{{redirect}}</a>
    <br />
    <br />
    Kung hindi ka interesado, maari mong balewalain ang mensahing ito.
    <br />
    <br />
    Salamat,
    <br />
    Pangkat ng {{project}}
</div>
