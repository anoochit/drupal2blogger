drupal2blogger
==============

A PHP script for content export of your Drupal 7 website to blogger.

What this script can do for you:

  - Export (HTML) content off all pages, stories, and comments.
  - Maintain author information and post meta data (such as dates).
  - Maintain reply-to-reply relationships.

What this script cannot do:

  - Data export (images, PDFs,...).
  - Link replacements (all site links will probably be broken).
  - Export any Drupal structures that are not available in Blogger
    (e.g., forums).

This script is based on drupal_to_blogger, a Drupal-6-to-Blogger exporter
by Christophe Vandeplas.


USAGE INSTRUCTIONS

(a) Download drupal2blogger to the server that hosts the Drupal database,
    and make sure it's accessible.
(b) Modify the contents of my_data.php to match your database and Blogger
    layout.
(c) Execute it and download the XML file. It should contain the content
    information of your Drupal 7 website.
(d) Log in to Blogger, and export your blog. The export link can be found
    at Setting->Other->Export blog. (2012/08/15)
    Optionally pretty-print the Blogger export file, e.g.,
      xml_pp blog-08-15-2012.xml > blogger.xml
(e) Insert the contents of the Drupal 7 XML (from (c)) right before the
    terminal </feed> in blogger.xml.
(f) Import blogger.xml into Blogger.
