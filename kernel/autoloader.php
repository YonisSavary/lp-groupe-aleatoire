<?php

const DIRECTORIES = ["kernel", "controllers", "models"];

function loadClasses(){
    foreach (DIRECTORIES as $dir){
        // For each files in each DIRECTORIES
        foreach(scandir("..\\$dir") as $file){
            if (preg_match("/\.+$/", $file)) continue;          // Ignore files beginning by '.' 
            if (strcmp($file,"autoloader.php") === 0) continue; // Ignore itself and other autoloaders
            if (preg_match("/\.php$/", $file)){                 // Only require files with names ending by .php
                require_once "..\\$dir\\$file";
            } 
        }
    }
}
spl_autoload_register("loadClasses");
