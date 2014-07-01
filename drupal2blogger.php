<?php
/**
 *   Copyright (c) 2012, Nico Schlömer <nico.schloemer@gmail.com>
 *   All rights reserved.
 *
 *   This file is part of drupal2blogger.
 *
 *   drupal2blogger is free software: you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation, either version 3 of the License, or
 *   (at your option) any later version.
 *
 *   This program is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU General Public License for more details.
 */

include 'my_data.php';

require 'vendor/autoload.php';
use \Michelf\Markdown;


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
//header('Content-type: text/xml');
//header('Content-Disposition: attachment; filename="drupal2blogger_export.xml"');

$sql = "SELECT * FROM ".$db_prefix."node as n JOIN ".$db_prefix."field_data_body as fdb ON n.nid=fdb.entity_id";

mysql_connect("127.0.0.1", $user, $pass) or die(mysql_error());
mysql_select_db($db) or die(mysql_error());

// Nodes
$result_node = mysql_query($sql) or die (mysql_error());
$numrows_node = mysql_numrows($result_node);


// Loop over the nodes.
for ($i_node = 0; $i_node < $numrows_node; $i_node++) {
  $type= htmlspecialchars(mysql_result($result_node,$i_node,"type"));


  // Translate the type into Blogger lingo.
  switch ($type) {
    case "blog":
      $blogger_type = "post";
      break;
    case "page":
      $blogger_type = "page";
      break;
    case "story":
      $blogger_type = "post";
      break;
    default:
      // Skip.
      // Use "continue 2", cf. http://www.php.net/manual/en/control-structures.switch.php.
      continue 2;
  }

  $nid = mysql_result($result_node, $i_node, "nid");
  $title= htmlspecialchars(mysql_result($result_node,$i_node,"title"));
  $created= date("c", mysql_result($result_node,$i_node,"created"));
  $updated= date("c", mysql_result($result_node,$i_node,"changed"));

  $body_md = mysql_result($result_node,$i_node,"body_value");

  $body_html = Markdown::defaultTransform($body_md);
  $body= htmlspecialchars($body_html);

  $sql_cc = "SELECT * FROM ".$db_prefix."node_comment_statistics WHERE nid = $nid";
  $result_cc = mysql_query($sql_cc) or die (mysql_error());
  $num_comments = mysql_result($result_cc, 0, "comment_count");

  // Blogger uses 19-digit integers (~63bits) as IDs, and we need to
  // recreate those later for the comments.
  // Hence, create a 63bit hash from the NodeID.
  $id = my_hash($nid, 63);
  // TODO hash isn't always 19 digits long. assert.

  echo "
  <entry>
    <id>tag:blogger.com,1999:blog-$blogger_id.$blogger_type-$id</id>
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
      echo "<category scheme='http://www.blogger.com/atom/ns#' term='$cat_name'/>";
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
  //
  //

  echo "
    <title type='text'>$title</title>
    <content type='html'>$body</content>";
  if ($num_comments > 0){
    // Comment links.
    echo "<link href=\"http://$blog_url/feeds/$blogger_id/comments/default\" rel=\"replies\" title=\"Post Comments\" type=\"application/atom+xml\"/>";
    echo "<link href=\"$my_url#comment-form\" rel=\"replies\" title=\"$num_comments Comments\" type=\"text/html\"/>";
  }

  echo "
  <link href=\"http://www.blogger.com/feeds/$blogger_id/pages/default/$id\" rel=\"edit\" type=\"application/atom+xml\"/>
  <link href=\"http://www.blogger.com/feeds/$blogger_id/pages/default/$id\" rel=\"self\" type=\"application/atom+xml\"/>
  <link href=\"$my_url\" rel=\"alternate\" title=\"$title\" type=\"text/html\"/>";
  echo "
    $global_author_tag
  </entry>";
}

