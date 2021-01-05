<?php

namespace Kernel;

/**
 * This class is OPTIONAL
 * You can define a controller without having to inherit from 'Controller'
 * 
 * 'Controller' help you to have a simpler control over
 * your global data and Renderer process
 */
class Controller
{
    public function __construct()
    {
        $this->GET = $_GET;
        $this->POST = $_POST;
        $this->METHOD = $_SERVER['REQUEST_METHOD'];
        $this->PATH = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
        $this->page_content = "";
        $this->renderer = null;
    }

    public function load_template(string $template_name, array $data)
    {
        $this->renderer = new Renderer($template_name, $data, false, false);
    }

    public function display_page()
    {
        $this->page_content = $this->renderer->finalize();
    }
}