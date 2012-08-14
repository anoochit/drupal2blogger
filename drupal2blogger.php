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

function my_hash($input, $num_bits){
  // Limit the size to 64bit; otherwise, decbin
  // has problems (always returns 0).
  assert('$num_bits <= 64');
  // Get MD5 hash (32 char hex = 128bit).
  $hash = md5($input);
  // Strip off the first half.
  $hash = substr($hash,0,16);
  $id_64bit = decbin(hexdec($hash));
  // Make sure the leading 0s aren't stripped.
  $id_64bit = str_pad($id_64bit, 64, '0', STR_PAD_LEFT);
  // Trim to desired size and convert to decimal.
  $id = bindec(substr($id_64bit,0,$num_bits));
  // Format to view all digits in integer.
  $id = number_format($id, 0, '', '');
  return $id;
}

function simplify_string($input){
  // First remove all special chars.
  $simple_title = preg_replace('/[^a-zA-Z0-9 ]/s', '', $input);
  // Now replace the white space by hyphens.
  $simple_title = preg_replace('/\s+/s', '-', $simple_title);
  // lowercase
  return strtolower($simple_title);
}

// We'll be outputting a xml
header('Content-type: text/xml');
header('Content-Disposition: attachment; filename="drupal_to_blogger_export.xml"');

$sql = "SELECT * FROM ".$db_prefix."node as n JOIN ".$db_prefix."field_data_body as fdb ON n.nid=fdb.entity_id";
mysql_connect("localhost", $user, $pass) or die(mysql_error());
mysql_select_db($db) or die(mysql_error());

// Nodes
$result_node = mysql_query($sql) or die (mysql_error());
$numrows_node = mysql_numrows($result_node);
// Loop over the nodes.
for ($i_node = 0; $i_node < $numrows_node; $i_node++) {
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
  $num_comments = mysql_result($result_cc, 0, "comment_count");

  // Blogger uses 19-digit integers (~63bits) as IDs, and we need to
  // recreate those later for the comments.
  // Hence, create a 63bit hash from the NodeID.
  $id = my_hash($nid, 63);

  print "
  <entry>
    <id>tag:drupal,blog-$blogger_id.$blogger_type-$id</id>
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
  // Generate simplified title for URL.
  $simple_title = simplify_string($title);

  // Create URL.
  $ym = date('Y/m',mysql_result($result_node,$i_node,"created"));
  $my_url = "http://$blog_url/$ym/$simple_title.html";
  // This would be for unpublished pages:
  //$my_url = "http://$blog_url/p/$simple_title.html";

  print "
    <title type='text'>$title</title>
    <content type='html'>$body</content>";
  if ($num_comments > 0){
    // Comment links.
    print "<link href=\"http://$blog_url/feeds/$blogger_id/comments/default\" rel=\"replies\" title=\"Post Comments\" type=\"application/atom+xml\"/>";
    print "<link href=\"$my_url#comment-form\" rel=\"replies\" title=\"$num_comments Comments\" type=\"text/html\"/>";
  }

  print "
  <link href=\"http://www.blogger.com/feeds/$blogger_id/pages/default/$id\" rel=\"edit\" type=\"application/atom+xml\"/>
  <link href=\"http://www.blogger.com/feeds/$blogger_id/pages/default/$id\" rel=\"self\" type=\"application/atom+xml\"/>
  <link href=\"$my_url\" rel=\"alternate\" title=\"$title\" type=\"text/html\"/>";
  print "
    $global_author_tag
  </entry>";
}

