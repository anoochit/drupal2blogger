<?php
/**
 * Drupal to Blogger
 * Script to export your drupal_database to an XML format that is importable in Blogger.
 * Copyleft Christophe Vandeplas <christophe@vandeplas.com>
 *
 * This php script does the export while keeping:
 *   posts
 *   comments
 *   publishing date
 * However there are a few quirks:
 *   Comments are (partially) anonymized because of a security feature of Blogger
 *   URLs are not customizable, so you will create dead links
 *   Images are not changed or imported. So manual work is still necessary
 *
 * INSTRUCTIONS
 ***************
 * To use this script first create your blog into Blogger, create a test posts
 * and export it to XML. Then run this php script and copy paste the output towards
 * the bottom of the XML, where your test post is located.
 * Save the file and import it again in Blogger. It usually takes some time,
 * but in the end you get the message that everything is imported correctly.
 */

include 'my_data.php';

/////////////////////////////////////////////////
// you should probably NOT change anything below
//

// We'll be outputting a xml
header('Content-type: text/xml');
header('Content-Disposition: attachment; filename="drupal_to_blogger_export.xml"');

$sql = "SELECT * FROM ".$db_prefix."node as n JOIN ".$db_prefix."field_data_body as fdb ON n.nid=fdb.entity_id";

mysql_connect("localhost", $user, $pass) or die(mysql_error());
mysql_select_db($db) or die(mysql_error());

// Nodes
$result_node = mysql_query($sql) or die (mysql_error());
$numrows_node=mysql_numrows($result_node);
$i_node=0;

// Loop over the nodes.
while ($i_node < $numrows_node) {
  $type= htmlspecialchars(mysql_result($result_node,$i_node,"type"));
  // Translate the type into Blogger lingo.
  switch ($type) {
    case "page":
      $blogger_type = "page";
      break;
    case "story":
      $blogger_type = "post";
      break;
    default:
      // Skip.
      continue;
  }

  $nid = mysql_result($result_node, $i_node, "nid");
  $title= htmlspecialchars(mysql_result($result_node,$i_node,"title"));
  $created= date("c", mysql_result($result_node,$i_node,"created"));
  $updated= date("c", mysql_result($result_node,$i_node,"changed"));
  $body= htmlspecialchars(mysql_result($result_node,$i_node,"body_value"));

  $sql_cc = "SELECT * FROM ".$db_prefix."node_comment_statistics WHERE nid = $nid";
  $result_cc = mysql_query($sql_cc) or die (mysql_error());
  $comments = mysql_result($result_cc, 0, "comment_count");

  print "
  <entry>
    <id>tag:drupal,post-$nid</id>
    <published>$created</published>
    <updated>$updated</updated>
    <category scheme='http://schemas.google.com/g/2005#kind' term='http://schemas.google.com/blogger/2008/kind#$blogger_type'/>";

  // Handle taxonomy tags for posts.
  if ($blogger_type == "post") {
    $sql_cat = "SELECT * from ".$db_prefix."taxonomy_index as ti WHERE nid = $nid";
    $result_cat = mysql_query($sql_cat) or die (mysql_error());
    $numrows_cat = mysql_numrows($result_cat);
    $i_cat=0;
    while ($i_cat < $numrows_cat) {
      $tid=mysql_result($result_cat,$i_cat,"tid");
      $sql_cat2 = "SELECT * from ".$db_prefix."taxonomy_term_data as ttd WHERE tid = $tid";
      $result_cat2 = mysql_query($sql_cat2) or die (mysql_error());
      $cat_name=mysql_result($result_cat2,0,"name");
      print "<category scheme='http://www.blogger.com/atom/ns#' term='$cat_name'/>";
      $i_cat++;
    }
  }
  print "
    <title type='text'>$title</title>
    <content type='html'>$body</content>
    $global_author_tag
    <thr:total>$comments</thr:total>
  </entry>";

  $i_node++;
}

// Comments
$sql_c = "SELECT * FROM ".$db_prefix."comment as c JOIN ".$db_prefix."field_data_comment_body as fdcb ON c.cid=fdcb.entity_id";
$result_c = mysql_query($sql_c) or die (mysql_error());
$numrows_c=mysql_numrows($result_c);
$i_c=0;
while ($i_c < $numrows_c) {
  $nid = mysql_result($result_c, $i_c, "nid");
  $cid = mysql_result($result_c, $i_c, "cid");
  $created = date("c", mysql_result($result_c,$i_c,"created"));
  $changed = date("c", mysql_result($result_c,$i_c,"changed"));
  $title = htmlspecialchars(mysql_result($result_c, $i_c, "subject"));
  $body = htmlspecialchars(mysql_result($result_c, $i_c, "comment_body_value"));
  $author = mysql_result($result_c, $i_c, "name");
  $email = mysql_result($result_c, $i_c, "mail");
  $url = mysql_result($result_c, $i_c, "homepage");

  print "<entry>
    <id>tag:drupal,comment-$cid</id>
    <published>$created</published>
    <updated>$changed</updated>
    <category scheme='http://schemas.google.com/g/2005#kind' term='http://schemas.google.com/blogger/2008/kind#comment'/>
    <title type='text'>$title</title>
    <content type='html'>$body</content>
    <author><name>$author</name>
      <uri>$url</uri>
      <email>a@a.com</email>
    </author>
    <thr:in-reply-to ref='tag:drupal,post-$nid' type='text/html'/>
  </entry>
  ";

  $i_c++;
}

echo "\n";
