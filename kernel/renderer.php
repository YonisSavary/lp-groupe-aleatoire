<?php

/**
 * Class Renderer : Reminder
 * 
 * How do the Renderer class parse a template ? -----------------------------------------------------------------------------------
 * 
 * Renderer divides block into layers; a layer is an string array that contain 
 * raw lines contained into a block, each times Renderer 
 * read a "complex" line (like 'block' or 'for'), it put 
 * each lines of this block into a layer without interpreting them,
 * but interpret the block content when the block is closing, this
 * feature is called "safe-buffering", example
 * 
 * DATA = ["subtitle" => "Welcome !"]
 * 
 * LAYER 'main' ------------------------+
 * {% block content %}                  | LAYER 'block:content' --------+
 *      <h1> Title </h1>                | <h1> Title </h1>              |
 *      <h2> {{ subtitle }} </h2>       | <h2> {{ subtitle }} </h2>     | This line will be interpreted now
 * {% endblock %}                       |                               |
 *  
 * This layer and safe-buffering is needed to allow devs to put loops inside another loops
 * 
 * How do the Renderer class do while going through a file ? -----------------------------------------------------------------------
 * 
 * the private function processTemplate(&$layer) go through each lines of the layer
 * and everytimes a line match a regex in BLOCK_REGEX, it call the regex callback, but
 * when the Renderer is in safe-buffering mode, it store the lines into a special layers 
 * 
 * 
 * How do the safe-buffering feature work ? ----------------------------------------------------------------------------------------
 * 
 * the safe-buffering mode is initialized by 
 * calling start_safe_buffering(string $upstairs, string $downstairs, string $callback="", string $safe_name="safe")
 * 
 * $upstairs and $downstairs are the block type delimiter, and callback is the class method that 
 * is called when the block is closing, 
 * the safe-buffering content is stored in a layer named after $safe_name 
 * 
 * (see the start_safe_buffering comment for more informations)
 * 
 * 
 */

namespace Kernel;

use Exception;
use Kernel\httpResponse;

class Renderer{
    /**
     * BLOCK_REGEX define the behavior of the 
     * Renderer class, the format is
     * 
     * <REGEX> => <CALLBACK>
     * 
     * <REGEX> is a regular expression 
     * <CALLBACK> is the class methods that is called when <REGEX>
     */
    const BLOCK_REGEX = [
        "/\{% ?extends .+ ?%\}/"=>"do_extends",
        "/\{% ?include .+ ?%\}/"=>"do_include",
        // Blocks feature
        "/\{% ?block .+ ?%\}/"=>"begin_block",
        "/\{% ?for .+ ?%\}/"=>"begin_for",
        "/\{% ?if .+ ?%}/"=>"begin_if",

        "/\{% static .+ %\}/"=>"get_static",
        "/\{% url .+ %\}/"=>"get_url",
        // Interpreter
        "/\{\{ ?.+ ?\}\}/"=>"interpret"
    ];

    //TODO
    // {% url <url> %}
    // {% static <file> %}
    
    ///////////////////////////// MAIN PROCESS /////////////////////////////
    /**
     * $template_name   is the name of the template inside "templates/"
     * $data            are the data used to interpret variables
     * $direct_flush    display the results if set to true
     * $do_debug        will print detailled debug when set to true
     */
    public function __construct(string $template_name="", array $data=[], bool $direct_flush=true, bool $do_debug=false){
        $this->layers = [];     // List of layers
        $this->data = $data;

        // An example for the safe-buffering is available 
        // just before the start_safe_buffering() method
        $this->safe_name = "safe";      // Name of the layer for safe-buffering
        $this->safe_buffering = false;  // Is this object in safe_buffering mode ?
        $this->safe_upstairs = "";      // Regex matching a opening block
        $this->safe_downstairs = "";    // Regex matching a ending block 
        $this->safe_depth = 0;          // How many ending block must be read before the original one is closed
        $this->safe_callback = "";      // Method name

        // References to external sources
        // Variable created by kernel/router.php
        $this->routes = &$_SERVER["CONFIG"]["ROUTES"];


        $this->direct_flush = $direct_flush;
        $this->do_debug = $do_debug;

        if ($template_name=="") return ;
        $this->load($template_name, $direct_flush);
    }


    /**
     * This function is the enty point of the class, it is called by the constructor
     * by default, but it can be also called externaly 
     */
    public function load(string $template_name, bool $direct_flush=true){
        $path = $this->get_path($template_name);
        // Read template or generate an error
        if (!file_exists($path)) $this->fatal_error("Template \"$template_name\" does not exists ");
        $this->layers["main"] = $this->file_to_layer($path);

        $this->process_layer($this->layers["main"]);
        $this->clean_layer("main");

        foreach ($this->layers as &$layer){
            $this->process_layer($layer);
        }

        if ($direct_flush) { $this->finalize();}
    }


