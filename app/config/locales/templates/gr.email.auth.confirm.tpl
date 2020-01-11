<style>
    * {
        font-family: sans-serif,Arial;
        -webkit-font-smoothing: antialiased;
        font-weight: lighter;
    }
</style>

<div style="direction: {{direction}}">
    Γεια σου {{name}},
    <br />
    <br />
    Ακολούθησε αυτό τον σύνδεσμο για να επιβεβαιώσεις τη διεύθυνση email σου. 
    <br />
    <a href="{{redirect}}">{{redirect}}</a>
    <br />
    <br />
    Αν δεν ζήτησες να επιβεβαιώσεις αυτή τη διεύθυνση, μπορείς να αγνοήσεις αυτό το μήνυμα.
    <br />
    <br />
    Ευχαριστούμε,
    <br />
    Η ομάδα του {{project}}
</div>