// Comments
$sql_c = "SELECT * FROM ".$db_prefix."comment as c JOIN ".$db_prefix."field_data_comment_body as fdcb ON c.cid=fdcb.entity_id";
$result_c = mysql_query($sql_c) or die (mysql_error());
$numrows_c = mysql_numrows($result_c);
for ($i_c = 0; $i_c < $numrows_c; $i_c++) {
  // Node ID.
  $nid = mysql_result($result_c, $i_c, "nid");
  $parent_id = my_hash($nid, 63);
  // Comment ID.
  $cid = mysql_result($result_c, $i_c, "cid");
  // Add numrows_node to make the ID globally unique.
  // TODO this should rather be the maximum NID.
  $comment_id = my_hash($cid+$numrows_node, 63);
  // Meta data.
  $created = date("c", mysql_result($result_c,$i_c,"created"));
  $changed = date("c", mysql_result($result_c,$i_c,"changed"));
  $title = htmlspecialchars(mysql_result($result_c, $i_c, "subject"));
  $body = htmlspecialchars(mysql_result($result_c, $i_c, "comment_body_value"));
  $author_name = mysql_result($result_c, $i_c, "name");
  $author_email = mysql_result($result_c, $i_c, "mail");
  $author_url = mysql_result($result_c, $i_c, "homepage");

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
      // Use "continue 2", cf. http://www.php.net/manual/en/control-structures.switch.php.
      continue 2;
  }

  // Create 32bit "pid" (9-digit decimal).
  // From http://api.drupal.org/api/drupal/modules%21comment%21comment.pages.inc/function/comment_reply/7:
  // $pid: Some comments are replies to other comments. In those cases, $pid is the parent comment's cid.
  $pid = mysql_result($result_c, $i_c, "pid");
  // 9-digit decimal = 32bit.
  $pid_hash = my_hash($pid, 32);

  // August 13, 2012 8:11 AM
  $formatted_date = date('F m, Y h:i A',mysql_result($result_c,$i_c,"created"));

  $parent_blogger_id = "tag:blogger.com,1999:blog-$blogger_id.post-$parent_id";

  //
  echo "
  <entry>
    <id>tag:blogger.com,1999:blog-$blogger_id.post-$comment_id</id>
    <published>$created</published>
    <updated>$changed</updated>
    <category scheme='http://schemas.google.com/g/2005#kind' term='http://schemas.google.com/blogger/2008/kind#comment'/>
    <title type='text'>$title</title>
    <content type='html'>$body</content>
    <link href=\"http://www.blogger.com/feeds/$blogger_id/$parent_id/comments/default/$comment_id\" rel=\"edit\" type=\"application/atom+xml\"/>
    <link href=\"http://www.blogger.com/feeds/$blogger_id/$parent_id/comments/default/$comment_id\" rel=\"self\" type=\"application/atom+xml\"/>
    <link href=\"$parent_url?showComment=1344870684962#c$comment_id\" rel=\"alternate\" title=\"\" type=\"text/html\"/>";
    // If the post is a reply to a reply, add "related" link. This always refers to the top-level comment.
    // In Drupal, a comment on a comments is marked by having PID!=0. Thus, find the highest-level related
    // comment with PID=0.
    $curr_pid = $pid;
    if ($curr_pid != 0) {
      while ($curr_pid != 0) {
        $prev_pid = $curr_pid;
        $curr_pid = mysql_result($result_c, $prev_pid, "pid");
      }
      // At this point, $prev_pid is the ID of the comment with PID=0.
      $related_comment_id = my_hash($prev_pid+$numrows_node, 63);
      echo "<link href=\"http://www.blogger.com/feeds/$blogger_id/$parent_id/comments/default/$related_comment_id\" rel=\"related\" type=\"application/atom+xml\"/>";
    }
    echo "
    <author>
      <name>$author_name</name>";
    if (!empty($author_url))
      echo "
      <uri>$author_url</uri>";
    echo "
      <email>noreply@blogger.com</email>
    </author>
    ";
    echo "<thr:in-reply-to href=\"$parent_url\" ref=\"$parent_blogger_id\" source=\"http://www.blogger.com/feeds/$blogger_id/posts/default/$parent_id\" type=\"text/html\"/>
    <gd:extendedProperty name=\"blogger.itemClass\" value=\"pid-$pid_hash\"/>
    <gd:extendedProperty name=\"blogger.displayTime\" value=\"$formatted_date\"/>
  </entry>
  ";
}


echo "\n";
