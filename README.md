# Nelumbo
Light PHP MVC Framework

## Actual Features

 * Routing
   * Method-only routes

 * Template rendering
   * if blocks
   * for-loops
   * include
   * extends
   * variables interpreter 
     (+ default value syntax)

 * Server Config File

 ## How do I debug existing routes

 By default, there's a route "nelumbo-debug", giving you
 every loaded classes and every routes that router.php found
 (You can disable it by setting `DEBUG_ROUTE_ENABLED` to false in
 router.php)