    /**
     * The function to call to display
     * the class Results, it compile every
     * content remaining in the layers 
     * and display it
     */
    public function finalize(bool $return=false){
        $page_content = "";
        foreach($this->layers as $layer){
            $page_content .= join("\n", $layer);
        }
        if ($return === true) return $page_content;
        httpResponse::html($page_content);
    }
    
    

    ////////////////////////////////////////////////////////////////////////////////////
    /////////////////////////////     LAYERS  FUNCTION     /////////////////////////////
    ////////////////////////////////////////////////////////////////////////////////////

    /**
     * The safe-buffering behavior is described in the file begining,
     * but here is an example of use: 
     * 
     * Note : up/downstairs must be regular expression (begining and ending by "/")
     * 
     *  $this->start_safe_buffering("/\{% for .+ in .+ %\}/", "/\{% endfor %\}/", "end_for", "for0");
     * 
     * this call will define this behavior :
     * 
     * upstairs and downstairs help us to know the block depth
     * and to store block of the same types :
     * 
     * {% for a in b %}         | safe_depth = 1
     *      {% for c in a %}    | safe_depth = 2
     *      {% endfor %}        | safe_depth = 1
     * {% endfor %}             | safe_depth = 0
     * 
     * when safe_depth reach 0, the for content will be interpreted with process_layer function
     * The layer named 'for0' will now have every lines between the actual line and the matching "/\{% endfor %\}/"
     * 
     */
    private function start_safe_buffering(string $upstairs, string $downstairs, string $callback="", string $safe_name="safe"){
        $this->add_layer($safe_name, [], "");
        $this->safe_name = $safe_name;
        $this->safe_buffering = true;
        $this->safe_upstairs = $upstairs;
        $this->safe_downstairs = $downstairs;
        $this->safe_depth = 1;
        $this->safe_callback = $callback;
    }


    /**
     * Simply return the safe-buffering layer and delete it
     */
    private function pull_safe(){
        $content = $this->layers[$this->safe_name];
        unset($this->layers[$this->safe_name]);
        return $content;
    }


    /**
     * Routines called for each lines when the Renderer is in
     * safe-buffering mode
     */
    private function update_safe_buffering(&$line){
        if (preg_match($this->safe_upstairs, $line))    { $this->safe_depth++; }
        if (preg_match($this->safe_downstairs, $line))  { $this->safe_depth--; }
        
        $safe_layer = &$this->layers[$this->safe_name];

        // The end block is reached ! 
        if ($this->safe_depth === 0) {
            $this->safe_buffering = false; 
            $this->debug("Ended safe buffering | ". count($safe_layer). " lines buffered");
            $callback = $this->safe_callback;
            if (method_exists($this, $callback)) $this->$callback($line);
        } else {
            // The line is stored and not interpreted
            array_push($safe_layer, $line);
            $line = "";
        }
    }


    /**
     * Add a layer to $this->layer :
     * $name        if the layer name (crazy right ?!)
     * $content     can be set to define an initial content for the layer
     * $prefix      can be set to help a person debugging, (example, name="1", prefix="loop", so the final name is ="loop.1")
     * $at_start    can be set to true to insert the new layer at the start of the layers list
     *              (pretty useful for the 'extends' feature)
     */
    private function add_layer(string $name, array $content=[], string $prefix="", bool $at_start=false){
        if ($prefix !== "" ) $name = $prefix.".".$name;
        if ($at_start){
            $this->layers = array_merge([$name=>$content], $this->layers);
        }
        else{
            $this->layers[$name] = $content;
        }
        $this->debug("new layer: '$name'");
        return $name;
    }

    /**
     * Remove a layer by its name
     */
    private function remove_layer(string $name){
        $this->debug("removing layer '$name'");
        unset($this->layers[$name]);
    }


    /**
     * Return the last layer name 
     * (Not used yet but can be useful)
     */
    private function get_last_layer(int $offset=0){
        $keys = array_keys($this->layers);
        return $keys[count($keys)-($offset+1)];
    }


    /*
     * Remove the empty lines in a layer
     * Also it delete the layer if there is only empty lines
     */
    private function clean_layer(string $name){
        $layer = $this->layers[$name];
        $new_content = [];
        for ($i=0; $i<count($layer); $i++){
            $line = $layer[$i];
            if (strlen(preg_replace("/[\r\n ]/","", $line)) === 0) continue;
            array_push($new_content, $line);
        }
        if (count($new_content) === 0){
            $this->debug("threw $name layer into the void (empty layer)");
            $this->remove_layer($name);
        }
        else{
            $this->debug("$name layer cleaned | ". count($new_content). " lines remainings ");
            $this->layers[$name] = $new_content;
        }
    }


