//! PHP `Utopia\\Messaging\\Adapter\\SMS\\GEOSMS\\CallingCode`.

use std::collections::HashSet;
use std::sync::OnceLock;

/// Country calling codes (PHP `CallingCode`).
#[derive(Debug, Clone, Copy)]
pub struct CallingCode;

impl CallingCode {
    /// PHP `CallingCode::ALGERIA`.
    pub const ALGERIA: &'static str = "213";

    /// PHP `CallingCode::ANDORRA`.
    pub const ANDORRA: &'static str = "376";

    /// PHP `CallingCode::ANGOLA`.
    pub const ANGOLA: &'static str = "244";

    /// PHP `CallingCode::ARGENTINA`.
    pub const ARGENTINA: &'static str = "54";

    /// PHP `CallingCode::ARMENIA`.
    pub const ARMENIA: &'static str = "374";

    /// PHP `CallingCode::ARUBA`.
    pub const ARUBA: &'static str = "297";

    /// PHP `CallingCode::AUSTRALIA`.
    pub const AUSTRALIA: &'static str = "61";

    /// PHP `CallingCode::AUSTRIA`.
    pub const AUSTRIA: &'static str = "43";

    /// PHP `CallingCode::AZERBAIJAN`.
    pub const AZERBAIJAN: &'static str = "994";

    /// PHP `CallingCode::BAHRAIN`.
    pub const BAHRAIN: &'static str = "973";

    /// PHP `CallingCode::BANGLADESH`.
    pub const BANGLADESH: &'static str = "880";

    /// PHP `CallingCode::BELARUS`.
    pub const BELARUS: &'static str = "375";

    /// PHP `CallingCode::BELGIUM`.
    pub const BELGIUM: &'static str = "32";

    /// PHP `CallingCode::BELIZE`.
    pub const BELIZE: &'static str = "501";

    /// PHP `CallingCode::BENIN`.
    pub const BENIN: &'static str = "229";

    /// PHP `CallingCode::BHUTAN`.
    pub const BHUTAN: &'static str = "975";

    /// PHP `CallingCode::BOLIVIA`.
    pub const BOLIVIA: &'static str = "591";

    /// PHP `CallingCode::BOSNIA_HERZEGOVINA`.
    pub const BOSNIA_HERZEGOVINA: &'static str = "387";

    /// PHP `CallingCode::BOTSWANA`.
    pub const BOTSWANA: &'static str = "267";

    /// PHP `CallingCode::BRAZIL`.
    pub const BRAZIL: &'static str = "55";

    /// PHP `CallingCode::BRUNEI`.
    pub const BRUNEI: &'static str = "673";

    /// PHP `CallingCode::BULGARIA`.
    pub const BULGARIA: &'static str = "359";

    /// PHP `CallingCode::BURKINA_FASO`.
    pub const BURKINA_FASO: &'static str = "226";

    /// PHP `CallingCode::BURUNDI`.
    pub const BURUNDI: &'static str = "257";

    /// PHP `CallingCode::CAMBODIA`.
    pub const CAMBODIA: &'static str = "855";

    /// PHP `CallingCode::CAMEROON`.
    pub const CAMEROON: &'static str = "237";

    /// PHP `CallingCode::CAPE_VERDE_ISLANDS`.
    pub const CAPE_VERDE_ISLANDS: &'static str = "238";

    /// PHP `CallingCode::CENTRAL_AFRICAN_REPUBLIC`.
    pub const CENTRAL_AFRICAN_REPUBLIC: &'static str = "236";

    /// PHP `CallingCode::CHILE`.
    pub const CHILE: &'static str = "56";

    /// PHP `CallingCode::CHINA`.
    pub const CHINA: &'static str = "86";

    /// PHP `CallingCode::COLOMBIA`.
    pub const COLOMBIA: &'static str = "57";

    /// PHP `CallingCode::COMOROS_AND_MAYOTTE`.
    pub const COMOROS_AND_MAYOTTE: &'static str = "269";

    /// PHP `CallingCode::CONGO`.
    pub const CONGO: &'static str = "242";