// Comments
$sql_c = "SELECT * FROM ".$db_prefix."comment as c JOIN ".$db_prefix."field_data_comment_body as fdcb ON c.cid=fdcb.entity_id";
$result_c = mysql_query($sql_c) or die (mysql_error());
$numrows_c=mysql_numrows($result_c);
for ($i_c = 0; $i_c < $numrows_c; $i_c++) {
  // Node ID.
  $nid = mysql_result($result_c, $i_c, "nid");
  $parent_id = my_hash($nid, 63);
  // Comment ID.
  $cid = mysql_result($result_c, $i_c, "cid");
  $comment_id = my_hash($cid, 63);
  // Meta data.
  $created = date("c", mysql_result($result_c,$i_c,"created"));
  $changed = date("c", mysql_result($result_c,$i_c,"changed"));
  $title = htmlspecialchars(mysql_result($result_c, $i_c, "subject"));
  $body = htmlspecialchars(mysql_result($result_c, $i_c, "comment_body_value"));
  $author = mysql_result($result_c, $i_c, "name");
  $email = mysql_result($result_c, $i_c, "mail");
  $url = mysql_result($result_c, $i_c, "homepage");

  // Get the parent node.
  $sql = "SELECT * FROM ".$db_prefix."node as n JOIN ".$db_prefix."field_data_body as fdb ON n.nid=fdb.entity_id WHERE n.nid = $nid";
  $result = mysql_query($sql) or die (mysql_error());
  // Make sure there's exactly one parent.
  assert('mysql_numrows($result) == 1');
  // Construct parent URL.
  $title= htmlspecialchars(mysql_result($result,0,"title"));
  $simple_title = simplify_string($title);
  $ym = date('Y/m',mysql_result($result,0,"created"));
  $parent_url = "http://$blog_url/$ym/$simple_title.html";

  $type = htmlspecialchars(mysql_result($result,0,"type"));
  // Translate the type into Blogger lingo.
  switch ($type) {
    case "story":
      $parent_type = "post";
      break;
    default:
      // Skip. Blogger pages (as opposed to posts) cannot have comments.
      continue;
  }

  // Create 32bit "pid" (9-digit decimal).
  // From http://api.drupal.org/api/drupal/modules%21comment%21comment.pages.inc/function/comment_reply/7:
  // $pid: Some comments are replies to other comments. In those cases, $pid is the parent comment's cid.
  $pid = mysql_result($result_c, $i_c, "pid");
  // 9-digit decimal = 32bit.
  $pid = my_hash($pid, 32);

  // August 13, 2012 8:11 AM
  $formatted_date = date('F m, Y h:i A',mysql_result($result_c,$i_c,"created"));

  $parent_blogger_id = "tag:drupal,blog-$blogger_id.post-$parent_id";

  //
  print "<entry>
    <id>tag:drupal,blog-$blogger_id.post-$parent_id.comment-$comment_id</id>
    <published>$created</published>
    <updated>$changed</updated>
    <category scheme='http://schemas.google.com/g/2005#kind' term='http://schemas.google.com/blogger/2008/kind#comment'/>
    <title type='text'>$title</title>
    <content type='html'>$body</content>
    <link href=\"http://www.blogger.com/feeds/$blogger_id/$parent_id/comments/default/$comment_id\" rel=\"edit\" type=\"application/atom+xml\"/>
    <link href=\"http://www.blogger.com/feeds/$blogger_id/$parent_id/comments/default/$comment_id\" rel=\"self\" type=\"application/atom+xml\"/>
    <link href=\"$my_url?showComment=1344870684962#c$comment_id\" rel=\"alternate\" title=\"\" type=\"text/html\"/>";
    // TODO conditionally insert "related"
    print "<author><name>$author</name>
      <uri>$url</uri>
      <email>a@a.com</email>
    </author>
    <thr:in-reply-to href=\"$parent_url\" ref=\"$parent_blogger_id\" source=\"http://www.blogger.com/feeds/$blogger_id/posts/default/$parent_id\" type=\"text/html\"/>
    <gd:extendedProperty name=\"blogger.itemClass\" value=\"pid-$pid\"/>
    <gd:extendedProperty name=\"blogger.displayTime\" value=\"$formatted_date\"/>
  </entry>
  ";
}

echo "\n";
