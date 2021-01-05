<?php

namespace Kernel;

/**
 * This class has two static methods so far :
 * 
 * httpResponse::html : 
 * given any object it will print the content into the page
 * 
 * httpResponse::json :
 * given an object, it will send the json version of the object
 * (and its include the json header, meaning that its json for the browser)
 * 
 * both of the methods call die(), so call theses methods as a final action 
 */
class httpResponse
{
    public static function html($pageContent){
        header("Content-Type : text/html");
        if (is_array($pageContent)===true){
            $pageContent = join("\n", $pageContent);
        } else if (is_object($pageContent)){
            $pageContent = print_r($pageContent, true);
        }
        echo $pageContent;
        die();
    }

    public static function json($pageContent){
        header('Content-Type: application/json');
        echo json_encode($pageContent);
        die();
    }
}