    /// PHP `CallingCode::COOK_ISLANDS`.
    pub const COOK_ISLANDS: &'static str = "682";

    /// PHP `CallingCode::COSTA_RICA`.
    pub const COSTA_RICA: &'static str = "506";

    /// PHP `CallingCode::CROATIA`.
    pub const CROATIA: &'static str = "385";

    /// PHP `CallingCode::CUBA`.
    pub const CUBA: &'static str = "53";

    /// PHP `CallingCode::CYPRUS`.
    pub const CYPRUS: &'static str = "357";

    /// PHP `CallingCode::CZECH_REPUBLIC`.
    pub const CZECH_REPUBLIC: &'static str = "420";

    /// PHP `CallingCode::DENMARK`.
    pub const DENMARK: &'static str = "45";

    /// PHP `CallingCode::DJIBOUTI`.
    pub const DJIBOUTI: &'static str = "253";

    /// PHP `CallingCode::ECUADOR`.
    pub const ECUADOR: &'static str = "593";

    /// PHP `CallingCode::EGYPT`.
    pub const EGYPT: &'static str = "20";

    /// PHP `CallingCode::EL_SALVADOR`.
    pub const EL_SALVADOR: &'static str = "503";

    /// PHP `CallingCode::EQUATORIAL_GUINEA`.
    pub const EQUATORIAL_GUINEA: &'static str = "240";

    /// PHP `CallingCode::ERITREA`.
    pub const ERITREA: &'static str = "291";

    /// PHP `CallingCode::ESTONIA`.
    pub const ESTONIA: &'static str = "372";

    /// PHP `CallingCode::ETHIOPIA`.
    pub const ETHIOPIA: &'static str = "251";

    /// PHP `CallingCode::FALKLAND_ISLANDS`.
    pub const FALKLAND_ISLANDS: &'static str = "500";

    /// PHP `CallingCode::FAROE_ISLANDS`.
    pub const FAROE_ISLANDS: &'static str = "298";

    /// PHP `CallingCode::FIJI`.
    pub const FIJI: &'static str = "679";

    /// PHP `CallingCode::FINLAND`.
    pub const FINLAND: &'static str = "358";

    /// PHP `CallingCode::FRANCE`.
    pub const FRANCE: &'static str = "33";

    /// PHP `CallingCode::FRENCH_GUIANA`.
    pub const FRENCH_GUIANA: &'static str = "594";

    /// PHP `CallingCode::FRENCH_POLYNESIA`.
    pub const FRENCH_POLYNESIA: &'static str = "689";

    /// PHP `CallingCode::GABON`.
    pub const GABON: &'static str = "241";

    /// PHP `CallingCode::GAMBIA`.
    pub const GAMBIA: &'static str = "220";

    /// PHP `CallingCode::GEORGIA`.
    pub const GEORGIA: &'static str = "995";

    /// PHP `CallingCode::GERMANY`.
    pub const GERMANY: &'static str = "49";

    /// PHP `CallingCode::GHANA`.
    pub const GHANA: &'static str = "233";

    /// PHP `CallingCode::GIBRALTAR`.
    pub const GIBRALTAR: &'static str = "350";

    /// PHP `CallingCode::GREECE`.
    pub const GREECE: &'static str = "30";

    /// PHP `CallingCode::GREENLAND`.
    pub const GREENLAND: &'static str = "299";

    /// PHP `CallingCode::GUADELOUPE`.
    pub const GUADELOUPE: &'static str = "590";

    /// PHP `CallingCode::GUAM`.
    pub const GUAM: &'static str = "671";

    /// PHP `CallingCode::GUATEMALA`.
    pub const GUATEMALA: &'static str = "502";

    /// PHP `CallingCode::GUINEA`.
    pub const GUINEA: &'static str = "224";

    /// PHP `CallingCode::GUINEA_BISSAU`.
    pub const GUINEA_BISSAU: &'static str = "245";

    /// PHP `CallingCode::GUYANA`.
    pub const GUYANA: &'static str = "592";

    /// PHP `CallingCode::HAITI`.
    pub const HAITI: &'static str = "509";