    /**
     * Core of the Renderer class,
     * is go through the given layer
     * and call either the update_safe_buffering
     * or the matching callback when interpreting
     */
    private function process_layer(array &$layer){
        $to_reach = count($layer);
        for ($i=0; $i<$to_reach; $i++){
            $line = &$layer[$i];
            if ($this->safe_buffering === true){
                $this->update_safe_buffering($line);
            } else {
                foreach(Renderer::BLOCK_REGEX as $regex => $func){
                    if (preg_match($regex, $line) && method_exists($this, $func)){
                        $this->$func($line);
                    }
                }
            }
        }
    }



    ////////////////////////////////////////////////////////////////////////////////////
    ///////////////////////////// MAIN FEATURES PROCESSING /////////////////////////////
    ////////////////////////////////////////////////////////////////////////////////////

    /**
     * function called for the block {% extends <name> %}
     */
    private function do_extends(string &$line){
        $to_extend_from = $this->dismantle($line, "extends");
        $path = $this->get_path($to_extend_from);
        if (!file_exists($path)) $this->fatal_error("Can't extends from \"$to_extend_from\" !");
        $content = $this->file_to_layer($path);
        $this->add_layer($to_extend_from, $content, "extends", true);
        $line = "";
    }


    /**
     * function called for the block {% include <name> %}
     */
    private function do_include(string &$line){
        $to_extend_from = $this->dismantle($line, "include");
        $regex = "/\{% ?include $to_extend_from ?%\}/";

        // Reading File for content
        $path = $this->get_path($to_extend_from, CONSTS("INCLUDES_DIR"));
        if (!file_exists($path)) {
            $line = preg_replace($regex, "Can't include \"$to_extend_from\" !", $line);
            return ;
        }
        $file_content = $this->file_to_layer($path);

        // Process the included part
        $include_layer_name = $this->add_layer($to_extend_from, $file_content, "include");
        $include_layer = &$this->layers[$include_layer_name];
        $this->process_layer($include_layer);
        $content = join("", $include_layer);
        $this->remove_layer($include_layer_name);

        // Merge the include content into the processed layer
        $line = preg_replace($regex, $content, $line);
        $this->debug("included $to_extend_from part");
    }


    /**
     * function called for the block {% block <name> %}
     */
    private function begin_block(&$line){
        $block_name = $this->dismantle($line, "block");

        // line = content
        $this->debug("Starting safe buffering for block \"". $block_name."\"");
        $this->start_safe_buffering("/\{% block .+ %\}/", "/\{% endblock %\}/", "end_block", $block_name);
        $line = "";
    }
    private function end_block(&$line){
        $line = "";
        $buffered = $this->pull_safe();
        $block_regex_beg = "/\{% ?block ".$this->safe_name." ?%\}/";
        $block_regex_end = "/\{% endblock %\}/";
        $block_regex_all = "/\{% ?block ".$this->safe_name." ?%\}(.|\n){0,}\{% ?endblock ?%\}/";
        $this->process_layer($buffered);

        // If there is only one layers remaining, we don't change the
        // content
        if (count($this->layers)===1){
            $line = join("", $buffered);
            return ;
        }

        foreach ($this->layers as $name=>&$content){
            if (preg_match($block_regex_all, join("\n", $content))){
                $ignoring = false;
                foreach ($content as &$layer_line){
                    if (preg_match($block_regex_beg, $layer_line)) $ignoring = true;
                    if (preg_match($block_regex_end, $layer_line)) {
                        $layer_line = join("\n", $buffered);
                        break;
                        return ;
                    }
                    if ($ignoring === true) $layer_line = "";
                }
                $this->debug("Inserting ".$this->safe_name. " into $name");
            }
        }
        $this->debug("No slot found for block ".$this->safe_name);
    }



