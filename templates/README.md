# Informations for dev using Nelumbo for the first time
Everything in this directory is used for the default Nelumbo pages

What's important is that :
 * **you can delete everything in this folder**
 * **BUT, keeping 'errors' and 'includes' directories**
   **(even empty) is advised, to keep a little of organization**
 * You can redefine INCLUDE_DIR and TEMPLATES_DIR in the server
   config ( INCLUDE_DIR is used when importing part with {% include 'part' %})
 * TEMPLATE_DIR is pretty explicit, it is used when telling a Renderer to render something