    /// PHP `CallingCode::HONDURAS`.
    pub const HONDURAS: &'static str = "504";

    /// PHP `CallingCode::HONG_KONG`.
    pub const HONG_KONG: &'static str = "852";

    /// PHP `CallingCode::HUNGARY`.
    pub const HUNGARY: &'static str = "36";

    /// PHP `CallingCode::ICELAND`.
    pub const ICELAND: &'static str = "354";

    /// PHP `CallingCode::INDIA`.
    pub const INDIA: &'static str = "91";

    /// PHP `CallingCode::INDONESIA`.
    pub const INDONESIA: &'static str = "62";

    /// PHP `CallingCode::IRAN`.
    pub const IRAN: &'static str = "98";

    /// PHP `CallingCode::IRAQ`.
    pub const IRAQ: &'static str = "964";

    /// PHP `CallingCode::IRELAND`.
    pub const IRELAND: &'static str = "353";

    /// PHP `CallingCode::ISRAEL`.
    pub const ISRAEL: &'static str = "972";

    /// PHP `CallingCode::ITALY`.
    pub const ITALY: &'static str = "39";

    /// PHP `CallingCode::JAPAN`.
    pub const JAPAN: &'static str = "81";

    /// PHP `CallingCode::JORDAN`.
    pub const JORDAN: &'static str = "962";

    /// PHP `CallingCode::KENYA`.
    pub const KENYA: &'static str = "254";

    /// PHP `CallingCode::KIRIBATI`.
    pub const KIRIBATI: &'static str = "686";

    /// PHP `CallingCode::NORTH_KOREA`.
    pub const NORTH_KOREA: &'static str = "850";

    /// PHP `CallingCode::SOUTH_KOREA`.
    pub const SOUTH_KOREA: &'static str = "82";

    /// PHP `CallingCode::KUWAIT`.
    pub const KUWAIT: &'static str = "965";

    /// PHP `CallingCode::KYRGYZSTAN`.
    pub const KYRGYZSTAN: &'static str = "996";

    /// PHP `CallingCode::LAOS`.
    pub const LAOS: &'static str = "856";

    /// PHP `CallingCode::LATVIA`.
    pub const LATVIA: &'static str = "371";

    /// PHP `CallingCode::LEBANON`.
    pub const LEBANON: &'static str = "961";

    /// PHP `CallingCode::LESOTHO`.
    pub const LESOTHO: &'static str = "266";

    /// PHP `CallingCode::LIBERIA`.
    pub const LIBERIA: &'static str = "231";

    /// PHP `CallingCode::LIBYA`.
    pub const LIBYA: &'static str = "218";

    /// PHP `CallingCode::LIECHTENSTEIN`.
    pub const LIECHTENSTEIN: &'static str = "417";

    /// PHP `CallingCode::LITHUANIA`.
    pub const LITHUANIA: &'static str = "370";

    /// PHP `CallingCode::LUXEMBOURG`.
    pub const LUXEMBOURG: &'static str = "352";

    /// PHP `CallingCode::MACAO`.
    pub const MACAO: &'static str = "853";

    /// PHP `CallingCode::MACEDONIA`.
    pub const MACEDONIA: &'static str = "389";

    /// PHP `CallingCode::MADAGASCAR`.
    pub const MADAGASCAR: &'static str = "261";

    /// PHP `CallingCode::MALAWI`.
    pub const MALAWI: &'static str = "265";

    /// PHP `CallingCode::MALAYSIA`.
    pub const MALAYSIA: &'static str = "60";

    /// PHP `CallingCode::MALDIVES`.
    pub const MALDIVES: &'static str = "960";

    /// PHP `CallingCode::MALI`.
    pub const MALI: &'static str = "223";

    /// PHP `CallingCode::MALTA`.
    pub const MALTA: &'static str = "356";

    /// PHP `CallingCode::MARSHALL_ISLANDS`.
    pub const MARSHALL_ISLANDS: &'static str = "692";

    /// PHP `CallingCode::MARTINIQUE`.
    pub const MARTINIQUE: &'static str = "596";

