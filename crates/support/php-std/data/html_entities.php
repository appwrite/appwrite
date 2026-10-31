<?php

// htmlentities()' tables per doctype, as JSON on stdout: for each doctype,
// `all` (HTML_ENTITIES) and `basic` (HTML_SPECIALCHARS) rows of
// [[code points...], "entity name"]. Input of html_entities.py.
//
//     docker run --rm -i --entrypoint php appwrite-dev < html_entities.php > entities.json

$out = [];
foreach (['html401' => ENT_HTML401, 'xhtml' => ENT_XHTML, 'html5' => ENT_HTML5, 'xml1' => ENT_XML1] as $name => $doctype) {
    foreach ([HTML_ENTITIES, HTML_SPECIALCHARS] as $table) {
        $rows = [];
        foreach (get_html_translation_table($table, ENT_QUOTES | $doctype, 'UTF-8') as $chars => $entity) {
            $rows[] = [array_map('mb_ord', mb_str_split((string) $chars)), substr($entity, 1, -1)];
        }
        $out[$name][$table === HTML_ENTITIES ? 'all' : 'basic'] = $rows;
    }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
