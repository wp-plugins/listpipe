<?php 
/*
Plugin Name: ListPipe Content Generator
Plugin URI: http://www.listpipe.com/plugins.php
Description: The ListPipe Pugin for WordPress pulls Powerful Custom Content from your ListPipe account and inserts it into your posts.
Version: 2.6
Author: Square Compass, Inc.
Author URI: http://www.squarecompass.com
*/
//Add Action
add_action('plugins_loaded','listpipe_get_content'); 
//Functions
function listpipe_get_content() {
	global $wpdb;
	switch(@$_REQUEST['action']) {
		case 'GetDraft':
			// Make sure the Draft Key, Approval Key and BlogPostingID are set.
			if(!empty($_POST['DraftKey']) && !empty($_POST['ApprovalKey']) && !empty($_POST['BlogPostingID'])) {
				// Get the new content from ListPipe (Open File).
				$data = "";
				$content_file = 
					"http://www.listpipe.com/blogs/getContent.php?action=GetContent&".
					"DraftKey=".urlencode($_POST['DraftKey'])."&".
					"BlogPostingID=".urlencode($_POST['BlogPostingID'])
				;
				@$handle = fopen($content_file,"r");
				if(empty($handle)) { // If fopen failed try CURL
					try{
						$ch = curl_init();
						curl_setopt($ch,CURLOPT_URL,$content_file);
						curl_setopt($ch,CURLOPT_HEADER,0);
						curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
						$data = curl_exec($ch); // Get file
						curl_close($ch);
					} catch(Exception $e) { die("failure on CURL (unable to open file): ".$e->getMessage()); }
				} else // Read File
					while (!feof($handle))
						$data .= fread($handle, 8192);
				@fclose($handle);
				if(empty($data) || substr($data,0,4) == "fail") // Validate file data
					die("failure to ".(empty($data)?"open":"read")." file");
				// Put the new content in the blog
				$contents = explode("{-~-}", $data);
				if(count($contents) == 2 || count($contents) == 3) {
					$is_draft = @$_POST["ApproveType"] == "draft";
					// Get/Make Category
					$cat_id = NULL;
					if(!empty($contents[2]) && is_string($contents[2])) {
						$cat_id = get_cat_id($contents[2]);
						if(empty($cat_id) || !is_numeric($cat_id)) { // Create Category
							require_once(ABSPATH.'wp-admin/includes/taxonomy.php');
							$cat_id = wp_insert_category(array("cat_name"=>$contents[2]));
						}
					}
					//Increased Allowed Tags to Accept Flash Video
					global $allowedposttags;
					$allowedposttags = array_merge(
						$allowedposttags,
						array(
							"object"=>array("width"=>array(),"height"=>array(),"data"=>array(),"type"=>array()),
							"param"=>array("name"=>array(),"value"=>array())
						)
					);
					//Get this Blogs Admin User
					$admin_user_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $wpdb->users WHERE user_email=%s;",get_option("admin_email")));
					// Save Post
					$publish_timestamp = strtotime("+ ".rand(0,3000)." seconds");
					$postID = wp_insert_post(array(
						"post_author"=>$admin_user_id,
						"post_status"=>($is_draft?"draft":"publish"),
						"post_type"=>"post",
						"post_title"=>$contents[0],
						"post_content"=>$contents[1],
						"post_category"=>array($cat_id),
						"post_date"=>date("Y-m-d H:i:s",$publish_timestamp),
						"post_date_gmt"=>gmdate("Y-m-d H:i:s",$publish_timestamp)
					));
					if(empty($postID))
						die("Unable to insert content.");
					// Save the approval key in wp_options for email post approval
					if($is_draft) 
						add_option("approvalKey".$postID,$_POST["ApprovalKey"],"","no");
					// Tell ListPipe that we have the content, pass back the PostID
					$data = "";
					$confirmation_ping = 
						"http://www.listpipe.com/blogs/getContent.php?action=ConfirmContent&".
						"DraftKey=".urlencode($_POST['DraftKey'])."&".
						"BlogPostingID=".urlencode($_POST['BlogPostingID'])."&".
						"PostID=".$postID
					;
					@$handle = fopen($confirmation_ping,"r");
					if(empty($handle)) { // If fopen failed try CURL
						try{
							$ch = curl_init();
							curl_setopt($ch,CURLOPT_URL,$confirmation_ping);
							curl_setopt($ch,CURLOPT_HEADER,0);
							curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
							$data = curl_exec($ch); // Get response
							curl_close($ch);
						} catch(Exception $e) { die("failure on CURL (unable to respond): ".$e->getMessage()); }
					} else // Read File
						while (!feof($handle))
							$data .= fread($handle,8192);
					@fclose($handle);
					die($data == "confirm success"?"success":"failure: bad response of \"$data\"");
				}
				die("failure: invalid separation of data");
			}
			die("failure: insufficient connection information");
		break;
		case 'PublishDraft':
			//Publish requested post
			$approval = get_option("approvalKey".@$_POST['pid']);
			if(!empty($approval) && $approval == @$_POST['key']) {
				wp_publish_post(@$_POST['pid']);
				if(delete_option("approvalKey".@$_POST['pid']))
					die("success");
				else
					die("updated, not deleted.");
			}
			die("fail");
		break;
	}
}