    /// PHP `CallingCode::MAURITANIA`.
    pub const MAURITANIA: &'static str = "222";

    /// PHP `CallingCode::MEXICO`.
    pub const MEXICO: &'static str = "52";

    /// PHP `CallingCode::MICRONESIA`.
    pub const MICRONESIA: &'static str = "691";

    /// PHP `CallingCode::MOLDOVA`.
    pub const MOLDOVA: &'static str = "373";

    /// PHP `CallingCode::MONACO`.
    pub const MONACO: &'static str = "377";

    /// PHP `CallingCode::MONGOLIA`.
    pub const MONGOLIA: &'static str = "976";

    /// PHP `CallingCode::MOROCCO`.
    pub const MOROCCO: &'static str = "212";

    /// PHP `CallingCode::MOZAMBIQUE`.
    pub const MOZAMBIQUE: &'static str = "258";

    /// PHP `CallingCode::MYANMAR`.
    pub const MYANMAR: &'static str = "95";

    /// PHP `CallingCode::NAMIBIA`.
    pub const NAMIBIA: &'static str = "264";

    /// PHP `CallingCode::NAURU`.
    pub const NAURU: &'static str = "674";

    /// PHP `CallingCode::NEPAL`.
    pub const NEPAL: &'static str = "977";

    /// PHP `CallingCode::NETHERLANDS`.
    pub const NETHERLANDS: &'static str = "31";

    /// PHP `CallingCode::NEW_CALEDONIA`.
    pub const NEW_CALEDONIA: &'static str = "687";

    /// PHP `CallingCode::NEW_ZEALAND`.
    pub const NEW_ZEALAND: &'static str = "64";

    /// PHP `CallingCode::NICARAGUA`.
    pub const NICARAGUA: &'static str = "505";

    /// PHP `CallingCode::NIGER`.
    pub const NIGER: &'static str = "227";

    /// PHP `CallingCode::NIGERIA`.
    pub const NIGERIA: &'static str = "234";

    /// PHP `CallingCode::NIUE`.
    pub const NIUE: &'static str = "683";

    /// PHP `CallingCode::NORFOLK_ISLANDS`.
    pub const NORFOLK_ISLANDS: &'static str = "672";

    /// PHP `CallingCode::NORTH_AMERICA`.
    pub const NORTH_AMERICA: &'static str = "1";

    /// PHP `CallingCode::NORTHERN_MARIANA_ISLANDS`.
    pub const NORTHERN_MARIANA_ISLANDS: &'static str = "670";

    /// PHP `CallingCode::NORWAY`.
    pub const NORWAY: &'static str = "47";

    /// PHP `CallingCode::OMAN`.
    pub const OMAN: &'static str = "968";

    /// PHP `CallingCode::PALAU`.
    pub const PALAU: &'static str = "680";

    /// PHP `CallingCode::PANAMA`.
    pub const PANAMA: &'static str = "507";

    /// PHP `CallingCode::PAPUA_NEW_GUINEA`.
    pub const PAPUA_NEW_GUINEA: &'static str = "675";

    /// PHP `CallingCode::PARAGUAY`.
    pub const PARAGUAY: &'static str = "595";

    /// PHP `CallingCode::PERU`.
    pub const PERU: &'static str = "51";

    /// PHP `CallingCode::PHILIPPINES`.
    pub const PHILIPPINES: &'static str = "63";

    /// PHP `CallingCode::POLAND`.
    pub const POLAND: &'static str = "48";

    /// PHP `CallingCode::PORTUGAL`.
    pub const PORTUGAL: &'static str = "351";

    /// PHP `CallingCode::QATAR`.
    pub const QATAR: &'static str = "974";

    /// PHP `CallingCode::REUNION`.
    pub const REUNION: &'static str = "262";

    /// PHP `CallingCode::ROMANIA`.
    pub const ROMANIA: &'static str = "40";

