<?php

const SERVER_CONF = "..\\server.json";
const DEFAULT_CONFIG = [
    "ERROR_DIR" => "errors",
    "TEMPLATE_DIR" => "..\\templates",
    "INCLUDES_DIR" => ["includes"],
    "STATIC_URL" => "assets",
];

if (file_exists(SERVER_CONF)){
    $_SERVER["CONFIG"] = (array) json_decode(file_get_contents(SERVER_CONF));
} 

foreach(DEFAULT_CONFIG as $key => $value){
    if (!isset($_SERVER["CONFIG"][$key])){
        $_SERVER["CONFIG"][$key] = $value;
        eval("define($key, \$value);");
    }
}

function CONSTS($key){
    return $_SERVER["CONFIG"][$key];
}