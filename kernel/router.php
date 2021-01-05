<?php

namespace Kernel;
use Kernel\httpResponse;

class Router
{
    const DEBUG_ROUTE_ENABLED = false ;
    const DEBUG_ROUTE_PATH = "/nelumbo-debug";

    public function print_debug()
    {
        $classes = get_declared_classes();
        $classes = array_filter($classes, function($elem){
            return preg_match("/.+\\\\.+/",$elem);
        });
        httpResponse::json([
            "namespaces_classes"=> $classes,
            "routes"=> $_SERVER["CONFIG"]["ROUTES"]
        ]);
    }

    /**
     * Generate an error page or json
     * basing on error\\error_generic.html template
     */
    public function error_page(string $code, string $subtitle, string $message, bool $json=false)    {
        if ($json === true)
        {
            httpResponse::json([
                "status"=>"error",
                "code"=>$code,
                "subtitle"=>$subtitle,
                "message"=>$message,
            ]);
        }
        else
        {
            new Renderer(CONSTS("ERROR_DIR")."\\error_generic", [
                "code"=>$code,
                "subtitle"=>$subtitle,
                "message"=>$message
            ]);
        }
    }
    /**
     * Routes syntax
     * 
     * {
     *  "<routePath>" : "<controller::method>"[,
     *  "methods" : [ <array of methods names> ]][,
     *  "name" : "<routeName,useful for templating>"]
     * }
     */
    public function loadRoutes()
    {
        $path = $_SERVER["DOCUMENT_ROOT"] . "\\..\\routes.json";
        if (!file_exists($path)) return ;
        
        $_SERVER["CONFIG"]["ROUTES"] = (array) json_decode(file_get_contents($path));
        foreach($_SERVER["CONFIG"]["ROUTES"] as $path => $route)
        {
            $route = $_SERVER["CONFIG"]["ROUTES"][$path] = (array) $route;
            if (!isset($route["methods"]))
            {
                $_SERVER["CONFIG"]["ROUTES"][$path]["methods"] = ["GET"];
            }
        }
    }
    
    /***
     * Routine called for every request
     */
    public function routeRequest()
    {
        if (!isset($_SERVER["CONFIG"]["ROUTES"])){
            $this->loadRoutes();
        }

        $reqPath = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

        if (Router::DEBUG_ROUTE_ENABLED === true &&
            Router::DEBUG_ROUTE_PATH === $reqPath){
                $this->print_debug();
        }

        if (isset($_SERVER["CONFIG"]["ROUTES"][$reqPath]))
        {
            $route_object = &$_SERVER["CONFIG"]["ROUTES"][$reqPath];
            //
            //  FROM HERE : THE REQUEST ROUTE EXISTS
            //

            $method = $_SERVER['REQUEST_METHOD'];
            if (!in_array($method, $route_object["methods"])) {
                //
                //  FROM HERE : CLIENT TRY TO ACCESS WITH A FORBIDDEN METHOD
                //
                $this->error_page("403",
                "Forbidden Access", 
                "$reqPath cannot be accessed by $method methods", true);
            }

            $tar = $route_object["target"];

            if (!preg_match("/[A-Za-z0-9]::[A-Za-z0-9]/",$tar)) {
                $this->error_page("Unknown",
                "Bad Route Format", 
                "The Target $tar contain invalid characters");
            }

            $tar = explode("::", $tar);
            $controller_name = $tar[0];
            $controller_namespace = "Controllers\\".$controller_name;
            $controller_method = $tar[1];
            $controller = null;

            // Fatal error, bad controller name 
            if (!class_exists($controller_namespace)) {
                $this->error_page("Fatal Error",
                "Bad Route Name", 
                "$controller_namespace doesn't exists");
            }
            // Instantiate a new controller
            eval("use $controller_namespace; \$controller = new $controller_name();");

            // Fatal error, good controller | bad method name
            if (!method_exists($controller, $controller_method))  {
                $this->error_page("Fatal Error",
                "Bad Route Method", 
                "$controller_name->$controller_method() doesn't exists");
            }
            // Call the route target function
            eval("\$controller->$controller_method();");
        } 
        else
        {
            $path = CONSTS("TEMPLATE_DIR")."\\".CONSTS("ERROR_DIR")."\\error_generic.html";
            if (file_exists($path)){
                $r = new Renderer(CONSTS("ERROR_DIR")."\\error_generic", [
                    "code"=>"404",
                    "subtitle"=>"Page not found",
                    "message"=>"No route at ".$reqPath
                ]);
            } else {
                echo "No route named \"$reqPath\"";
            }
        }
    }
}