    /// PHP `CallingCode::RUSSIA_KAZAKHSTAN_UZBEKISTAN_TURKMENISTAN_AND_TAJIKSTAN`.
    pub const RUSSIA_KAZAKHSTAN_UZBEKISTAN_TURKMENISTAN_AND_TAJIKSTAN: &'static str = "7";

    /// PHP `CallingCode::RWANDA`.
    pub const RWANDA: &'static str = "250";

    /// PHP `CallingCode::SAN_MARINO`.
    pub const SAN_MARINO: &'static str = "378";

    /// PHP `CallingCode::SAO_TOME_AND_PRINCIPE`.
    pub const SAO_TOME_AND_PRINCIPE: &'static str = "239";

    /// PHP `CallingCode::SAUDI_ARABIA`.
    pub const SAUDI_ARABIA: &'static str = "966";

    /// PHP `CallingCode::SENEGAL`.
    pub const SENEGAL: &'static str = "221";

    /// PHP `CallingCode::SERBIA`.
    pub const SERBIA: &'static str = "381";

    /// PHP `CallingCode::SEYCHELLES`.
    pub const SEYCHELLES: &'static str = "248";

    /// PHP `CallingCode::SIERRA_LEONE`.
    pub const SIERRA_LEONE: &'static str = "232";

    /// PHP `CallingCode::SINGAPORE`.
    pub const SINGAPORE: &'static str = "65";

    /// PHP `CallingCode::SLOVAK_REPUBLIC`.
    pub const SLOVAK_REPUBLIC: &'static str = "421";

    /// PHP `CallingCode::SLOVENIA`.
    pub const SLOVENIA: &'static str = "386";

    /// PHP `CallingCode::SOLOMON_ISLANDS`.
    pub const SOLOMON_ISLANDS: &'static str = "677";

    /// PHP `CallingCode::SOMALIA`.
    pub const SOMALIA: &'static str = "252";

    /// PHP `CallingCode::SOUTH_AFRICA`.
    pub const SOUTH_AFRICA: &'static str = "27";

    /// PHP `CallingCode::SPAIN`.
    pub const SPAIN: &'static str = "34";

    /// PHP `CallingCode::SRI_LANKA`.
    pub const SRI_LANKA: &'static str = "94";

    /// PHP `CallingCode::ST_HELENA`.
    pub const ST_HELENA: &'static str = "290";

    /// PHP `CallingCode::SUDAN`.
    pub const SUDAN: &'static str = "249";

    /// PHP `CallingCode::SURINAME`.
    pub const SURINAME: &'static str = "597";

    /// PHP `CallingCode::SWAZILAND`.
    pub const SWAZILAND: &'static str = "268";

    /// PHP `CallingCode::SWEDEN`.
    pub const SWEDEN: &'static str = "46";

    /// PHP `CallingCode::SWITZERLAND`.
    pub const SWITZERLAND: &'static str = "41";

    /// PHP `CallingCode::SYRIA`.
    pub const SYRIA: &'static str = "963";

    /// PHP `CallingCode::TAIWAN`.
    pub const TAIWAN: &'static str = "886";

    /// PHP `CallingCode::THAILAND`.
    pub const THAILAND: &'static str = "66";

    /// PHP `CallingCode::TOGO`.
    pub const TOGO: &'static str = "228";

    /// PHP `CallingCode::TONGA`.
    pub const TONGA: &'static str = "676";

    /// PHP `CallingCode::TUNISIA`.
    pub const TUNISIA: &'static str = "216";

    /// PHP `CallingCode::TURKEY`.
    pub const TURKEY: &'static str = "90";

    /// PHP `CallingCode::TUVALU`.
    pub const TUVALU: &'static str = "688";

    /// PHP `CallingCode::UGANDA`.
    pub const UGANDA: &'static str = "256";

    /// PHP `CallingCode::UKRAINE`.
    pub const UKRAINE: &'static str = "380";

    /// PHP `CallingCode::UNITED_ARAB_EMIRATES`.
    pub const UNITED_ARAB_EMIRATES: &'static str = "971";

    /// PHP `CallingCode::UNITED_KINGDOM`.
    pub const UNITED_KINGDOM: &'static str = "44";

