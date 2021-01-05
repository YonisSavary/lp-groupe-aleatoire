<?php

namespace Controllers;

use Kernel\Controller;
use Kernel\httpResponse;
use Kernel\Renderer;

class GroupController extends Controller
{
    public function index(){
        $this->load_template("groups\\form", []);
        $this->display_page();
    }

    private function throw_error($message){
        $r = new Renderer("errors\\error_generic", [
            "subtitle"=>"Form Error",
            "message"=>$message
        ]);
    }

    public function create(){
        if (!isset($this->POST["gsize"])) $this->throw_error("gsize key is missing !");
        if (!isset($this->POST["gdata"])) $this->throw_error("gdata key is missing !");
        if ($this->POST["gsize"] == "") $this->throw_error("invalid gsize !") ;
        $lines = explode("\n", $this->POST["gdata"]);
        $subjects = [];
        $fields = explode(",", str_replace("\r", "", $lines[0]));
        unset($lines[0]);

        foreach($lines as $line){
            if (!preg_match("/,/", $line)) continue;
            $props = explode(",", $line);
            $new_subject = [];

            for ($i=0; $i<count($fields); $i++){
                $f = $fields[$i];
                $new_subject[$f] = $props[$i];
            }

            array_push($subjects, $new_subject);
        }

        $to_reach = $this->POST["gsize"];
        $final_groups = [];
        $count = 0;
        $new_group = [];
        if ($to_reach > count($subjects)) return ;
        while (count($subjects)>0){
            if (count($new_group) < $to_reach){
                if (count($subjects) < $to_reach)
                {
                    array_push($final_groups, $new_group);
                    break;
                }
                $to_push = rand(0, count($subjects)-1);
                array_push($new_group, $subjects[$to_push]);

                unset($subjects[$to_push]);
                // Reset indexes
                $subjects = array_values($subjects);

            } else {
                array_push($final_groups, $new_group);
                $new_group = [];
            }
        }
        new Renderer("groups\\results", [
            "fields"=>$fields,
            "groups"=>$final_groups
        ]);
    }
}