    /**
     * function called for the block {% for <var> in <arr> %}
     */
    private function begin_for(&$line){
        $for_params = join(":", explode(" in ", $this->dismantle($line, "for")));
        $this->debug("Starting safe buffering for loop \"". $for_params ."\"");
        $this->start_safe_buffering("/\{% for .+ in .+ %\}/", "/\{% endfor %\}/", "end_for", $for_params);
        $line = "";
    }
    private function end_for(&$line){
        $buffered = $this->pull_safe();
        $for_params = $this->safe_name;

        $for_var = explode(":", $for_params)[0];
        $for_arr = explode(":", $for_params)[1];
        $for_reg = "/  ".$for_var."/";

        $buffered = preg_replace($for_reg, " ".$for_arr, $buffered);
        
        if (strpos($for_arr,".") > 0){
            eval("\$d_arr = &\$this->data[\"".join("\"][\"", explode(".",$for_arr))."\"];");
        }
        else{
            $d_arr = &$this->data[$for_arr]; // d_arr => data_array
            if (!isset($this->data[$for_arr])) $this->fatal_error("$for_arr array does not exists !");
        }

        $new_content = [];
        $regex = "/$for_var/";
        $regex_attribute = "/{{ .+\.$for_var.+/";
        // For each items
        for ($i=0; $i<count($d_arr); $i++) {
            $replace_to = $for_arr.".".$i;

            $layer_name = $this->add_layer("$for_var:$for_arr.$i", $buffered, "loop");
            $item_layer = &$this->layers[$layer_name];
            foreach($item_layer as &$l_line){
                if (preg_match("/\{ ?.+ ?\}/", $l_line)){
                    if (preg_match($regex_attribute, $l_line)){
                        $const = "{{ $replace_to }}";
                        $this->interpret($const);
                        $r = "/\.$for_var/";
                        $l_line = preg_replace($r, ".".$const, $l_line);
                    } else {
                        $this->debug("heeeeere ".htmlspecialchars($l_line));
                        $l_line = preg_replace($regex, $replace_to, $l_line);
                    }
                    $l_line = preg_replace("/\{\{ index \}\}/", $i+1, $l_line);
                }
            }
                    

            $this->process_layer($item_layer);
            $new_content = array_merge($new_content, $item_layer);
            $this->remove_layer($layer_name);
        }
        $line = "";
        $line = join("", $new_content);
    }



    /**
     * function called for the block {% if <cond> %}
     */
    private function begin_if(&$line){
        $condition_name = $this->dismantle($line, "if");
        $this->debug("Starting safe buffering for block \"". $condition_name."\"");
        $this->start_safe_buffering("/\{% ?if .+ ?%\}/", "/\{% ?endif ?%\}/", "end_if", $condition_name);
        $line = "";
    }
    private function end_if(&$line){
        foreach ($this->data as $key=>$value){
            $to_eval = "\$".$key ." = \$this->data[\$key];";
            eval($to_eval);
        }
        // Layer contained in the condtion
        $buffered_cond = $this->pull_safe();
        // Full condition
        $condition = $this->safe_name;
        
        /*
         * Condition processing 
         * Example : 
         * cond = 'var == "value"'
         * 
         * condition_elements = [
         *  'var',      <= get expression to evaluate
         *  '==',       <= conditionnal element, ignored
         *  '"value"'   <= contain quote, so ignored
         * ]
         */

        $condition_elements = explode(" ", $condition);
        $inside_quote = false;
        foreach ($condition_elements as &$elem){
            if (strpos($elem, "\"") !== false){
                if (substr_count($elem, "\"") % 2 === 0) continue;
                $inside_quote = !$inside_quote;
                continue;
            } 
            if (is_numeric($elem)) continue ;
            if (preg_match("/[<>!=\|\&]{1,}/",$elem)) continue;
            if ($inside_quote === true) continue;
            $elem = "{{ $elem }}";
            $this->debug($elem);
            $elem = $this->interpret($elem, true);
        }

        $cond = join(" ", $condition_elements);
        // Remaining part of the condition

        $condition_results = "";
        $to_eval = "try { \$condition_results = (".$cond."); } catch (Exception \$e){ \$condition_results = false; }";

        try {
            $res = eval($to_eval);

            if ($condition_results === true){
                $ln = $this->add_layer($condition, $buffered_cond, "if");
                $this->process_layer($this->layers[$ln]);
                $line = join("", $this->layers[$ln]);
                $this->remove_layer($ln);
            } else {
                $line = "";
            }

        } catch (Exception $e) {
            $line = "Error parsing condition \"$cond\"";
        }        
    }



    /**
     * function called for the block {% static <file_path> %}
     */
    private function get_static(&$line){
        $param = $this->dismantle($line, "static");
        $static_regex = "/\{% ?static ".str_replace("/", "\/", addslashes($param))." ?%\}/";

        $param = str_replace("\\", "/", $param);
        $param = preg_replace("/[\"\']/", "", $param);

        $line = preg_replace($static_regex, CONSTS("STATIC_URL")."/$param", $line);
        $this->debug("getting static for $param | got: ".htmlspecialchars($line)); 
    }