    /// PHP `CallingCode::URUGUAY`.
    pub const URUGUAY: &'static str = "598";

    /// PHP `CallingCode::VANUATU`.
    pub const VANUATU: &'static str = "678";

    /// PHP `CallingCode::VENEZUELA`.
    pub const VENEZUELA: &'static str = "58";

    /// PHP `CallingCode::VIETNAM`.
    pub const VIETNAM: &'static str = "84";

    /// PHP `CallingCode::WALLIS_AND_FUTUNA`.
    pub const WALLIS_AND_FUTUNA: &'static str = "681";

    /// PHP `CallingCode::YEMEN`.
    pub const YEMEN: &'static str = "967";

    /// PHP `CallingCode::ZAMBIA`.
    pub const ZAMBIA: &'static str = "260";

    /// PHP `CallingCode::ZANZIBAR`.
    pub const ZANZIBAR: &'static str = "255";

    /// PHP `CallingCode::ZIMBABWE`.
    pub const ZIMBABWE: &'static str = "263";

    fn codes() -> &'static HashSet<&'static str> {
        static CODES: OnceLock<HashSet<&'static str>> = OnceLock::new();
        CODES.get_or_init(|| {
            HashSet::from([
                Self::ALGERIA,
                Self::ANDORRA,
                Self::ANGOLA,
                Self::ARGENTINA,
                Self::ARMENIA,
                Self::ARUBA,
                Self::AUSTRALIA,
                Self::AUSTRIA,
                Self::AZERBAIJAN,
                Self::BAHRAIN,
                Self::BANGLADESH,
                Self::BELARUS,
                Self::BELGIUM,
                Self::BELIZE,
                Self::BENIN,
                Self::BHUTAN,
                Self::BOLIVIA,
                Self::BOSNIA_HERZEGOVINA,
                Self::BOTSWANA,
                Self::BRAZIL,
                Self::BRUNEI,
                Self::BULGARIA,
                Self::BURKINA_FASO,
                Self::BURUNDI,
                Self::CAMBODIA,
                Self::CAMEROON,
                Self::CAPE_VERDE_ISLANDS,
                Self::CENTRAL_AFRICAN_REPUBLIC,
                Self::CHILE,
                Self::CHINA,
                Self::COLOMBIA,
                Self::COMOROS_AND_MAYOTTE,
                Self::CONGO,
                Self::COOK_ISLANDS,
                Self::COSTA_RICA,
                Self::CROATIA,
                Self::CUBA,
                Self::CYPRUS,
                Self::CZECH_REPUBLIC,
                Self::DENMARK,
                Self::DJIBOUTI,
                Self::ECUADOR,
                Self::EGYPT,
                Self::EL_SALVADOR,
                Self::EQUATORIAL_GUINEA,
                Self::ERITREA,
                Self::ESTONIA,
                Self::ETHIOPIA,
                Self::FALKLAND_ISLANDS,
                Self::FAROE_ISLANDS,
                Self::FIJI,
                Self::FINLAND,
                Self::FRANCE,
                Self::FRENCH_GUIANA,
                Self::FRENCH_POLYNESIA,
                Self::GABON,
                Self::GAMBIA,
                Self::GEORGIA,
                Self::GERMANY,
                Self::GHANA,
                Self::GIBRALTAR,
                Self::GREECE,
                Self::GREENLAND,
                Self::GUADELOUPE,
                Self::GUAM,
                Self::GUATEMALA,
                Self::GUINEA,
                Self::GUINEA_BISSAU,
                Self::GUYANA,
                Self::HAITI,
                Self::HONDURAS,
                Self::HONG_KONG,
                Self::HUNGARY,
                Self::ICELAND,
                Self::INDIA,
                Self::INDONESIA,
                Self::IRAN,
                Self::IRAQ,
                Self::IRELAND,
                Self::ISRAEL,
                Self::ITALY,
                Self::JAPAN,
                Self::JORDAN,
                Self::KENYA,
                Self::KIRIBATI,
                Self::NORTH_KOREA,
                Self::SOUTH_KOREA,
                Self::KUWAIT,
                Self::KYRGYZSTAN,
                Self::LAOS,
                Self::LATVIA,
                Self::LEBANON,
                Self::LESOTHO,
                Self::LIBERIA,
                Self::LIBYA,
                Self::LIECHTENSTEIN,
                Self::LITHUANIA,
                Self::LUXEMBOURG,
                Self::MACAO,
                Self::MACEDONIA,
                Self::MADAGASCAR,
                Self::MALAWI,
                Self::MALAYSIA,
                Self::MALDIVES,
                Self::MALI,
                Self::MALTA,
                Self::MARSHALL_ISLANDS,
                Self::MARTINIQUE,
                Self::MAURITANIA,
                Self::MEXICO,
                Self::MICRONESIA,
                Self::MOLDOVA,
                Self::MONACO,
                Self::MONGOLIA,
                Self::MOROCCO,
                Self::MOZAMBIQUE,
                Self::MYANMAR,
                Self::NAMIBIA,
                Self::NAURU,
                Self::NEPAL,
                Self::NETHERLANDS,
                Self::NEW_CALEDONIA,
                Self::NEW_ZEALAND,
                Self::NICARAGUA,
                Self::NIGER,
                Self::NIGERIA,
                Self::NIUE,
                Self::NORFOLK_ISLANDS,
                Self::NORTH_AMERICA,
                Self::NORTHERN_MARIANA_ISLANDS,
                Self::NORWAY,
                Self::OMAN,
                Self::PALAU,
                Self::PANAMA,
                Self::PAPUA_NEW_GUINEA,
                Self::PARAGUAY,
                Self::PERU,
                Self::PHILIPPINES,
                Self::POLAND,
                Self::PORTUGAL,
                Self::QATAR,
                Self::REUNION,
                Self::ROMANIA,
                Self::RUSSIA_KAZAKHSTAN_UZBEKISTAN_TURKMENISTAN_AND_TAJIKSTAN,
                Self::RWANDA,
                Self::SAN_MARINO,
                Self::SAO_TOME_AND_PRINCIPE,
                Self::SAUDI_ARABIA,
                Self::SENEGAL,
                Self::SERBIA,
                Self::SEYCHELLES,
                Self::SIERRA_LEONE,
                Self::SINGAPORE,
                Self::SLOVAK_REPUBLIC,
                Self::SLOVENIA,
                Self::SOLOMON_ISLANDS,
                Self::SOMALIA,
                Self::SOUTH_AFRICA,
                Self::SPAIN,
                Self::SRI_LANKA,
                Self::ST_HELENA,
                Self::SUDAN,
                Self::SURINAME,
                Self::SWAZILAND,
                Self::SWEDEN,
                Self::SWITZERLAND,
                Self::SYRIA,
                Self::TAIWAN,
                Self::THAILAND,
                Self::TOGO,
                Self::TONGA,
                Self::TUNISIA,
                Self::TURKEY,
                Self::TUVALU,
                Self::UGANDA,
                Self::UKRAINE,
                Self::UNITED_ARAB_EMIRATES,
                Self::UNITED_KINGDOM,
                Self::URUGUAY,
                Self::VANUATU,
                Self::VENEZUELA,
                Self::VIETNAM,
                Self::WALLIS_AND_FUTUNA,
                Self::YEMEN,
                Self::ZAMBIA,
                Self::ZANZIBAR,
                Self::ZIMBABWE,
            ])
        })
    }

    /// PHP `CallingCode::fromPhoneNumber`.
    #[must_use]
    pub fn from_phone_number(number: &str) -> Option<String> {
        let stripped: String = number
            .chars()
            .filter(|c| !matches!(c, '+' | ' ' | '(' | ')' | '-'))
            .collect();
        let digits = stripped
            .strip_prefix("00")
            .or_else(|| stripped.strip_prefix("011"))
            .unwrap_or(stripped.as_str());
        for length in [3, 2, 1] {
            if digits.len() >= length {
                let code = &digits[..length];
                if Self::codes().contains(code) {
                    return Some(code.to_string());
                }
            }
        }
        None
    }
}
