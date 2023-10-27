/**
 * @param {string} s
 * @return {string}
 */
var longestPalindrome = function(s) {
    if(s.length===1) return s[0];
    let count = 0, ans = s[0];
    for(let i=0; i<=s.length-2; i++){
        for(let j=s.length-1;j>i; j--){
            if(isPallindrome(s,i,j)){
                if(j-i > count){
                    count = j-i;
                    ans = str(s,i,j);
                }
                
            }
        }
    }
    return ans;
};

function str(string, s,e){
    let res = "";
    for(let i=s; i<=e; i++){
        res += string[i];
    }
    console.log(res)
    return res;
}

function isPallindrome(str,s,e){
    while(s<e){
        if(str[s]!==str[e]) return false;
        s++,e--;
    }
    return true;
}