    /**
     * function called for the block {% url '<route_name>' %}
     */
    private function get_url(&$line){
        $param = $this->dismantle($line, "url");
        $static_regex = "/\{% ?url ".str_replace("/", "\/", addslashes($param))." ?%\}/";

        $param = preg_replace("/[\"\']/", "", $param);

        $found = false;
        foreach($this->routes as $path=>$route_param){
            if (!isset($route_param["name"])) continue;
            if ($route_param["name"] === $param){
                $found = true;
                $line = preg_replace($static_regex, $path, $line);
            }
        }
        // If '$param' route isn't found in the configuration
        // it will replace the instruction by "/$param"
        if (!$found) $line = preg_replace($static_regex, $param, $line);
        $this->debug("getting url for $param | got: ".htmlspecialchars($line));
    }


    ///////////////////////////// DATA PROCESSING /////////////////////////////
    
    /**
     * function called for the block {{ <varname> = <default_value> }}
     */
    private function interpret(string &$line, bool $return_expression=false){
        $to_interpret = $this->dismantle($line, "", "\{\{", "\}\}");
        $line_regex = "/\{\{ ?$to_interpret ?\}\}/";
        $subkeys = [];
        $final = null;
        $default = null;

        // DEFAULT VALUE FEATURE
        $deli = "=";
        if (strpos($to_interpret,$deli)){
            $default = preg_replace("/(.{0,}$deli ?|\")/", "", $to_interpret);
            $to_interpret = preg_replace("/ ?$deli.{0,}/","", $to_interpret);
        }

        $to_eval = "\$this->data[\"$to_interpret\"]";

        // SUBKEYS FEATURES
        if (strpos($to_interpret, ".")){
            $subkeys = explode(".", $to_interpret);
            $to_interpret = explode(".", $to_interpret)[0];
            unset($subkeys[0]);
            $subkeys = "[\"".join("\"][\"", $subkeys)."\"]";
            $to_eval = "\$this->data[\"$to_interpret\"]".$subkeys;
        }

        if ($return_expression === true ) return $to_eval;
        //$this->debug("will evaluate : $to_eval");
        set_error_handler(function() {});

        eval("try { \$final = ".$to_eval."; } catch (Exception \$e){ }");
        restore_error_handler();
        
        if ($final===null){
            $final = "Unknown key"; 
            if ($default !== null) $final = $default;
        }
        if (is_array($final)) $final = print_r($final, true);
        $line = preg_replace($line_regex, $final, $line);
        $this->debug("has to interpret : ".$to_eval . " | found : $final");
    }


    /**
     * This function is extremly important for EVERY block we encounter, 
     * this function return the content of a block instruction, example
     * 
     * $line        is the content to extract from
     * $prefix      is the instruction type/name
     * $begin/$end  are the delimiters for the block
     * 
     * here is some examples:
     * dismantle("{% block <blockname> %}", "block")        ====> return "<blockname>"
     * dismantle("{{ <varname> }}", "", "\{\{", "\}\})      ====> return "<varname>"
     * 
     */
    private function dismantle($line, $prefix, $begin="\{%", $end="%\}"){
        $regex = "/(.{0,}$begin ?$prefix ?| ?$end.{0,})/";
        return preg_replace($regex, "", $line);
    }

    ///////////////////////////// FILE PROCESSING /////////////////////////////


    /**
     * Given a $path to a file,
     * it return an array containing every lines of the file
     */
    private function file_to_layer(string $path){
        return explode("\n", str_replace("\r", "", file_get_contents($path)));
    }


    /**
     * Get path of a template
     * you can defines subdirectories to the template folder
     */
    public static function get_path(string $template_name, array $sub_directories=[]) { 
        $path = $_SERVER["DOCUMENT_ROOT"]."\\".CONSTS("TEMPLATE_DIR")."\\";
        if (count($sub_directories)>0) $path.= join("\\",$sub_directories)."\\";
        $path.= $template_name.".html";
        return $path;
    }


    /**
     * Pretty useful this one.
     * Debug will display a $message, 
     * and a print_r of the layers data,
     * 
     * so this function allow you to display a message 
     * and the var_dump of the layers, and to check 
     * the layers states at differents times
     */
    private function debug($message){
        if ($this->do_debug === false) return ;
        echo "
        <details>
            <summary>".$this->safe_depth.str_repeat("----", count($this->layers)-1)." => ".$message."</summary>
            <pre>".htmlspecialchars(print_r($this->layers, true))."</pre>
        </details>";
    }

    /**
     * display an error message and finish the process
     */
    private function fatal_error(string $message){
        echo $message."<br>";
        die();
    }
}