<?php
/*
 * wpf_ajax_functions.php
 * This file contains the code for all methods that are been called from the frontend using ajax requests.
 */

/*
* Require admin functionality
*/
require_once( WPF_PLUGIN_DIR . 'inc/wpf_function.php' );
require_once( WPF_PLUGIN_DIR . 'inc/wpf_api.php' );

/*
 * This function is used to load the tasks on the "Tasks Center". It is been called from function wpf_backed_scripts() wpfeedback.php
 *
 * @input NULL
 * @return String ( Listing of Task Embedded with HTML Elements.)
 */
if ( ! function_exists( 'wpfeedback_get_post_list_ajax' ) ) {
    function wpfeedback_get_post_list_ajax() {
        global $wpdb, $current_user;
        $output           = '';
        $post_title_query = '';
        if ( is_user_logged_in() && is_admin() ) {
            wpf_security_check();
            $all_task_types_array      = array( 'publish', 'wpf_admin' );
            $all_task_types_meta       = '';
            $all_task_types_meta_array = array();

            /* START */
            $currnet_user_information = wpf_get_current_user_information();
            $current_role             = $currnet_user_information['role'];
            $current_user_name        = $currnet_user_information['display_name'];
            $current_user_id          = $currnet_user_information['user_id'];
            $wpf_website_builder      = get_site_data_by_key( 'wpf_website_developer' );
            if ( $current_user_name == 'Guest' ) {
                $wpf_website_client = get_site_data_by_key( 'wpf_website_client' );
                $wpf_current_role   = 'guest';
                if ( $wpf_website_client ) {
                    $wpf_website_client_info = get_userdata( $wpf_website_client );
                    if ( $wpf_website_client_info ) {
                        if ( $wpf_website_client_info->display_name == '' ) {
                            $current_user_name = $wpf_website_client_info->user_nicename;
                        } else {
                            $current_user_name = $wpf_website_client_info->display_name;
                        }
                    }
                }
            } else {
                $wpf_current_role = wpf_user_type();
            }
            $current_user_name = addslashes( $current_user_name );
            if ( $wpf_current_role == 'advisor' ) {
                $wpf_tab_permission_display_stickers = ( get_site_data_by_key( 'wpf_tab_permission_display_stickers_webmaster' ) != 'no' ) ? 'yes' : 'no';
                $wpf_tab_permission_display_task_id  = ( get_site_data_by_key( 'wpf_tab_permission_display_task_id_webmaster' ) != 'no' ) ? 'yes' : 'no';
            } elseif ( $wpf_current_role == 'king' ) {
                $wpf_tab_permission_display_stickers = get_site_data_by_key( 'wpf_tab_permission_display_stickers_client' ) == 'yes' ? 'yes' : 'no';
                $wpf_tab_permission_display_task_id  = get_site_data_by_key( 'wpf_tab_permission_display_task_id_client' ) == 'yes' ? 'yes' : 'no';
            } elseif ( $wpf_current_role == 'council' ) {
                $wpf_tab_permission_display_stickers = get_site_data_by_key( 'wpf_tab_permission_display_stickers_others' ) == 'yes' ? 'yes' : 'no';
                $wpf_tab_permission_display_task_id  = get_site_data_by_key( 'wpf_tab_permission_display_task_id_others' ) == 'yes' ? 'yes' : 'no';
            } else {
                $wpf_tab_permission_display_stickers = get_site_data_by_key( 'wpf_tab_permission_display_stickers_guest' ) == 'yes' ? 'yes' : 'no';
                $wpf_tab_permission_display_task_id  = get_site_data_by_key( 'wpf_tab_permission_display_task_id_guest' ) == 'yes' ? 'yes' : 'no';
            } 

            $args = array(
                'numberposts' => -1,
                'limit'       => 20,
                'post_type'   => 'wpfeedback',
                'orderby'     => 'title',
                'orderby'     => 'date',
                'order'       => 'DESC',
                'task_center' => 1,
                'wpf_site_id' => get_option( 'wpf_site_id' )
            );
	    
            if ( isset( $_POST['page_no'] ) ) {
                $args['page_no'] = sanitize_text_field( $_POST['page_no'] );
            } 
            if ( isset( $_POST['task_status'] ) ) {
                $args['status'] = sanitize_text_field( $_POST['task_status'] );
            }            
            if ( isset( $_POST['task_priority'] ) ) {
                $args['priority'] = sanitize_text_field( $_POST['task_priority'] );
            }            
            if ( isset( $_POST['task_title'] ) && $_POST['task_title'] != '' ) {
                $args['task_title'] = trim( sanitize_text_field( $_POST['task_title'] ) );
            }            
            if ( isset( $_POST['task_types_meta'] ) ) {
                $args['task_types_meta'] = sanitize_text_field( $_POST['task_types_meta'] );
            }            
            if ( isset( $_POST['task_types'] ) ) {
                $args['task_types'] = sanitize_text_field( $_POST['task_types'] );
            }	    
            if ( isset( $_POST['author_list'] ) ) {
                $args['task_notify_users'] = sanitize_text_field( $_POST['author_list'] );
            }
            
            if ( isset($_POST['internal'] ) && boolval( $_POST['internal'] ) ) {
                $args['internal'] = boolval( $_POST['internal'] );
            }

            $url         = WPF_CRM_API . 'wp-api/all/task-center-tasks';
            $sendtocloud = wp_json_encode( $args );
            $myposts     = wpf_send_remote_post( $url,$sendtocloud );
            $data        = isset( $myposts['data'] ) ? $myposts['data'] : '' ;
            if ( $myposts ) {
                if ( ( ! empty( $_POST['search_type'] ) ) && $_POST['search_type'] === 'sidebar' ) {
                    foreach ( $myposts['data'] as $key => $mypost ) {
                        $user_atarim_type = get_user_meta( $current_user_id, 'wpf_user_type', true );
                        if ( $mypost['task']['is_internal'] == '1' ) {
                            if ( $user_atarim_type != 'advisor' ) {
                                continue;
                            }
                        }
                        $wpf_page_url = $mypost['task']['task_page_url'];
                        if ( $wpf_page_url ) {
                            $wpf_page_url_with_and = explode( '&', $wpf_page_url )[1];
                            $wpf_page_url_question = explode( '?', $wpf_page_url )[1];
                            if ( $wpf_page_url_with_and ) {
                                $saperater = '&';
                            }
                            if ( $wpf_page_url_question ) {
                                $saperater = '&';
                            } else {
                                $saperater = '?';
                            }
                        }

                        $sticker_span        = '';
                        $custom_status_class = '';
                        $custom_status_class = $mypost['task']['task_status'].'_custom';
                        $sticker_span        = '<span class="sticker ' . $mypost['task']['task_priority'] . '_custom"></span> ';
                        $display_check_mark  = '';
                        $display_check_mark  = $mypost['task']['wpf_task_id'];
                        if ( $mypost['task']['task_status'] == 'complete' ) {
                            $bubble_label = $sticker_span . $display_check_mark;
                        } else {
                            $bubble_label = $sticker_span . $mypost['task']['wpf_task_id'];
                        }

                        $general_tag = '';
                        if ( $mypost['task']['task_type'] == 'general' ) {
                            $general_tag = '<span class="wpf_task_type" title="Task type">' . addslashes( $wpf_general_tag ) . '</span>';
                        }

                        $wpf_task_status_label   = '<div class="wpf_task_label"><span class="task_status wpf_' . $mypost['task']['task_status'] . '" title="Status: ' . $mypost['task']['task_status'] . '">' . get_wpf_status_icon() . '</span>';
                        $wpf_task_priority_label = '<span class="priority wpf_' . $mypost['task']['task_priority'] . '" title="Priority: ' . $mypost['task']['task_priority'] . '">' . get_wpf_priority_icon() . '</span></div>';
                        $all_wpfb_metas          = $mypost['task']['wpf_tags'];
                        if ( $all_wpfb_metas ) {
                            $tag_length     = count( $all_wpfb_metas );
                            $wpfb_tags_html = '<div class="wpf_task_tags">';
                            $all_tag        = array_keys( $all_wpfb_metas );
                            $i              = 1;
                            foreach ( $all_tag as $k => $value ) {
                                if ( $i == 1 ) {
                                    $wpfb_tags_html .=  '<span class="wpf_task_tag">' . $all_wpfb_metas[$value]["name"] . '</span>';
                                } else {
                                    if ( $tag_length == $i ) {
                                        $all_other_tag .=  $all_wpfb_metas['value']["name"];
                                    } else {
                                        $all_other_tag .=  $all_wpfb_metas['value']["name"] . ', ';
                                    }
                                }
                            }

                            if ( $tag_length > 1 ) {
                                $wpfb_tags_html .= '<span class="wpf_task_tag_more" title="' . $all_other_tag . '">...</span>';
                            }
                            $wpfb_tags_html .= '</div>';
                        }

                        $output .= '<li class="current_page_task ' . $mypost['task']['task_status'] .
                            " " . $mypost['task']['task_status'] . '_custom' . " " . $mypost['task']['task_priority'] .
                            '" data-taskid="' . $mypost['task']['wpf_task_id'] . '" data-postid="' . $key .
                            '" data-task_url="' . $mypost['task']['task_page_url'] . $saperater .
                            'wpf_general_taskid=' . $key . '"><div class="wpf_task_number">' . $bubble_label .
                            '</div><div class="wpf_task_sum"><level class="task-author">' .
                            $mypost['task']['task_config_author_name'] . '<span>' . $mypost['task']['task_time'] .
                            '</span></level><div class="wpf_task_pagename">' . $mypost['task']['task_page_title'] .
                            '</div><div class="current_page_task_list">' . $mypost['task']['task_title'] .
                            '</div></div><div class="wpf_task_meta"><div class="wpf_task_meta_icon">
                            <i class="gg-chevron-left"></i></div><div class="wpf_task_meta_details">' . $general_tag .
                            $wpf_task_status_label . $wpf_task_priority_label . $wpfb_tags_html . '</div></div></li>';
                    }
                } else {
                    if ( ! isset( $_POST['page_no'] ) ) {
                        $output .= '<ul id="all_wpf_list" style="list-style-type: none; font-size:12px;">';
                    }
                    if ( ! empty( $data ) ) {
                        $output .= return_task_list_html_taskcenter( $data, $current_user_id, $wpf_tab_permission_display_stickers, $wpf_tab_permission_display_task_id );
                    }
                    wp_reset_postdata();

                    if ( $myposts['status'] == 404 && $myposts['status'] != 1 ) {
                        $output = '<div class="wpf_no_tasks_found"><i class="gg-info"></i> No tasks found</div>';
                    }
                    if ( ! isset( $_POST['page_no'] ) ) {
                        $output .= '</ul>';
                    }
                }
            }
            echo $output;
            exit;
        }
    }
}
add_action( 'wp_ajax_wpfeedback_get_post_list_ajax', 'wpfeedback_get_post_list_ajax' );
add_action( 'wp_ajax_nopriv_wpfeedback_get_post_list_ajax', 'wpfeedback_get_post_list_ajax' );

/*
 * This function is used to load the task comments on the "Tasks Center". It is been called from function wpf_backed_scripts() in wpfeedback.php
 *
 * @input Array ( $_POST )
 * @return String ( Listing of Comments in Task Embedded with HTML Elements.)
 */
if ( ! function_exists( 'list_wpf_comment_func' ) ) {
    function list_wpf_comment_func() {
        if ( is_user_logged_in() && is_admin() ) {
            global $wpdb, $current_user;
            wpf_security_check();
            $response             = array();
            $response['data']     = '';
	        $response['wpf_tags'] = '';
            $comment              = "";
            $post_id              = sanitize_text_field( $_POST['post_id'] );
            $post_author_id       = sanitize_text_field( $_POST['post_author_id'] );
            $click                = sanitize_text_field( $_POST['click'] );
            $current_user_id      = $current_user->ID;
            if ( $post_id ) {
                $args = array(
                    'task_id'  => $post_id,
                    'type'     => 'wp_feedback',
                    'orderby'  => 'created_at',
                    'order'    => 'ASC',
                );

                $wpf_license_key_enc = get_option( 'wpf_license_key' );
                $wpf_license_key     = wpf_crypt_key( $wpf_license_key_enc, 'd' );
                $url                 = WPF_CRM_API . 'wp-api/comment/list';
                $sendtocloud         = wp_json_encode( $args );
                $comments_info       = wpf_send_remote_post( $url, $sendtocloud );
                $comment_count       = $comments_info['count'];
                $tags                = $comments_info['tags'];
                if ( ! empty( $tags ) && count( $tags ) > 0 ) {
                    foreach ( $tags as $term ) {
                        $response['wpf_tags'] .= '<span class="wpf_tag_name ' . $term['slug'] . '">' . $term['name'] . '<a href="javascript:void(0)" onclick="wpf_delete_tag_admin(\'' . $term['name'] . '\',\'' . $term['slug'] . '\',' . $post_id . ')" data-tag_slug="' . $term['slug'] . '"><i class="gg-close-o"></i></a></span>';
                    }
                }
                // display the results
                if ( $comment_count > 0 ) {
                    $avatar_array = array();
                    foreach ( $comments_info['data'] as $key => $comment ) {
                        $task_date                = $comment['created_at'];
                        $task_date                = strtotime( $task_date );
                        $task_date                = get_date_from_gmt( date( 'Y-m-d H:i:s', $task_date ), 'Y-m-d H:i:s' );
                        $task_date1               = date_create($task_date);
                        $wpf_wp_current_timestamp = date( 'Y-m-d H:i:s', current_time( 'timestamp', 0 ) );
                        $task_date2               = date_create( $wpf_wp_current_timestamp );
                        $curr_comment_time        = wpfb_time_difference( $task_date1, $task_date2 );
                        $author_id                = $comment['wpf_user_id'];
                        $author_img               = '';
                        $author_html              = '';
                        $author                   = "";
                        if ( array_key_exists( $comment['wpf_user_id'], $avatar_array ) ) {
                            $author_img = $avatar_array[ $comment['wpf_user_id'] ];
                        } else {
                            $author_img = get_avatar_url( $comment['wpf_user_id'], array( 'size'=>42, 'default'=>'404' ) );
                            $headers    = @get_headers( $author_img );
                            if ( ! empty( $headers ) ) {
                                if ( in_array( 'HTTP/1.1 404 Not Found', $headers ) ) {
                                    $author_img = '';
                                } else {
                                    $avatar_array[ $comment['wpf_user_id'] ] = $author_img;
                                }
                            } else {
                                $author_img = '';
                            }
                        }
                        if ( $comment['is_log'] != 1 ) {
                            if ( isset( $comment['wpf_author'] ) && isset( $comment['wpf_author']['wpf_display_name'] ) ) { // wpf user
                                $author = $comment['wpf_author']['wpf_display_name'];
                                $user   = get_user_by( 'id', $comment['wpf_author']['wpf_id'] );
                                if ( $user ) {
                                    $authr  = $user;
                                    $author = $authr->display_name;
                                } elseif ( get_user_by( 'login', $comment['wpf_author']['wpf_display_name'] ) ) {
                                    $author = get_user_by( 'login', $comment['wpf_author']['wpf_display_name'] );
                                    $author = $author->display_name;
                                }else{
                                    $author =  $comment['wpf_author']['wpf_display_name'];
                                }
                            } elseif ( isset( $comment['author'] ) && isset( $comment['author']['name'] ) && $comment['author']['name'] != '' ) { // app user
                                $author = $comment['author']['name'];
                            } elseif ( isset( $comment['author'] ) && isset( $comment['author']['username'] ) ) { // app user
                                $author = $comment['author']['username'];
                            } elseif ( isset( $comment['wpf_author'] ) && isset( $comment['wpf_author']['first_name'] ) && $comment['wpf_author']['first_name'] != '' ) { // wpf user
                                $author = $comment['wpf_author']['first_name'] . ' '. $comment['wpf_author']['last_name'];
                            } else {
                                $author = get_the_author_meta( 'display_name', $comment["wpf_user_id"] );
                                if ( empty( trim( $author ) ) ) {
                                    $task_author = get_user_by( 'ID', intval( $author_id ) );
                                    if ( ! $task_author ) {
                                        $author = "Deleted User";
                                    } else {
                                        if ( ! is_array( $comment['wpf_author'] ) && ! is_null( $comment['wpf_author'] ) ) {
                                            $author = $comment['wpf_author'];
                                        } else {
                                            $author = 'Guest';
                                        }
                                    }
                                }
                            }
                            if ( (!empty($comment['response'])) && !empty($comment['inbox']) ) {
                                if ( $comment['response']['delivery_status'] === 'outgoing' ) {
                                    $author = (!empty($comment['inbox'])) ? $comment['inbox']['from_name'] : "Guest";
                                } elseif ( $comment['response']['delivery_status'] === 'incoming' ) {
                                    $from_address = $comment['response']['from_address'];
                                    if ( strpos($from_address, '<') !== false ) {
                                        $parts1 = explode('<', $from_address);
                                        $parts2 = explode('>', $parts1[1]);
                            
                                        $from_address = $parts2[0];
                                    }
                                    $author = $from_address;
                                }
                            }
                        }

                        $new_class   = "";
                        $image_class = "";
                        $file_class  = "";
                        if ( $comment['is_log'] == 1 ) {
                            $new_class  = " is_info";
                            $author_img = WPF_PLUGIN_URL . 'images/bell.svg';
                        }

                        if ( $author_img == '' ) {
                            $author_html = substr($author, 0, 2);
                        } else {
                            $author_html = '<img src="' . $author_img . '" alt="author"></img>';
                        }

                        $note_class = '';
                        $note_html  = '';
                        if ( $comment['is_note'] == true ) {
                            $note_class = " is_note";
                            $note_html = '<small class="wpf_note_html">Note</small>';
                        }
                        if ( $current_user_id == $author_id ) {
                            $class = "chat_author" . $new_class . $note_class;
                        } else {
                            $class = ' ' . $file_class . ' ' . $image_class . "not_chat_author" . $new_class;
                        }

                        $name           = "<div class='wpf_initials'>" . $author . "</div>";
                        $image_dwn_icon = "<span id='wpf_push_media' class='wpf_push_media wpf_image_download'>" . get_wpf_push_to_media_icon() . "</span><span id='wpf_image_open' class='wpf_image_open' onclick='wpf_image_open_new_tab(this)'>" . get_wpf_image_open_icon();
                        if ( strpos( $comment['comment_content'], 'wpfeedback-image.s3' ) !== false ) {
                            if ( $comment['comment_type'] == 'image/png' || $comment['comment_type'] == 'image/gif' || $comment['comment_type'] == 'image/jpeg' ) {
                                $comment_text = '<a href="' . $comment['comment_content'] . '" target=_blank><div class="tag_img" style="width: 275px;height: 183px;"><div class="meassage_area_main"><a href="' . $comment['comment_content'] . '" target="_blank"></a><img src="' . $comment['comment_content'] . '" alt="" style="width: 100%;" class="wpfb_task_screenshot">' . $image_dwn_icon . '</div></div></a>';
                                $image_class  = " is_image";
                            } else {
                                $file_class   = " is_file";
                                $file_name    = preg_replace( '/\.[^.]$/', '', basename( $comment['comment_content'] ) );
                                $comment_text = '<a href="' . $comment['comment_content'] . '" download><div class="tag_file""><i class="gg-software-download"></i> ' . $file_name . '</div></a>';
                            }
                        } elseif ( wp_http_validate_url( $comment['comment_content'] ) && ! strpos( $comment['comment_content'], 'wpfeedback-image.s3' ) ) {
                            $idVideo = $comment['comment_content'];
                            $link    = explode("?v=",$idVideo);
                            if ( $link[0] == 'https://www.youtube.com/watch' ) {
                                $youtubeUrl = "http://www.youtube.com/oembed?url=$idVideo&format=json";
                                $docHead    = get_headers( $youtubeUrl );
                                if ( substr( $docHead[0], 9, 3 ) !== "404" ) {
                                    $comment_text = '<iframe width="100%" height="150" src="https://www.youtube.com/embed/' . $link[1] . '" type="text/html" width="500" height="265" frameborder="0" allowfullscreen></iframe>';
                                } else {
                                    $comment_text = $comment['comment_content'];
                                }
                            } else {
                                $comment_text = "<a target='_blank' href='" . $comment['comment_content'] . "'>" . $comment['comment_content'] . "</a>";
                            }
                        } else {
                            $comment_text = stripslashes( $comment['comment_content'] );
                        }

                        $files = $comment['files'];
                        $files_html = get_files_html($files);

                        $edit_delete_button_html = '';
                        $edited_html             = '';

                        if ( isset( $comment['is_edited'] ) && $comment['is_edited'] && ! $comment['is_deleted'] ) {
                            //$edited_html = '<span class="wpf-is-edited">(edited)<span class="wpf_tooltiptext edit_tooltip_text">' . $comment['updated_at'] . '</span></span>';        
                            $edited_html = '<span class="wpf-is-edited">(Edited)</span>';            
                        } else {
                            $edited_html = '';
                        }
                        if ( ( ! $comment['is_log'] ) && ( ! $comment['is_deleted'] ) ) {
                            $edit_delete_button_html  = '<div class="wpf-edit-delete-wrapper">';
                            $edit = is_feature_enabled( 'edit' );
                            if ( ! $edit ) {
                                $edit_delete_button_html .= '<a href="javascript:void(0)" style="display:none"></a><a href="javascript:void(0)" onclick="edit_delete_plan()" class="wpf_edit_box_active" id="wpf_edit_box_active"><i class="gg-pen"></i></a><a href="javascript:void(0)" class="wpf_comment_delete_btn" onclick="edit_delete_plan()"><i class="gg-trash"></i></a></div>';
                            } else {
                                $edit_delete_button_html .= '<a href="javascript:void(0)" style="display:none"></a><a href="javascript:void(0)" onclick="wpf_edit_box_active(' . $comment['id'] . ')" class="wpf_edit_box_active" id="wpf_edit_box_active"><i class="gg-pen"></i></a><a href="javascript:void(0)" class="wpf_comment_delete_btn" onclick="wpf_delete_comment(' . $comment['id'] . ')"><i class="gg-trash"></i></a></div>';
                            }
                        }
                        
                        if ( $comment['is_note'] != true || ( $comment['is_note'] == true && $current_user_id == $author_id ) ) {
                            $response['data'] .= '<li class="' . $class . ' ' . $file_class . ' ' . $image_class . '" id="task_comments_' . intval( $key + 1 ) . '" data-comment_id="' . $comment['id'] . '" title="' . $curr_comment_time['comment_time'] . '"><div class="wpf-comment-container"><div class="wpf-author-img">' . $author_html . '</div><div class="wpf-comment-wrapper"><level class="wpf-author"><div>' . $author . $note_html . '</div><span>' . 
                            $edited_html . $curr_comment_time['comment_time'] . '</span>' . $edit_delete_button_html . '</level><div class="meassage_area_main"><div class="task_text" id="wpf-chat-text-' . $comment['id'] . '">' . nl2br( $comment_text ) . '</div>' . $files_html . '</div><div id="wpfb-edit-comment-wrapper-' . $comment['id'] . '" class="wpfb-edit-comment-wrapper"><div class="wpf-editor"></div><textarea class="form-control wpfb-edit-comment" data-comment_id="' . $comment['id'] . '" placeholder="Edit the comment..." spellcheck="false">' . nl2br( $comment_text ) . '</textarea><button class="wpf_edit_comment_btn" onclick="wpfb_edit_comment(' . $comment['id'] . ')">Update</button><a class="wpf-cancel-edit-comment" onclick="wpfb_cancel_edit_comment(' . $comment['id'] . ')" href="javascript:void(0)">Cancel</a><div class="wpf_update_error wpf_hide">Please post your comment before performing this action</div></div></div></div></div></li>';
                        }
                    }
                } else {
                    $response['data'] = '<li id="wpf_not_found">No comments found</li>';
                }
            } else {
                $response['data'] = '<li id="wpf_not_found">No comments found</li>';
            }
            echo wp_json_encode( $response, JSON_INVALID_UTF8_IGNORE );
            die();
        }
        else{
            $response = '<li id="wpf_not_found">Something wrong</li>';
            echo $response;
            die();
        }
    }
}
add_action( 'wp_ajax_list_wpf_comment_func', 'list_wpf_comment_func' );

/*
 * This function is used to add new comments from the "Tasks Center". It is been called from function wpf_backed_scripts() in wpfeedback.php. The function hadles posting of all types of comments like text, images files etc.
 *
 * @input Array ( $_POST )
 * @return String (HTML to add comment to the chat)
 */
if ( ! function_exists( 'insert_wpf_comment_func' ) ) {
    function insert_wpf_comment_func() {
        global $wpdb;
        wpf_security_check();
        $s_comment_content = sanitize_textarea_field( $_POST['wpf_comment'] );
        $h_comment_content = html_entity_decode( $s_comment_content );
        $k_comment_content = wpf_wp_kses_check( $h_comment_content );
        $wpf_comment       = wpf_test_input( $k_comment_content );
        $post_id     = sanitize_text_field(  $_POST['post_id'] );
        $author_id   = sanitize_text_field(  $_POST['author_id'] );
        $note        = sanitize_text_field(  $_POST['note'] );

        try {
            if ( $wpf_comment ) {
                $url                        = WPF_CRM_API . 'wp-api/comment/create';
                $sendarr                    = array();
                $sendarr["task_id"]         = $post_id;
                $sendarr["comment_content"] = $wpf_comment;
                $sendarr["wpf_user_id"]     = $author_id;
                $sendarr["user_id"]         = "";
                $sendarr["is_note"]         = $note;
                $sendtocloud                = wp_json_encode( $sendarr );
                $comment_info               = wpf_send_remote_post( $url, $sendtocloud );
                if ( isset( $comment_info['status'] ) && isset( $comment_info['data'] ) ) {
                    $comment_info = $comment_info['data'];
                    if ( $post_id ) {
                        $url                = WPF_CRM_API . 'wp-api/task/detail';
                        $sendarr            = array();
                        $sendarr["task_id"] = $post_id;
                        $sendtocloud        = wp_json_encode( $sendarr );
                        $task_status        = wpf_send_remote_post( $url, $sendtocloud )["data"]["task_status"];
                        if ( $task_status == 'complete' ) {
                            $url                    = WPF_CRM_API . 'wp-api/assign/status';
                            $sendarr                = array();
                            $sendarr["task_id"]     = $post_id;
                            $sendarr["task_status"] = "open";
                            $sendtocloud            = wp_json_encode( $sendarr );
                            wpf_send_remote_post( $url, $sendtocloud );
                        }
                    }

                    $author_html = '';
                    $author_img  = get_avatar_url( get_current_user_id(), array( 'size' => 42, 'default' => '404' ) );
                    $headers     = @get_headers( $author_img );
                    if ( ! empty( $headers ) ) {
                        if ( in_array( 'HTTP/1.1 404 Not Found', $headers ) ) {
                            $author_img = '';
                        }
                    } else {
                        $author_img = '';
                    }
                    
                    $class = "chat_author";
                    $note_html  = '';
                    if ( $note == 'true' ) {
                       $class = "chat_author is_note";
                       $note_html = '<small class="wpf_note_html">Note</small>';
                    }

                    $task_date                = $comment_info["comment_date"];
                    $task_date                = strtotime( $task_date );
                    $task_date                = get_date_from_gmt( date( 'Y-m-d H:i:s', $task_date ), 'Y-m-d H:i:s' );
                    $task_date1               = date_create($task_date);
                    $wpf_wp_current_timestamp = date( 'Y-m-d H:i:s', current_time( 'timestamp', 0 ) );
                    $task_date2               = date_create( $wpf_wp_current_timestamp );
                    $curr_comment_time        = wpfb_time_difference( $task_date1, $task_date2 );
                    $author_id                = $comment_info["user_id"];
                    if ( $comment_info["wpf_user_id"] ) {
                        $author = get_the_author_meta( 'first_name', $comment_info["wpf_user_id"] ) . ' ' . get_the_author_meta( 'last_name', $comment_info["wpf_user_id"] );
                        if ( trim( $author ) == '' ) {
                            $author = get_the_author_meta( 'nickname', $comment_info["wpf_user_id"] );    
                        }
                    } elseif ( $author_id ) {
                        $author = get_the_author_meta( 'first_name', $comment_info["user_id"] );
                        if ( empty( $author ) ) {
                            $author = get_the_author_meta('display_name', $comment_info["user_id"]);
                        }
                    }

                    if ( $author_img == '' ) {
                        $author_html = substr($author, 0, 2);
                    } else {
                        $author_html = '<img src="' . $author_img . '" alt="author"></img>';
                    }

                    $image_dwn_icon = '';
                    if ( strpos( $comment_info["comment_content"], 'wpfeedback-image.s3' ) !== false ) {
                        if ( $comment_info['comment_type'] == 'image/png' || $comment_info['comment_type'] == 'image/gif' || $comment_info['comment_type'] == 'image/jpeg' ) {
                            $image_dwn_icon = "<span class='wpf_image_download'>" . get_wpf_image_download_icon() . "</span><span id='wpf_image_open' class='wpf_image_open' onclick='wpf_image_open_new_tab(this)'>" . get_wpf_image_open_icon() . "</span><span class='wpf_image_delete'>" . get_wpf_close_icon() . "</span>";
                            $comment        = '<a href="' . $comment_info["comment_content"] . '" target=_blank><div class="tag_img" style="width: 275px;height: 183px;"><img src="' . $comment_info["comment_content"] . '" alt="" style="width: 100%;" class="wpfb_task_screenshot"></div></a>';
                        } else {
                            $file_name = preg_replace( '/\.[^.]$/', '', basename( $comment_info["comment_content"] ) );
                            $comment   = '<a href="' . $comment_info["comment_content"] . '" download><div class="tag_img" style="width: 275px;height: 183px;"><i class="gg-software-download"></i> ' . $file_name . '</div></a>';
                        }
                    } elseif ( wp_http_validate_url( $comment_info["comment_content"] ) && ! strpos( $comment_info["comment_content"], 'wpfeedback-image.s3' ) ) {
                        $idVideo = $comment_info["comment_content"];
                        $link    = explode( "?v=", $idVideo );
                        if ( $link[0] == 'https://www.youtube.com/watch' ) {
                            $youtubeUrl = "http://www.youtube.com/oembed?url=$idVideo&format=json";
                            $docHead    = get_headers( $youtubeUrl );
                            if ( substr( $docHead[0], 9, 3 ) !== "404" ) {
                                $comment = '<iframe width="100%" height="150" src="https://www.youtube.com/embed/' . $link[1] . '" type="text/html" width="500" height="265" frameborder="0" allowfullscreen></iframe>';
                            } else {
                                $comment = $comment_info["comment_content"];
                            }
                        } else {
                            $comment = preg_replace( '/<a /', '<a target="_blank" ', $comment_info["comment_content"] );
                        }
                    } else { // text
                        $comment = $comment_info["comment_content"];
                    }
                    $author = ucwords($author);
                    //$profile_image           = "<div class='chat_initials'>" . $author . " <span>" . __( "now", 'atarim-visual-collaboration' ) . "</span></div>";
                    $author                  = '<div>' . $author . $note_html . '</div><span>' . __( "now", 'atarim-visual-collaboration' ) . '</span>';
                    $edit_delete_button_html = '<div class="wpf-edit-delete-wrapper"><a href="javascript:void(0)" onclick="wpf_edit_box_active(' . $comment_info['id'] . ')" class="wpf_edit_box_active" id="wpf_edit_box_active"><i class="gg-pen"></i></a><a href="javascript:void(0)" class="wpf_comment_delete_btn"  onclick="wpf_delete_comment(' . $comment_info['id'] . ')" ><i class="gg-trash"></i></a></div>';
                    $textarea_comment_edit   = '<div id="wpfb-edit-comment-wrapper-' . $comment_info['id'] . '" class="wpfb-edit-comment-wrapper"><div class="wpf-editor"></div><textarea class="form-control wpfb-edit-comment" data-comment_id="' . $comment_info['id'] . '" placeholder="Edit the comment..." spellcheck="false">' . $comment . '</textarea><button class="wpf_edit_comment_btn" onclick="wpfb_edit_comment(' . $comment_info['id'] . ')">Update</button><a class="wpf-cancel-edit-comment" onclick="wpfb_cancel_edit_comment(' . $comment_info['id'] . ')" href="javascript:void(0)">Cancel</a><div class="wpf_update_error wpf_hide">Please post your comment before performing this action</div></div>';
                    echo '<li class="' . $class . '"  data-comment_id="' . $comment_info["id"] . '"><div class="wpf-comment-container"><div class="wpf-author-img">' . $author_html . '</div><div class="wpf-comment-wrapper"><level class="wpf-author">' . $author . '</level>' . $edit_delete_button_html . '<div class="meassage_area_main">' . $image_dwn_icon . '<div class="task_text" id="wpf-chat-text-' . $comment_info["id"] . '">' . nl2br($comment) . '</div></div>' . $textarea_comment_edit . '</div></div></li>';
                } else {
                    if ( ! empty( $comment_info['limit'] ) && $comment_info['limit'] === true ) {
                        echo wp_json_encode( $comment_info );
                    } else {
                        echo 'Error saving comment. Please contact administrator.';
                    }
                }
                /*Clear cache if checkbox enabled in settings */
                if ( get_option( 'wpf_enable_clear_cache' ) == 'yes') {
                    clearObjectCache();
                }
                die();
            }
        } catch (Exception $ex) {}
    }
}
add_action( 'wp_ajax_insert_wpf_comment_func', 'insert_wpf_comment_func' );
add_action( 'wp_ajax_nopriv_insert_wpf_comment_func', 'insert_wpf_comment_func' );

/*
 * This function is used to get the email of current user. This function is not used currently.
 *
 * @input NULL
 * @return String
 */
if ( ! function_exists( 'wpf_notify_admin_add_ons_func' ) ) {
    function wpf_notify_admin_add_ons_func() {
        wpf_security_check();
        $current_user_id = get_current_user_id();
        $user_info       = get_userdata( $current_user_id );
        echo $user_info->user_email;
        echo $add_ons_name = sanitize_text_field( $_POST['add_ons'] );
        exit;
    }
}
add_action( 'wp_ajax_wpf_notify_admin_add_ons_func', 'wpf_notify_admin_add_ons_func' );

/*
 * This function is used to create a new task. This function is used to create task from Frontend Pages, Backend Tasks Center, Graphics and API.
 *
 * @input Array ( $_POST['new_task'] )
 * @return Int (Task ID)
 */
if ( ! function_exists( 'wpf_add_new_task' ) ) {
    function wpf_add_new_task() {
        global $wpdb, $current_user;
        wpf_security_check();
        if ( $current_user->ID == 0 ) {
            $user_id = get_site_data_by_key( 'wpf_website_client' );
        } else {
            $user_id = $current_user->ID;
        }
        $comment_count = get_last_task_id();
        $task_data     = json_decode(stripslashes($_POST['new_task']), true);

        if ( $task_data['task_page_title'] == '' && ! is_admin() ) {
            $task_data['task_page_title'] = get_the_title( sanitize_text_field( $task_data['current_page_id'] ) );
        } else if( isset( $task_data['wpf_current_screen'] ) && $task_data['wpf_current_screen'] != '' ) {
            $task_data['task_page_title'] = sanitize_text_field( $task_data['wpf_current_screen'] );
        } else if( $task_data['task_page_title'] == '' ) {
            $task_data['task_page_title'] = 'Page';
        }
        if ( $task_data['task_page_url'] == '' ) {
            $task_data['task_page_url'] = $current_page_url = get_permalink( sanitize_text_field( $task_data['current_page_id'] ) );
        }
        if ( isset( $task_data['wpf_current_screen'] ) && $task_data['wpf_current_screen'] != '' ) {
            $wpf_current_screen = sanitize_text_field( $task_data['wpf_current_screen'] );
        } else {
            $wpf_current_screen = '';
        }	
        $task_data["wpf_site_id"] = get_option( "wpf_site_id" );
        $task_data['wpf_task_id'] = $comment_count;
	
        if( isset( $task_data['task_element_path'] ) ) {
            $task_data['task_element_path'] = str_replace( ".active_comment", "", $task_data['task_element_path'] );
            $task_data['task_element_path'] = str_replace( ".logged-in.admin-bar", "", $task_data['task_element_path'] );
            $task_data['task_element_path'] = str_replace( ".no-customize-support", "", $task_data['task_element_path'] );
            $task_data['task_element_path'] = str_replace( ".customize-support", "", $task_data['task_element_path'] );
            $task_data['task_element_path'] = str_replace( ".gf_browser_chrome", "", $task_data['task_element_path'] );
            $task_data['task_element_path'] = str_replace( ".gf_browser_gecko", "", $task_data['task_element_path'] );
            $task_data['task_element_path'] = str_replace( ".wpfb_task_bubble", "", $task_data['task_element_path'] );
        }

        $wpf_allowed_filetypes = array( "jpg", "jpeg", "png", "gif", "pdf", "doc", "docx", "xlsx", "xls", "mp4", "webm", "mov", "wmv", "avi", "ttf", "txt", "psd", "svg" );
        $content = array();
        if ( ! empty( $_FILES['wpf_upload_file'] ) ) {
            for ($i = 0; $i < count( $_FILES['wpf_upload_file']['name'] ); $i++ ) {
                $temp_file_name = $_FILES['wpf_upload_file']['name'][$i];
                $temp_file_type = $_FILES['wpf_upload_file']['type'][$i];
                $file_path = $_FILES['wpf_upload_file']['tmp_name'][$i];
                $data = file_get_contents( $file_path );
                $file_name = explode( '.', $temp_file_name );
                $file_ext = strtolower( end( $file_name ) );

                if ( $file_ext == 'xlsx' || $file_ext == 'xls' || $file_ext == 'docx' || $file_ext == 'doc' || $file_ext == 'avi' || $file_ext = "psd" ) {
                    $invalid = 0;
                } else {
                    $invalid = wpf_verify_file_upload( $_SERVER, $data );
                    $invalid = wpf_verify_file_upload_type( $_SERVER, $temp_file_type );
                }
                if ( $invalid == 0 ) {
                    if ( ! in_array( $file_ext, $wpf_allowed_filetypes ) ) {
                        $invalid = 1;
                    }
                }
                if ( $invalid == 0 ) {
                    $file_type = 0;
                    if ( in_array( $temp_file_type, array( 'image/jpeg', 'image/png', 'image/gif' ) ) ) {
                        $file_type = 1;
                    } else {
                        $file_type = 2;
                    }
                    //$content[] = 'data:' . $temp_file_type . ';base64,' . base64_encode( $data );
                    $content[] = array(
                        'name' => $temp_file_name,
                        'data' => 'data:' . $temp_file_type . ';base64,' . base64_encode( $data )
                    );
                }
            }
        }
        $task_content                      = sanitize_textarea_field( $task_data['task_comment_message'] );
        $h_task_content                    = html_entity_decode( $task_content );
        $k_task_contents                   = wpf_wp_kses_check( $h_task_content );
        $t_task_contents                   = wpf_test_input( $k_task_contents );
        $task_data['task_comment_message'] = $t_task_contents;
        $task_data['task_title']           = $t_task_contents;
        $task_data['files']                = $content;
        $url                               = WPF_CRM_API . 'wp-api/task/create';
        $sendtocloud                       = wp_json_encode( $task_data );
        $res                               = wpf_send_remote_post( $url, $sendtocloud );
        echo wp_json_encode( $res );
        exit();
    }
}
add_action( 'wp_ajax_wpf_add_new_task', 'wpf_add_new_task' );
add_action( 'wp_ajax_nopriv_wpf_add_new_task', 'wpf_add_new_task' );

/*
 * This function is used to create a new comment for the task. This function is used to create comment from Frontend Pages, Backend Tasks Center And Graphics.
 *
 * @input Array ( $_POST['new_task'] )
 * @return String (Message)
 */
if ( ! function_exists( 'wpfb_add_comment' ) ) {
    function wpfb_add_comment() {
        global $wpdb, $current_user;
        wpf_security_check();
        $user_id = 0;
        if ( $current_user->ID == 0 ) {
            $user_id = get_site_data_by_key( 'wpf_website_client' );
        } else {
            $user_id = $current_user->ID;
        }
        $enabled_wpfeedback    = wpf_check_if_enable();
        if ( $enabled_wpfeedback == 1 ) {
            $wpf_allowed_filetypes = array( "jpg", "jpeg", "png", "gif", "pdf", "doc", "docx", "xlsx", "xls", "mp4", "webm", "mov", "wmv", "avi", "ttf", "txt", "psd", "svg" );
            $wpf_taskid = sanitize_text_field( $_POST['task_id'] );
            $content = array();
            if ( ! empty( $_FILES['wpf_upload_file'] ) ) {
                for ($i = 0; $i < count( $_FILES['wpf_upload_file']['name'] ); $i++ ) {
                    $temp_file_name = $_FILES['wpf_upload_file']['name'][$i];
                    $temp_file_type = $_FILES['wpf_upload_file']['type'][$i];
                    $file_path = $_FILES['wpf_upload_file']['tmp_name'][$i];
                    $data = file_get_contents( $file_path );
                    $file_name = explode( '.', $temp_file_name );
                    $file_ext = strtolower( end( $file_name ) );

                    if ( $file_ext == 'xlsx' || $file_ext == 'xls' || $file_ext == 'docx' || $file_ext == 'doc' || $file_ext == 'avi' || $file_ext = "psd" ) {
                        $invalid = 0;
                    } else {
                        $invalid = wpf_verify_file_upload( $_SERVER, $data );
                        $invalid = wpf_verify_file_upload_type( $_SERVER, $temp_file_type );
                    }
                    if ( $invalid == 0 ) {
                        if ( ! in_array( $file_ext, $wpf_allowed_filetypes ) ) {
                            $invalid = 1;
                        }
                    }
                    if ( $invalid == 0 ) {
                        $file_type = 0;
                        if ( in_array( $temp_file_type, array( 'image/jpeg', 'image/png', 'image/gif' ) ) ) {
                            $file_type = 1;
                        } else {
                            $file_type = 2;
                        }
                        //$content[] = 'data:' . $temp_file_type . ';base64,' . base64_encode( $data );
                        $content[] = array(
                            'name' => $temp_file_name,
                            'data' => 'data:' . $temp_file_type . ';base64,' . base64_encode( $data )
                        );
                    }
                }
            }
            $comment_content                   = sanitize_textarea_field( $_POST['comment_content'] );
            $h_comment_content                 = html_entity_decode( $comment_content );
            $k_comment_content                 = wpf_wp_kses_check( $h_comment_content );
            $t_comment_content                 = wpf_test_input( $k_comment_content );
            $task_data['comment_content']      = $t_comment_content;
            $task_data['task_comment_message'] = $t_comment_content;
            $task_data['task_id']              = $wpf_taskid;
            $task_data['wpf_user_id']          = $user_id;
            $task_data['user_id']              = "";
            $task_data['is_note']              = $_POST['is_note'];
            $task_data['files']                = $content;
            $url                               = WPF_CRM_API . 'wp-api/comment/create';
            $sendtocloud                       = wp_json_encode( $task_data );
            $res                               = wpf_send_remote_post( $url, $sendtocloud );
            if ( array_key_exists( 'status', $res ) ) {
                if ( $res['status'] == '200' && array_key_exists( 'data', $res ) ) {
                    $res['data']['id'] = encrypt_id( $res['data']['id'] );
                }
            }
            echo wp_json_encode( $res );
            exit;
        } else {
            echo 0;
        }
        exit;
    }
}
add_action( 'wp_ajax_wpfb_add_comment', 'wpfb_add_comment' );
add_action( 'wp_ajax_nopriv_wpfb_add_comment', 'wpfb_add_comment' );

/*clear object cache function */
function clearObjectCache() {
    wp_cache_flush();
}


/*
 * This function is used to load all the tasks. This function is used to load tasks on Frontend Sidebar, Backend Sidebar and Graphics Sidebar.
 *
 * @input Array ( $_POST )
 * @return JSON (Listing of all tasks)
 */
if ( ! function_exists( 'load_wpfb_tasks' ) ) {
    function load_wpfb_tasks(){
        wpf_security_check();
        ob_start();
        ob_clean();
        $response            = array();
        $current_page_url    = isset( $_POST['current_page_url'] ) ? sanitize_text_field( $_POST['current_page_url'] ) : '';
        $current_page_id     = isset( $_POST['current_page_id'] ) ? sanitize_text_field( $_POST['current_page_id'] ) : '';
        $page_type           = isset( $_POST['page_type'] ) ? sanitize_text_field( $_POST['page_type'] ) : '';
        $fallback_link       = isset( $_POST['fallback_link_check'] ) ? sanitize_text_field( $_POST['fallback_link_check'] ) : 0;
        $current_screen      = isset( $_POST['wpf_current_screen'] ) ? sanitize_text_field( $_POST['wpf_current_screen'] ) : '';
        $wpside              = isset( $_POST['wpside'] ) ? sanitize_text_field( $_POST['wpside'] ) : '';
        $is_admin            = ( $wpside == 'backend' ) ? 1 : 0;
        $post_data = '';
        
        if ( $current_page_id != '' ) {
            $post_data = array(
                'wpf_site_id'      => get_option( 'wpf_site_id' ),
                'url'              => WPF_HOME_URL,
                'task_types'       => [],
                'sort'             => ['task_title', 'created_at'],
                'sort_by'          => 'asc',
                'current_page_id'  => $current_page_id,
                'current_page_url' => $current_page_url,
                'page_type'        => $page_type,
                'fallback_link'    => $fallback_link
            );
        } else if ( $current_screen != '' ) {
            $post_data = array(
                'wpf_site_id'        => get_option( 'wpf_site_id' ),
                'wpf_current_screen' => $current_screen,
                'task_types'         => [],
                "sort"               => ["task_title", "created_at"],
                "sort_by"            => "asc",
                'url'                => WPF_HOME_URL,
                "is_admin_task"      => 1
            );
        } else if ( isset( $_POST['task_id'] ) && $_POST['task_id'] != "" ) { // load by task id
            $post_data = array(
                'wpf_task_id' => sanitize_text_field( $_POST['task_id'] ),
                'post_type'   =>'wpfeedback',
                'wpf_site_id' => get_option( 'wpf_site_id' ),
                'url'         => WPF_HOME_URL
            );  
        } else { // in backend, fronted tab
            $page_no = $_POST['page_no'];
            $post_data = array(
                'wpf_site_id'     => get_option( 'wpf_site_id' ),
                'task_types'      => [],
                "current_page_id" => '',
                'post_type'       => 'wpfeedback',
                'numberposts'     => -1,
                'limit'           => 20,
                'page_no'         => $page_no,
                'post_status'     => 'any',
                'orderby'         => 'date',
                'order'           => 'DESC',
                'url'             => WPF_HOME_URL,
                'is_admin_task'   => $is_admin
            );
        }
        $url         = WPF_CRM_API . 'wp-api/all/task';
        $sendtocloud = wp_json_encode( $post_data );
        $wpfb_tasks  = wpf_send_remote_post( $url, $sendtocloud );
        if ( ! empty( $wpfb_tasks ) && $wpfb_tasks['status'] !== false ) {
            $response = process_task_response( $wpfb_tasks );
        }     
             
        // milestone
        if ( is_feature_enabled( 'project_stages' ) ) {
            if ( ! empty( $wpfb_tasks ) && isset( $wpfb_tasks['milestone'] ) && ! is_null( $wpfb_tasks['milestone'] ) ) {
                $response['milestone'] = $wpfb_tasks['milestone'];
            }
        }

        // Tags.
        if ( ! empty( $wpfb_tasks ) && isset( $wpfb_tasks['wpf_all_tags'] ) && ! is_null( $wpfb_tasks['wpf_all_tags'] ) ) {
            $response['wpf_all_tags'] = $wpfb_tasks['wpf_all_tags'];
        }

        //update_option( 'restrict_plugin', $wpfb_tasks['limit'], 'no' );
        ob_end_clean();
        echo wp_json_encode( $response );
        exit;
    }
}
add_action( 'wp_ajax_load_wpfb_tasks', 'load_wpfb_tasks' );
add_action( 'wp_ajax_nopriv_load_wpfb_tasks', 'load_wpfb_tasks' );

/*
 * This function is used to set the priority of the tasks. This function is used to set priority from Frontend Pages, Backend Pages, Backend Tasks Center and Graphics Tasks.
 *
 * @input Array ( $_POST['task_info'] )
 * @return Boolean
 */
if ( ! function_exists( 'wpfb_set_task_priority' ) ) {
    function wpfb_set_task_priority() {
        wpf_security_check();
        $task_info           = $_POST['task_info'];
        $url                 = WPF_CRM_API . 'wp-api/task/update-task-details';
        $current_user        = wp_get_current_user();
        $data['user_id']     = $current_user->ID;
        $data['method']      = 'priority';
        $data['task_id']     = $task_info['task_id'];
        $data['value']       = $task_info['task_priority'];
        $data['from_wp']     = 1;
        $data['wpf_site_id'] = get_option( 'wpf_site_id' );
        $arg                 = wp_json_encode( $data );
        $response = wpf_send_remote_post( $url, $arg );
        print_r($response);
        exit;
    }
}
add_action( 'wp_ajax_wpfb_set_task_priority', 'wpfb_set_task_priority' );
add_action( 'wp_ajax_nopriv_wpfb_set_task_priority', 'wpfb_set_task_priority' );

/*
 * This function is used to set the status of the tasks and send notifications to the user based on the status. This function is used to set status from Frontend Pages, Backend Pages, Backend Tasks Center and Graphics Tasks.
 *
 * @input Array ( $_POST['task_info'] )
 * @return Boolean
 */
if ( ! function_exists( 'wpfb_set_task_status' ) ) {
    function wpfb_set_task_status() {
            wpf_security_check();
            $task_info           = $_POST['task_info'];
            $url                 = WPF_CRM_API . 'wp-api/task/update-task-details';
            $current_user        = wp_get_current_user();
            $data['user_id']     = $current_user->ID;
            $data['method']      = 'status';
            $data['task_id']     = $task_info['task_id'];
            $data['value']       = $task_info['task_status'];
            $data['from_wp']     = 1;
            $data['wpf_site_id'] = get_option( 'wpf_site_id' );
            $arg                 = wp_json_encode( $data );
            $response = wpf_send_remote_post( $url, $arg );
            print_r($response);
            exit;
    }
}
add_action( 'wp_ajax_wpfb_set_task_status', 'wpfb_set_task_status' );
add_action( 'wp_ajax_nopriv_wpfb_set_task_status', 'wpfb_set_task_status' );

/*
 * This function is used to set the edit the comments
 * @input Array ( $_POST['task_info'] )
 * @return Boolean
 */
if ( ! function_exists( 'wpfb_edit_comment' ) ) {
    function wpfb_edit_comment() {
        wpf_security_check();
        $task_info = $_POST['task_info'];
        $s_comment_content = sanitize_textarea_field( $task_info['comment_content'] );
        $h_comment_content = html_entity_decode( $s_comment_content );
        $k_comment_content = wpf_wp_kses_check( $h_comment_content );
        $comment_content   = wpf_test_input( $k_comment_content );
        $comment_id        = sanitize_text_field( $task_info['comment_id'] );
        $comment_id        = decrypt_id( $comment_id );
        $args = array(
            'comment_id'      => $comment_id,
            'comment_content' => $comment_content,
        );
        $url           = WPF_CRM_API . 'wp-api/comment/updateContent';
        $sendtocloud   = wp_json_encode( $args );
        $comments_info = wpf_send_remote_post( $url, $sendtocloud );      
        if ( $comments_info['status'] == 200 ) {
            echo "1";
        }
        exit;
    }
}
add_action( 'wp_ajax_wpfb_edit_comment', 'wpfb_edit_comment' );
add_action( 'wp_ajax_nopriv_wpfb_edit_comment', 'wpfb_edit_comment' );

/*
 * This function is used to delete the comments
 * @input Array ( $_POST['task_info'] )
 * @return Boolean
 */
if ( ! function_exists( 'wpf_delete_comment' ) ) {
    function wpf_delete_comment() {
        global $wpdb, $current_user;
        wpf_security_check();
        $user_id = 0;
        if ( $current_user->ID == 0 ) {
            $user_id = get_site_data_by_key( 'wpf_website_client' );
        } else {
            $user_id = $current_user->ID;
        }
        $task_info  = $_POST['task_info'];
        $comment_id = sanitize_text_field( $task_info['comment_id'] );
        $comment_id = decrypt_id( $comment_id );
        $args       = array(
            'comment_id' => $comment_id,
            'user_id'    => $user_id,
            'from_wp'    => true,
        );

        $url           = WPF_CRM_API . 'wp-api/comment/trash';
        $sendtocloud   = wp_json_encode( $args );
        $comments_info = wpf_send_remote_post( $url, $sendtocloud );
        if($comments_info['status'] == 200) {
            echo wp_json_encode( $comments_info['data'] );
        }
        exit;
    }
}
add_action( 'wp_ajax_wpf_delete_comment', 'wpf_delete_comment' );
add_action( 'wp_ajax_nopriv_wpf_delete_comment', 'wpf_delete_comment' );

/*
 * This function is used to mark the tasks as internal. This function is used to mark internal from Frontend Pages, Backend Pages, Backend Tasks Center and Graphics Tasks.
 *
 * @input Array ( $_POST['task_info'] )
 * @return Boolean
 */
if ( ! function_exists( 'wpfb_mark_as_internal' ) ) {
    function wpfb_mark_as_internal() {
        wpf_security_check();
        $task_info           = $_POST['task_info'];
        $url                 = WPF_CRM_API . 'wp-api/task/internal';
        $current_user        = wp_get_current_user();
        $data['user_id']     = $current_user->ID;
        $data['task_id']     = $task_info['task_id'];
        $data['internal']    = $task_info['internal'];
        $data['from_wp']     = 1;
        $data['wpf_site_id'] = get_option( 'wpf_site_id' );
        $arg                 = wp_json_encode( $data );
        $response            = wpf_send_remote_post( $url, $arg );  
        echo wp_json_encode( $response );
        exit;
    }
}
add_action( 'wp_ajax_wpfb_mark_as_internal', 'wpfb_mark_as_internal' );
add_action( 'wp_ajax_nopriv_wpfb_mark_as_internal', 'wpfb_mark_as_internal' );


if ( ! function_exists( 'wpfb_approve_page' ) ) {
    function wpfb_approve_page() {
            wpf_security_check();
            $task_info['page_id']            = sanitize_text_field( $_POST['page_id'] );
            $task_info['set_tasks_complete'] = sanitize_text_field( $_POST['complete_tasks'] );
            $task_info['user_id']            = sanitize_text_field( $_POST['current_user_id'] );
            $task_info['wpf_site_id']        = get_option( 'wpf_site_id' );
            $url                             = WPF_CRM_API . 'wp-api/approve-page';
            $sendtocloud                     = wp_json_encode( $task_info );
            $res                             = wpf_send_remote_post( $url, $sendtocloud );

            if ( $res['status'] ) {
                echo 1;
            } else {
                echo wp_json_encode( $res );
            }
            exit;
    }
}
add_action( 'wp_ajax_wpfb_approve_page', 'wpfb_approve_page' );
add_action( 'wp_ajax_nopriv_wpfb_approve_page', 'wpfb_approve_page' );

/*
 * This function is used to set the notify users for the task. This function is used to set notify users from Frontend Pages, Backend Pages, Backend Tasks Center and Graphics Tasks.
 *
 * @input Array ( $_POST['task_info'] )
 * @return Boolean
 */
if ( ! function_exists( 'wpfb_set_task_notify_users' ) ) {
    function wpfb_set_task_notify_users() {
        wpf_security_check();
        $task_notify_users         = filter_var( $_POST['task_info']['task_notify_users'], FILTER_SANITIZE_FULL_SPECIAL_CHARS );
        $task_data['notify_users'] = $task_notify_users;
        $task_data['task_id']      = sanitize_text_field( $_POST['task_info']['task_id'] );
        $task_data['method']       = "notify_users";
        $task_data['from_wp']      = 1;
        $url                       = WPF_CRM_API . 'wp-api/task/update-task-details';
        $sendtocloud               = wp_json_encode( $task_data );
        $res                       = wpf_send_remote_post( $url, $sendtocloud );
        if ( $res['status'] == 1 || $res['status'] == 200 ) {
            echo 1;
        } else {
            echo 0;
        }
        exit;
    }
}
add_action( 'wp_ajax_wpfb_set_task_notify_users', 'wpfb_set_task_notify_users' );
add_action( 'wp_ajax_nopriv_wpfb_set_task_notify_users', 'wpfb_set_task_notify_users' );

/*
 * This function is used to take the screenshot and add to the task. This function is used to take screenshots from Frontend Pages, Backend Pages, Backend Tasks Center and Graphics Tasks.
 *
 * @input Array ( $_POST )
 * @return Boolean
 */
if ( ! function_exists( 'wpfb_save_screenshot_function' ) ) {
    function wpfb_save_screenshot_function() {
        global $wpdb, $current_user;
        wpf_security_check();
        if ( $current_user->ID == 0 ) {
            $task_config_author_id = get_site_data_by_key( 'wpf_website_client' );
        } else {
            $task_config_author_id = $current_user->ID;
        }

        $image           = $_POST['image'];
        $task_screenshot = $_POST['task_screenshot'];
        $post_id         = $task_screenshot['post_id'];
        if ( $_POST["autoscreen"] == "1" ) {        
            $image_parts                = explode( "base64,", $image );
            $url                        = WPF_CRM_API . 'wp-api/task/image/upload';
            $sendarr                    = array();
            $sendarr["task_id"]         = $post_id;
            $sendarr["task_screenshot"] = $image;
            $sendtocloud                = wp_json_encode( $sendarr );
            print_r( wpf_send_remote_post( $url, $sendtocloud )["data"] );
            exit;
        } else {
            $temp_wpf_file_type      = "image/jpeg";
            $file                    = $_POST['image'];
			$base64_image            = $file;
			$args                    = [];
			$args['task_id']         = $post_id;
			$args['comment_content'] = $base64_image;
			$args['user_id']         = "";
			$args['wpf_user_id']     = $task_config_author_id;
            $args['file_upload']     = 1;
			$args['file_name']       = "Screenshot";
			$args['type']            = $temp_wpf_file_type;
			$url                     = WPF_CRM_API . 'wp-api/comment/create';
			$sendtocloud             = wp_json_encode($args);
            $res                     = wpf_send_remote_post( $url, $sendtocloud );

			if ( $res['status'] == 200 ) {
                echo $res['data']['comment_content'];
                die();
            }
        }
    }
}
add_action( 'wp_ajax_wpfb_save_screenshot', 'wpfb_save_screenshot_function' );
add_action( 'wp_ajax_nopriv_wpfb_save_screenshot', 'wpfb_save_screenshot_function' );

/*
 * This function is used to reset the White Labeling settings from the backend.
 *
 * @input NULL
 * @return NULL
 */
if ( ! function_exists( 'wpfeedback_reset_setting' ) ) {
    function wpfeedback_reset_setting() {
        wpf_security_check();
        $options['wpf_site_id']           = get_option( 'wpf_site_id' );
        $options['wpfeedback_color']      = '002157';
        $options['wpfeedback_logo']       = WPF_PLUGIN_URL . 'images/Atarim.svg';
        $options['wpfeedback_favicon']    =  WPF_PLUGIN_URL . 'images/atarim_icon.svg';
        $options['wpf_powered_link']      = '';
        $options['wpfeedback_powered_by'] = 'no';
        $parms                            = [];
        foreach ( $options as $key => $value ) {
            array_push( $parms, ['name' => $key,'value' => $value] );
        }
	    update_site_data( $parms );	
        echo 1;
        exit;
    }
}
add_action( 'wp_ajax_wpfeedback_reset_setting', 'wpfeedback_reset_setting' );
add_action( 'wp_ajax_nopriv_wpfeedback_reset_setting', 'wpfeedback_reset_setting' );

/*
 * This function is used to resync the website with the dashboard app. It will clear the "wpf_check_license_date" and "wpf_initial_sync" which in turn will call the Initial sync from function "wpf_license_key_check_item" in wpf_function.php
 *
 * @input NULL
 * @return NULL
 */
if ( ! function_exists( 'wpf_resync_dashboard' ) ) {
    function wpf_resync_dashboard() {
        wpf_security_check();
        delete_option( 'wpf_check_license_date' );
        delete_option( 'wpf_initial_sync' );
        get_notif_sitedata_filterdata();
        echo 1;
        exit;
    }
}
add_action( 'wp_ajax_wpf_resync_dashboard', 'wpf_resync_dashboard' );
add_action( 'wp_ajax_nopriv_wpf_resync_dashboard', 'wpf_resync_dashboard' );


/* load site data => v2.1.0  */
function load_site_metadata() {
    get_notif_sitedata_filterdata();
    die();
}
add_action( 'wp_ajax_load_site_metadata', 'load_site_metadata' );
add_action( 'wp_ajax_nopriv_load_site_metadata', 'load_site_metadata' );

/*
 * This function is used to update the roles to be allowed to use plugin from the Permissions tab.
 *
 * @input Array ( $_POST )
 * @return Boolean
 */
if ( ! function_exists( 'wpf_update_roles' ) ) {
    function wpf_update_roles() {
        wpf_security_check();	
	    $roles                       = $_POST['task_notify_roles'];
        $wpf_allow_guest             = $_POST['wpf_allow_guest'];	
        $options['wpf_site_id']      = get_option( 'wpf_site_id' );
        $options['wpf_allow_guest']  = $wpf_allow_guest;
        $options['wpf_selcted_role'] = $roles;
        $parms                       = [];
        foreach ( $options as $key => $value ) {
            array_push( $parms, ['name' => $key,'value' => $value] );
        }
	    update_site_data( $parms );
        echo 1;
        exit;
    }
}
add_action( 'wp_ajax_wpf_update_roles', 'wpf_update_roles' );
add_action( 'wp_ajax_nopriv_wpf_update_roles', 'wpf_update_roles' );

/*
 * This function is used to set the Email Notification from the Settings tab.
 *
 * @input Array ( $_POST )
 * @return Boolean
 */
if ( ! function_exists( 'wpf_update_notifications' ) ) {
    function wpf_update_notifications() {
        wpf_security_check();	
        $options['wpf_site_id']             = get_option( 'wpf_site_id' );
        $options['wpf_every_new_task']      = isset( $_POST['wpf_every_new_task'] ) ? 'yes' : 'no';
        $options['wpf_every_new_comment']   = isset( $_POST['wpf_every_new_comment'] ) ? 'yes' : 'no';
        $options['wpf_every_new_complete']  = isset( $_POST['wpf_every_new_complete'] ) ? 'yes' : 'no';
        $options['wpf_every_status_change'] = isset( $_POST['wpf_every_status_change'] ) ? 'yes' : 'no';
        $options['wpf_daily_report']        = isset( $_POST['wpf_daily_report'] ) ? 'yes' : 'no';
        $options['wpf_weekly_report']       = isset( $_POST['wpf_weekly_report'] ) ? 'yes' : 'no';
        $options['wpf_auto_daily_report']   = isset( $_POST['wpf_auto_daily_report'] ) ? 'yes' : 'no';
        $options['wpf_auto_weekly_report']  = isset( $_POST['wpf_auto_weekly_report'] ) ? 'yes' : 'no';	
        $parms                              = [];
        foreach ( $options as $key => $value ) {
            array_push( $parms, ['name' => $key,'value' => $value] );
        }
	    update_site_data( $parms );	
        echo 1;
        exit;
    }
}
add_action( 'wp_ajax_wpf_update_notifications', 'wpf_update_notifications' );
add_action( 'wp_ajax_nopriv_wpf_update_notifications', 'wpf_update_notifications' );

/*
 * This function is used to set the option that initial setup of the plugin was done from the backend.
 *
 * @input NULL
 * @return Boolean
 */
if ( ! function_exists( 'wpf_initial_setup_done' ) ) {
    function wpf_initial_setup_done() {
        global $current_user;
        wpf_security_check();
        $user_id = $current_user->ID;
        if ( is_user_logged_in() && $user_id != '' ) {    
            $options['wpf_site_id']        = get_option( 'wpf_site_id' );
            $options['enabled_wpfeedback'] =  'yes';
            $options['wpf_initial_setup']  =  'yes';
            update_user_meta( $user_id, 'wpf_user_type', 'advisor' );
            if ( get_option( 'wpf_website_developer' ) == '' || get_option( 'wpf_website_developer' ) == '0' ) {
                $options['wpf_website_developer'] = $user_id; //set install default user
            }            
            $wpf_pre_website_client = get_option( 'wpf_pre_website_client' ) ? get_option( 'wpf_pre_website_client' ) : 0;
            update_option( 'wpf_website_client', $wpf_pre_website_client );
            $options['wpf_website_client'] = $wpf_pre_website_client;
            $parms                         = [];
            foreach ( $options as $key => $value ) {
                array_push( $parms, ['name' => $key,'value' => $value] );
            }
            update_site_data( $parms );           
            echo 1;
        }
        exit;
    }
}
add_action( 'wp_ajax_wpf_initial_setup_done', 'wpf_initial_setup_done' );
add_action( 'wp_ajax_nopriv_wpf_initial_setup_done', 'wpf_initial_setup_done' );

/*
 * This function is used to delete the task from the website. This function is used from Frontend Pages, Backend Pages, Backend Tasks Center and Graphics Tasks.
 *
 * @input Array ( $_POST )
 * @return Boolean
 */
if ( ! function_exists( 'wpfb_delete_task' ) ) {
    function wpfb_delete_task() {
        wpf_security_check();
        $ids = [];
        foreach ( $_POST['task_info'] as  $value ) {
            array_push( $ids, $value );
        }
        $args = array(
            'task_id' => $ids
        );

        $url         = WPF_CRM_API . 'wp-api/task/delete';
        $sendtocloud = wp_json_encode( $args );
        $response    = wpf_send_remote_post( $url, $sendtocloud );
        if ( $response['status'] == 200 ) {
            echo 1;
        } else {
            echo 0;
        }
        exit;
    }
}
add_action( 'wp_ajax_wpfb_delete_task', 'wpfb_delete_task' );
add_action( 'wp_ajax_nopriv_wpfb_delete_task', 'wpfb_delete_task' );


if ( ! function_exists( 'wpfb_delete_task_image' ) ) {
    function wpfb_delete_task_image() {
        wpf_security_check();
        $task_img_url    = sanitize_url( $_POST['task_img_url'] );
        $newtask_img_url = str_replace( 'https://wpfeedback-image.s3.us-east-2.amazonaws.com/', '', $task_img_url );

        $args = array(
            'comment_id' => sanitize_text_field( $_POST['comment_id'] ),
            'filepath'   => $newtask_img_url,
        );

        $url         = WPF_CRM_API . 'delete-s3-images';
        $sendtocloud = wp_json_encode( $args );
        $response    = wpf_send_remote_post( $url, $sendtocloud );
        if ( $response['status'] == 200 ) {
            echo 1;
        } else {
            echo 0;
        }
        exit;
    }
}
add_action( 'wp_ajax_wpfb_delete_task_image', 'wpfb_delete_task_image' );
add_action( 'wp_ajax_nopriv_wpfb_delete_task_image', 'wpfb_delete_task_image' );

/*
 * This function is used to upload the files for the task. This function is used on Backend Tasks Center.
 *
 * @input Array ( $_POST and $_FILES)
 * @return JSON
 */
if ( ! function_exists( 'wpf_upload_file' ) ) {
    function wpf_upload_file() {
        global $wpdb, $current_user;
        wpf_security_check();
        if ( $current_user->ID == 0 ) {
            $user_id = get_site_data_by_key( 'wpf_website_client' );
        } else {
            $user_id = $current_user->ID;
        }
        $user_name = $current_user->display_name;

	    $wpf_taskid = sanitize_text_field( $_POST['wpf_taskid'] );
        if ( ! empty( $_FILES ) ) {
            foreach ( $_FILES as $file ) {
                if ( is_array( $file ) ) {
                    $wpf_allowed_filetypes = array( "jpg", "jpeg", "png", "gif", "pdf", "doc", "docx", "xlsx", "xls", "mp4", "webm", "mov", "wmv", "avi", "ttf", "txt", "psd", "svg" );
                    $temp_wpf_file_name    = $_FILES["wpf_upload_file"]["name"];
                    $temp_wpf_file_type    = $_FILES["wpf_upload_file"]["type"];
                    $tmp_f                 = explode( ".", $temp_wpf_file_name );
                    $temp_wpf_file_ext     = strtolower( end( $tmp_f ) );
                    $fname                 = explode( ".", $temp_wpf_file_name );
                    $temp_name             = $_FILES['wpf_upload_file']['tmp_name'];
                    $data                  = file_get_contents( $temp_name );
                    if ( $temp_wpf_file_ext == 'xlsx' || $temp_wpf_file_ext == 'xls' || $temp_wpf_file_ext == 'docx' || $temp_wpf_file_ext == 'doc' || $temp_wpf_file_ext == 'avi' || $temp_wpf_file_ext = "psd" ) {
                        $invalid = 0;
                    } else {
                        $invalid = wpf_verify_file_upload( $_SERVER, $data );
                        $invalid = wpf_verify_file_upload_type( $_SERVER, $temp_wpf_file_type );
                    }

                    $file_path = '';
                    if ( $invalid == 0 ) {
                        if ( ! in_array( $temp_wpf_file_ext, $wpf_allowed_filetypes ) ) {
                            $invalid = 1;
                        }
                    }

                    if ( $invalid == 0 ) {
                        $file_type = 0;
                        if ( in_array( $temp_wpf_file_type, array( 'image/jpeg', 'image/png', 'image/gif' ) ) ) {
                            $file_type = 1;
                        } else {
                            $file_type = 2;
                        }

                        $file                    = $_FILES['wpf_upload_file'];
                        $base64_image            = 'data:' . $temp_wpf_file_type . ';base64,' . base64_encode( $data );
                        $args                    = [];
                        $args['task_id']         = $wpf_taskid;
                        $args['comment_content'] = $base64_image;
                        $args['user_id']         = "";
                        $args['wpf_user_id']     = $user_id;
                        $args['file_upload']     = ! empty( $_FILES['wpf_upload_file'] ) ? 1 : 0;
                        $args['file_name']       = str_replace( ' ', '_', trim( $fname[0] ) );
                        $args['type']            = $temp_wpf_file_type;
                        $url                     = WPF_CRM_API . 'wp-api/comment/create';
                        $sendtocloud             = json_encode( $args );
                        $res                     = wpf_send_remote_post( $url, $sendtocloud );

                        if ( $res['status'] == 200 ) {
                            $response = array(
                                'status'     => 1,
                                'type'       => $file_type,
                                'message'    => $res['data']['comment_content'],
                                'comment_id' => $res['data']['id'],
                                'author'     => $user_name,
                            );
                        } else {
                            $response = array(
                                'status'  => 0,
                                'message' => 'invalid'
                            );
                        }
                        echo json_encode( $response );
                        die();
                    } else {
                        $response = array(
                            'status'  => 0,
                            'message' => 'invalid'
                        );
                    }
                }
            }
        }
        echo json_encode( $response );
        die();
    }
}
add_action( 'wp_ajax_wpf_upload_file', 'wpf_upload_file' );
add_action( 'wp_ajax_nopriv_wpf_upload_file', 'wpf_upload_file' );

/*
 * This function is used to get ID of the page by URL. This function is used from Frontend Pages and Graphics Tasks.
 *
 * @input Array ( $_POST )
 * @return JSON
 */
if ( ! function_exists( 'wpf_get_page_id_by_url' ) ) {
    function wpf_get_page_id_by_url() {
        global $wpdb;
        wpf_security_check();
        $siteurl          = get_option( 'siteurl' );
        $response         = array();
        $current_page_url = sanitize_url( $_POST['current_page_url'] );

        if ( substr( $current_page_url, -1 ) == '/' ) {
            $current_page_url = substr( $current_page_url, 0, -1 );
        }

        if ( substr( $siteurl, -1 ) == '/' ) {
            $siteurl = substr( $siteurl, 0, -1 );
        }

        if ( $siteurl == $current_page_url ) {
            $home_page_id = get_option( 'page_on_front' );
            if ( $home_page_id == 0 ) {
                $home_page_id = $blog_id = get_option( 'page_for_posts' );
            }
            $prepared_sql = $wpdb->prepare(
                "SELECT post_title FROM {$wpdb->prefix}posts WHERE ID = %d",
                $home_page_id
            );
            //$query                  = "SELECT `post_title` FROM `{$wpdb->prefix}posts` WHERE `ID` = '" . $home_page_id . "'";
            $page_info              = $wpdb->get_results( $prepared_sql, OBJECT );
            $response['id']         = $home_page_id;
            $response['post_title'] = $page_info[0]->post_title;
        } else {
            $link_array = explode( '/', $current_page_url );
            if ( end( $link_array ) != '' ) {
                $slug = end( $link_array );
            } else {
                $slug = $link_array[count( $link_array ) - 2];
            }
            $prepared_sql = $wpdb->prepare(
                "SELECT ID, post_title FROM {$wpdb->prefix}posts WHERE post_name = %s",
                $slug
            );
            //$query     = "SELECT `ID`,`post_title` FROM `{$wpdb->prefix}posts` WHERE `post_name` = '" . $slug . "'";
            $page_info = $wpdb->get_results( $prepared_sql, OBJECT );
            if ( $page_info ) {
                $response['id']         = $page_info[0]->ID;
                $response['post_title'] = $page_info[0]->post_title;
            } else {
                $response['id']         = 0;
                $response['post_title'] = 0;
            }
        }
        echo wp_json_encode( $response );
        exit;
    }
}
add_action( 'wp_ajax_wpf_get_page_id_by_url', 'wpf_get_page_id_by_url' );
add_action( 'wp_ajax_nopriv_wpf_get_page_id_by_url', 'wpf_get_page_id_by_url' );

/*
 * This function is used to set the normal page element tasks as general task in case if they are orphaned. This function is used from Frontend Pages.
 *
 * @input Array ( $_POST )
 * @return Int
 */
function wpfb_set_general_comment(){
    wpf_security_check();
    $task_ids[0] = sanitize_text_field( $_POST['wpfb_task_id'] ); 
    $args        = array(
        'task_id'     => $task_ids,
        'wpf_site_id' => get_option( 'wpf_site_id' )
    );

    $url         = WPF_CRM_API . 'wp-api/task/update-task-type';
    $sendtocloud = wp_json_encode( $args );
    $response    = wpf_send_remote_post( $url, $sendtocloud );
    echo $wpfb_task_id = sanitize_text_field( $_POST['wpfb_task_id'] );
    exit;
}
add_action( 'wp_ajax_wpfb_set_general_comment', 'wpfb_set_general_comment' );
add_action( 'wp_ajax_nopriv_wpfb_set_general_comment', 'wpfb_set_general_comment' );


/*
 * This function is used to set the general tasks (created by orphaned service) as normal page. This function is used from Frontend Pages.
 *
 * @input Array ( $_POST )
 * @return Int
 */
function wpf_set_task_element(){
    wpf_security_check();
    $wpf_task_ids = $_POST['wpf_task_ids'];
    $task_ids     = $_POST['wpf_task_ids']; 
    $args         = array(
        'task_id'     => $task_ids,
        'wpf_site_id' => get_option( 'wpf_site_id' )
    );

    $url         = WPF_CRM_API . 'wp-api/task/update-task-type';
    $sendtocloud = wp_json_encode( $args );
    $response    = wpf_send_remote_post( $url, $sendtocloud );
    if ( empty( $wpf_task_ids ) ) {
        echo 0;
        exit;
    }
    echo 1;
    exit;
}
add_action( 'wp_ajax_wpf_set_task_element', 'wpf_set_task_element' );
add_action( 'wp_ajax_nopriv_wpf_set_task_element', 'wpf_set_task_element' );

/*
 * This function is used to set change the title of the task. This function from Backend Tasks Center.
 *
 * @input Array ( $_POST )
 * @return JSON
 */
if ( ! function_exists( 'wpf_update_title' ) ) {
    function wpf_update_title() {
        $response = array();
        wpf_security_check();
        $wpf_new_task_title = trim( sanitize_text_field( $_POST['wpf_new_task_title'] ) );
        $wpf_task_id        = sanitize_text_field( $_POST['wpf_task_id'] );
        if ( ! empty( $wpf_new_task_title ) && $wpf_task_id !='' ) {
            $my_post = array(
               'task_id'    =>  $wpf_task_id,
               'task_title' => $wpf_new_task_title
            );

            $sendtocloud = wp_json_encode( $my_post );
            $url         = WPF_CRM_API . 'wp-api/task/title/update';
            $wpf_task_id = wpf_send_remote_post( $url, $sendtocloud );
            if ( $wpf_task_id['status'] == 200 ) {
                $response['wpf_msg']            = 1;
                $response['wpf_new_task_title'] = $wpf_new_task_title;
                $response['wpf_task_id']        = $wpf_task_id;
            } else {
                $response['wpf_msg'] = 0;
            }
        }       
        echo wp_json_encode($response);
        exit;
    }
}
add_action( 'wp_ajax_wpf_update_title', 'wpf_update_title' );
add_action( 'wp_ajax_nopriv_wpf_update_title', 'wpf_update_title' );

/*
 * This function is used to set the tag to the task. This function is used from Frontend Pages, Backend Pages and Backend Tasks Center.
 *
 * @input Array ( $_POST )
 * @return JSON
 */
if ( ! function_exists( 'wpfb_set_task_tag' ) ) {
    function wpfb_set_task_tag() {
        wpf_security_check();
        $response                               = array();
        $task_list_tags_array                   = array();
        $response['wpf_tag_type']               = '';
        $response['wpf_task_tag_name']          = '';
        $response['wpf_task_tag_slug']          = '';
        $wpf_task_tag_info                      = $_POST['wpf_task_tag_info'];
        $st_tag_name                            = str_replace( array( "'", "\"" ), "", $wpf_task_tag_info['wpf_task_tag_name'] );
        $h_tag_name                             = html_entity_decode( $st_tag_name );
        $s_tag_name                             = strip_tags( $h_tag_name );
        $t_tag_name                             = wpf_test_input( $s_tag_name );
        $tag_name                               = sanitize_text_field( $t_tag_name ); 
        $wpf_tag_slug                           = strtolower( trim( preg_replace( '/[^A-Za-z0-9-]/', '-', $tag_name ) ) );
        $wpf_task_tag_info['wpf_task_tag_slug'] = $wpf_tag_slug;
        
	    if ( isset( $_POST['wpf_task_tag_info']['wpf_task_id'] ) && $tag_name != '' ) {
            $post_data = [];
            $post_data['task_id']           = sanitize_text_field( $_POST['wpf_task_tag_info']['wpf_task_id'] );
            $post_data['from_wp']           = 1;
            $post_data['user_id']           = ( is_user_logged_in() ) ? get_current_user_id() : 0;
            $post_data['method']            = 'wpf_tags';
            $post_data['action']            = 'add_tags';
            $post_data['wpf_task_tag_name'] = $tag_name;
            $post_data['wpf_task_tag_slug'] = $wpf_tag_slug;
            $url                            = WPF_CRM_API . 'wp-api/task/update-task-details';
            $sendtocloud                    = wp_json_encode( $post_data );
            $wpf_task_tags_obj              = wpf_send_remote_post( $url, $sendtocloud );

            if ( isset( $wpf_task_tags_obj['code'] ) && $wpf_task_tags_obj['code'] == 401 ) {
                $response['wpf_task_tag_name'] = $tag_name;
                $response['wpf_msg']           = 0;
                $response['wpf_tag_type']      = 'already_exit';
            } else {
                $response['wpf_task_tag_slug'] = $wpf_tag_slug;
                $response['wpf_task_tag_name'] = $tag_name;
                $response['wpf_msg']           = 1;
                $response['wpf_term_id']       = $post_data['task_id'];
                $response['wpf_tag_type']      = 'new';
            }
	    } else {
            $response['wpf_task_tag_name'] = $tag_name;
            $response['wpf_msg']           = 500;
            $response['wpf_tag_type']      = 'invalid_tag';
        }      
        echo wp_json_encode( $response ); 
        exit;
    }
}
add_action( 'wp_ajax_wpfb_set_task_tag', 'wpfb_set_task_tag' );
add_action( 'wp_ajax_nopriv_wpfb_set_task_tag', 'wpfb_set_task_tag' );

/*
 * This function is used to delete the tag from the task. This function is used from Frontend Pages, Backend Pages and Backend Tasks Center.
 *
 * @input Array ( $_POST )
 * @return JSON
 */
if ( ! function_exists( 'wpfb_delete_task_tag' ) ) {
    function wpfb_delete_task_tag() {
        wpf_security_check();
        $response                       = array();
        $task_list_tags_array           = array();
        $wpf_task_tag_info              = $_POST['wpf_task_tag_info'];
        $wpf_task_tag_slug              = $wpf_task_tag_info['wpf_task_tag_slug'];
        $wpf_task_id                    = $wpf_task_tag_info['wpf_task_id']; 
        $wpf_task_tag_name              = $wpf_task_tag_info['wpf_task_tag_name'];
        $post_data                      = [];
        $post_data['task_id']           = $wpf_task_tag_info['wpf_task_id'];
        $post_data['from_wp']           = 1;
        $post_data['method']            = 'wpf_tags';
        $post_data['action']            = 'remove_tag';
        $post_data['wpf_task_tag_name'] = $wpf_task_tag_name;
        $post_data['wpf_task_tag_slug'] = $wpf_task_tag_slug;
        $url                            = WPF_CRM_API . 'wp-api/task/update-task-details';
        $sendtocloud                    = wp_json_encode( $post_data );
        $wpf_task_tags_obj              = wpf_send_remote_post( $url, $sendtocloud );

        if ( $wpf_task_tags_obj['status'] == 1 ) {
                $response['wpf_task_tag_slug'] = $wpf_task_tag_slug;
                $response['wpf_task_tag_name'] = $wpf_task_tag_name;
                $response['wpf_task_id']       = $wpf_task_id;
                $response['wpf_msg']           = 1; 
        } else {
                $response['wpf_msg'] = 0; 
        }        
        echo wp_json_encode( $response ); 
        exit;
    }
}
add_action( 'wp_ajax_wpfb_delete_task_tag', 'wpfb_delete_task_tag' );
add_action( 'wp_ajax_nopriv_wpfb_delete_task_tag', 'wpfb_delete_task_tag' );

/*
 * This function is used to reconnect the general task to the element. This function is used from Frontend Pages and Backend Pages.
 *
 * @input Array ( $_POST )
 * @return Boolean
 */
if ( ! function_exists( 'wpf_reconnect_task' ) ) {
    function wpf_reconnect_task() {
        wpf_security_check();
        $new_reconnect_obj = $_POST['new_reconnect_obj'];
        $post_data         = [
            'wpf_site_id'         => get_option( 'wpf_site_id' ),
            'task_id'             => sanitize_text_field( $new_reconnect_obj['wpf_reconnect_taskid'] ),
            'method'              => 'reconnect_task',
            'task_element_path'   => $new_reconnect_obj['rightArrowParents'],
            'wpfb_task_bubble'    => sanitize_text_field( $new_reconnect_obj['dompath'] ),
            'task_element_height' => sanitize_text_field( $new_reconnect_obj['html_element_height'] ),
            'task_element_width'  => sanitize_text_field( $new_reconnect_obj['html_element_width'] ),
            'type'                => 'element',
            'from_wp'             => 1
        ];
        $url         = WPF_CRM_API . 'wp-api/task/update-task-details';
        $sendtocloud = wp_json_encode( $post_data );
        $response    = wpf_send_remote_post( $url, $sendtocloud );
        if ( $response['status'] == 1 ) {
            echo 1;
        } else {
            echo 0;
        }
        exit();
    }
    add_action( 'wp_ajax_wpf_reconnect_task', 'wpf_reconnect_task' );
    add_action( 'wp_ajax_nopriv_wpf_reconnect_task', 'wpf_reconnect_task' );
}

/*
 * This function is used to do the bulk updates. This function is used from Backend Tasks Center.
 *
 * @input Array ( $_POST )
 * @return JSON
 */
if ( ! function_exists( 'wpf_bulk_update_tasks' ) ) {
    function wpf_bulk_update_tasks() {
        wpf_security_check();
        $wpf_task_ids = [];
        if ( isset( $_POST['wpf_task_ids'] ) ) {
            foreach ( $_POST['wpf_task_ids'] as  $value ) {
                array_push( $wpf_task_ids, $value );
            }
        }
        $wpf_task_priority_attr    = $_POST['wpf_task_priority_attr'];
        $wpf_task_task_status_attr = $_POST['wpf_task_task_status_attr'];
        $response                  = array();
        $task_status_info          = array();
        $task_priority_info        = array();
        if ( $wpf_task_task_status_attr ) {
            $task_info['task_id']     = $wpf_task_ids;
            $task_info['task_status'] = $wpf_task_task_status_attr;
            $task_info['method']      = "status";
        }
        if ( $wpf_task_priority_attr ) {
            $task_info['task_id']       = $wpf_task_ids;
            $task_info['task_priority'] = $wpf_task_priority_attr;
            $task_info['method']        = "priority";
        }

        $url         = WPF_CRM_API . 'wp-api/task/status/bulk/edit';
        $sendtocloud = wp_json_encode( $task_info );
        $res         = wpf_send_remote_post($url,$sendtocloud);
        if ( $res['status'] == 200 ) {
            $response['wpf_msg'] = 1;    
        }    
        echo wp_json_encode( $response );
        exit;
    }
}
add_action( 'wp_ajax_wpf_bulk_update_tasks', 'wpf_bulk_update_tasks' );
add_action( 'wp_ajax_nopriv_wpf_bulk_update_tasks', 'wpf_bulk_update_tasks' );

/*
 * This function is used for make the array
 * of task list to display in sidebar
 */
function process_task_response( $wpfb_tasks ) {
    $current_user_id           = get_current_user_id();
    $comment_attachments_count = 0;
    $comment_attachments_index = 0;
    $comment_attachment_name   = 'Guest';
    $response                  = array();
    $user_atarim_type          = get_user_meta( $current_user_id, 'wpf_user_type', true );
    $wpfb_users_json           = do_shortcode('[wpf_user_list_front]');
    $wpfb_users                = json_decode($wpfb_users_json, true);
    $user_avatar               = array();
    foreach ( $wpfb_users as $key => $value) {
        $user_avatar_url = get_avatar_url( $value['id'], array( 'size'=>42, 'default'=>'404' ) );
        $avatar_headers  = @get_headers( $user_avatar_url );
        if ( ! empty( $avatar_headers ) ) {
            if ( in_array( 'HTTP/1.1 404 Not Found', $avatar_headers ) ) {
                $user_avatar[ $key ] = array(
                    'id'          => $value['id'],
                    'img'         => '',
                    'displayname' => $value['displayname']
                );
            } else {
                $user_avatar[ $key ] = array(
                    'id'          => $value['id'],
                    'img'         => $user_avatar_url,
                    'displayname' => $value['displayname']
                );
            }
        } else {
            $user_avatar[ $key ] = array(
                'id'          => $value['id'],
                'img'         => '',
                'displayname' => $value['displayname']
            );
        }
    }
    if ( ! empty( $wpfb_tasks['data'] ) ) {
	    foreach ( $wpfb_tasks['data'] as $wpfb_task ) {
            if ( $wpfb_task['task']['is_internal'] == '1' ) {
                if ( $user_atarim_type != 'advisor' ) {
                    continue;
                }
            }
            //old tasks
            $task_date                                   = $wpfb_task['task']['created_at'];
            $response[$wpfb_task['task']['id']]['class'] = 'today';
            $task_priority                               = $wpfb_task['task']['task_priority'];
            $task_status                                 = $wpfb_task['task']['task_status'];
            $task_tags                                   = $wpfb_task['task']['tags'];
            $post_title                                  = $wpfb_task['task']['task_title'];
            $temp_tag_counter                            = 0;
            if ( is_array( $task_tags ) && ! empty( $task_tags ) ) {
                foreach ( $task_tags as $task_tag => $task_tags_value ) {
                    $response[$wpfb_task['task']['id']]['wpf_tags'][$temp_tag_counter]['slug'] = $task_tags_value['slug'];
                    $response[$wpfb_task['task']['id']]['wpf_tags'][$temp_tag_counter]['name'] = $task_tags_value['tag'];
                    $response[$wpfb_task['task']['id']]['wpf_tags'][$temp_tag_counter]['id']   = $task_tags_value['id'];
                    $temp_tag_counter++;
                }
            }
            $active_user_data = array();
            $active_users = $wpfb_task['task']['task_notify_users'];
            $active_users = explode( ',', $active_users );
            if ( ! empty( $active_users ) ) {
                foreach ( $active_users as $active_user ) {
                    $active_user_data[ $user_avatar[ $active_user ]['id'] ] = array(
                        'img'         => $user_avatar[ $active_user ]['img'],
                        'displayname' => $user_avatar[ $active_user ]['displayname']
                    );
                }
            }
            $task_id                                                                 = $wpfb_task['task']['id'];
            $response[$wpfb_task['task']['id']]['current_user_id']                   = $current_user_id;
            $response[$wpfb_task['task']['id']]['task_type']                         = $wpfb_task['task']['task_type'];
            $response[$wpfb_task['task']['id']]['site_task_id']                      = $wpfb_task['task']['site_task_id'];
            $response[$wpfb_task['task']['id']]['created_at']                        = $wpfb_task['task']['created_at'];
            $response[$wpfb_task['task']['id']]['updated_at']                        = $wpfb_task['task']['updated_at'];	    
            $created_at                                                              = strtotime( date( "Y-m-d", strtotime( $wpfb_task['task']['created_at'] ) ) );
            $task_time_type                                                          = get_task_time_type( $created_at );
            $response[$wpfb_task['task']['id']]['task_time_type']                    = $task_time_type;
            $response[$wpfb_task['task']['id']]['site_id']                           = $wpfb_task['task']['site_id'];
            $response[$wpfb_task['task']['id']]['graphic_id']                        = $wpfb_task['task']['graphic_id'];
            $response[$wpfb_task['task']['id']]['task_config_author_browser']        = $wpfb_task['task']['task_config_author_browser'];
            $response[$wpfb_task['task']['id']]['task_priority']                     = $task_priority;
            $response[$wpfb_task['task']['id']]['task_status']                       = $task_status;
            $response[$wpfb_task['task']['id']]['task_notify_users']                 = $wpfb_task['task']['task_notify_users'];
            $task_date                                                               = strtotime( $task_date );
            $task_date                                                               = get_date_from_gmt( date( 'Y-m-d H:i:s', $task_date ), 'Y-m-d H:i:s' );
            $task_date1                                                              = date_create( $task_date );
            $wpf_wp_current_timestamp                                                = date( 'Y-m-d H:i:s', current_time( 'timestamp', 0 ) );
            $current_time                                                            = date_create( $wpf_wp_current_timestamp );
            $curr_comment_time                                                       = wpfb_time_difference( $task_date1, $current_time );
            $response[$wpfb_task['task']['id']]['task_time']                         = $curr_comment_time['comment_time'];
            $response[$wpfb_task['task']['id']]['task_config_author_browserVersion'] = $wpfb_task['task']['task_config_author_browserVersion'];
            $response[$wpfb_task['task']['id']]['task_config_author_browserOS']      = $wpfb_task['task']['task_config_author_browserOS'];     
            $response[$wpfb_task['task']['id']]['task_config_author_name']           = get_task_author( $wpfb_task['task'] );
            $response[$wpfb_task['task']['id']]['task_config_author_resX']           = $wpfb_task['task']['task_config_author_resX'];
            $response[$wpfb_task['task']['id']]['task_config_author_resY']           = $wpfb_task['task']['task_config_author_resY'];
            $response[$task_id]['task_title']                                        = $post_title;
            $response[$wpfb_task['task']['id']]['task_page_url']                     = $wpfb_task['task']['task_page_url'];
            $response[$wpfb_task['task']['id']]['task_page_title']                   = $wpfb_task['task']['task_page_title'];
            $response[$wpfb_task['task']['id']]['task_comment_message']              = $wpfb_task['task']['task_comment_message'];
            $response[$wpfb_task['task']['id']]['task_element_path']                 = $wpfb_task['task']['task_element_path'];
            $response[$wpfb_task['task']['id']]['wpfb_task_bubble']                  = $wpfb_task['task']['wpfb_task_bubble'];
            $response[$wpfb_task['task']['id']]['task_element_html']                 = $wpfb_task['task']['task_element_html'];
            $response[$wpfb_task['task']['id']]['task_X']                            = $wpfb_task['task']['task_X'];
            $response[$wpfb_task['task']['id']]['task_Y']                            = $wpfb_task['task']['task_Y'];
            $response[$wpfb_task['task']['id']]['task_elementX']                     = $wpfb_task['task']['task_elementX'];
            $response[$wpfb_task['task']['id']]['task_elementY']                     = $wpfb_task['task']['task_elementY'];
            $response[$wpfb_task['task']['id']]['task_relativeX']                    = $wpfb_task['task']['task_relativeX'];
            $response[$wpfb_task['task']['id']]['task_relativeY']                    = $wpfb_task['task']['task_relativeY'];
            $response[$wpfb_task['task']['id']]['task_element_height']               = $wpfb_task['task']['task_element_height'];
            $response[$wpfb_task['task']['id']]['task_element_width']                = $wpfb_task['task']['task_element_width'];
            $response[$wpfb_task['task']['id']]['task_comment_id']                   = $wpfb_task['task']['wpf_task_id'];
            $response[$wpfb_task['task']['id']]['wpf_task_id']                       = $wpfb_task['task']['wpf_task_id'];
            $response[$wpfb_task['task']['id']]['task_type']                         = $wpfb_task['task']['task_type'];
            $response[$wpfb_task['task']['id']]['is_admin_task']                     = $wpfb_task['task']['is_admin_task'];
            $response[$wpfb_task['task']['id']]['is_internal']                       = $wpfb_task['task']['is_internal'];
            $response[$wpfb_task['task']['id']]['wpf_task_url']                      = $wpfb_task['task']['wpf_task_url'];
            $response[$wpfb_task['task']['id']]['estimated_time']                    = $wpfb_task['task']['estimated_time'];
            $response[$wpfb_task['task']['id']]['spent_time']                        = $wpfb_task['task']['spent_time'];
            $response[$wpfb_task['task']['id']]['notify_wp_feedback_users']          = $wpfb_task['task']['notify_wp_feedback_users'];
            $response[$wpfb_task['task']['id']]['wpf_task_screenshot']               = $wpfb_task['task']['wpf_task_screenshot'];
            $response[$wpfb_task['task']['id']]['task_top']                          = $wpfb_task['task']['task_top'];
            $response[$wpfb_task['task']['id']]['task_left']                         = $wpfb_task['task']['task_left'];
            $response[$wpfb_task['task']['id']]['page_type']                         = $wpfb_task['task']['page_type'];
            $response[$wpfb_task['task']['id']]['wpf_current_screen']                = $wpfb_task['task']['wpf_current_screen'];
	        $comments_info                                                           = $wpfb_task['task']['comments'];
            if ( $comments_info ) {
                $avatar_array = array();
                foreach ( $comments_info as $cmnt_key => $comment ) {
                    $commentid = encrypt_id( $comment['id'] );
                    $response[$wpfb_task['task']['id']]['comments'][$commentid]['wpf_user_id'] = $comment['wpf_user_id'];
                    $avatar_url = '';
                    if ( array_key_exists( $comment['wpf_user_id'], $avatar_array ) ) {
                        $avatar_url = $avatar_array[ $comment['wpf_user_id'] ];
                    } else {
                        $avatar_url = get_avatar_url( $comment['wpf_user_id'], array( 'size'=>42, 'default'=>'404' ) );
                        $headers    = @get_headers( $avatar_url );
                        if ( ! empty( $headers ) ) {
                            if ( in_array( 'HTTP/1.1 404 Not Found', $headers ) ) {
                                $avatar_url = '';
                            } else {
                                $avatar_array[ $comment['wpf_user_id'] ] = $avatar_url;
                            }
                        } else {
                            $avatar_url = '';
                        }
                    }
                    $response[$wpfb_task['task']['id']]['comments'][$commentid]['wpf_user_img'] = $avatar_url;
                    $comment_type = 0;
                    if ( strpos( $comment["comment_content"], 'wpfeedback-image.s3' ) !== false ) {
                        $response[$wpfb_task['task']['id']]['comments'][$commentid]['filetype'] = $comment['comment_type'];
                        if ( $comment['comment_type'] == 'image' || $comment['comment_type'] == 'image/png' || $comment['comment_type'] == 'image/gif' || $comment['comment_type'] == 'image/jpeg' ) {
                            $comment_type = 1;
                        } else {
                            $comment_type = 2;
                        }
                        $response[$wpfb_task['task']['id']]['comments'][$commentid]['message'] = $comment['comment_content'];
                    } else if ( wp_http_validate_url( wp_strip_all_tags( $comment['comment_content'] ) ) && ! strpos( $comment['comment_content'], 'wpfeedback-image.s3' ) ) {
                        $idVideo = wp_strip_all_tags( $comment['comment_content'] );
                        $link    = explode( "?v=", $idVideo );
                        if ( $link[0] == 'https://www.youtube.com/watch' ) {
                            $youtubeUrl = "http://www.youtube.com/oembed?url=$idVideo&format=json";
                            $docHead    = get_headers( $youtubeUrl );
                            if ( substr( $docHead[0], 9, 3 ) !== "404" ) {
                                $comment_type = 3;
                                $response[$wpfb_task['task']['id']]['comments'][$commentid]['message'] = $link[1];
                            } else {
                                $response[$wpfb_task['task']['id']]['comments'][$commentid]['message'] = $comment['comment_content'];
                            }
                        } else {
                            $response[$wpfb_task['task']['id']]['comments'][$commentid]['message'] = $comment['comment_content'];
                        }
                    } else {
                        $response[$wpfb_task['task']['id']]['comments'][$commentid]['message'] = stripslashes( $comment['comment_content'] );
                    }
                    if ( ! empty( $comment['files'] ) ) {
                        $response[$wpfb_task['task']['id']]['comments'][$commentid]['files'] = $comment['files'];
                    } else {
                        $response[$wpfb_task['task']['id']]['comments'][$commentid]['files'] = array();
                    }
                    $response[$wpfb_task['task']['id']]['comments'][$commentid]['comment_type'] = $comment_type;
                    $response[$wpfb_task['task']['id']]['comments'][$commentid]['is_log']       = $comment['is_log'];

                    if ( isset( $comment['author'] ) && ! empty( $comment['author'] ) ) {
                        $response[$wpfb_task['task']['id']]['comments'][$commentid]['author'] = $comment['author']['name'];
                    } elseif ( isset( $comment['wpf_author'] ) && ! empty( $comment['wpf_author'] ) ) {
                        $response[$wpfb_task['task']['id']]['comments'][$commentid]['author'] = $comment['wpf_author']['wpf_display_name'];
                    } else {
                        /* v2.1.0 */
                        $userInfo = get_userdata( $comment['wpf_user_id'] );
                        if ( ! empty( $userInfo ) ) {   
                            $userr = $userInfo->data;
                            $response[$wpfb_task['task']['id']]['comments'][$commentid]['author'] = $userr->display_name;

                            //wpf_author
                            if ( ! is_null( $wpfb_task['task']['comments'][$cmnt_key]['wpf_author'] ) ) {
                                $wpf_author = $wpfb_task['task']['comments'][$cmnt_key]['wpf_author'];
                                $response[$wpfb_task['task']['id']]['comments'][$commentid]['author'] = $wpf_author['name'];
                            } else {
                                if ( empty( $response[$wpfb_task['task']['id']]['comments'][$commentid]['author'] ) ) {
                                    $response[$wpfb_task['task']['id']]['comments'][$commentid]['author'] = "Guest";
                                }
                            }
                        } else {
                            if ( ( ! empty( $comment['response'] ) ) && ( ! empty( $wpfb_task['task']['inbox'] ) ) ) {
                                $response[$wpfb_task['task']['id']]['comments'][$commentid]['response'] = $comment['response'];
                                if ( $comment['response']['delivery_status'] === 'outgoing' ) {
                                    $response[$wpfb_task['task']['id']]['comments'][$commentid]['author'] = $wpfb_task['task']['inbox']['from_name'];
                                } elseif ( $comment['response']['delivery_status'] === 'incoming' ) {        
                                    $from_address = $comment['response']['from_address'];
                                    if ( strpos( $from_address, '<' ) !== false ) {
                                        $parts1       = explode( '<', $from_address );
                                        $parts2       = explode( '>', $parts1[1] );                            
                                        $from_address = $parts2[0];
                                    }
                                    $response[$wpfb_task['task']['id']]['comments'][$commentid]['author'] = $from_address;
                                }
                                // attachments
                                if ( ! empty( $comment['response']['attachments'] ) ) {
                                    $comment_attachment_name   = $response[$wpfb_task['task']['id']]['comments'][$commentid]['author'];
                                    $comment_attachments_count = intval( $comment['response']['attachments'] );
                                } else {
                                    $comment_attachment_name   = 'Guest';
                                    $comment_attachments_count = 0;
                                }
                            } else {
                                if ( ( is_null( $wpfb_task['task']['comments'][$cmnt_key]['wpf_user_id'] ) ) && ( is_null( $wpfb_task['task']['comments'][$cmnt_key]['user_id'] ) ) )  {
                                    $response[$wpfb_task['task']['id']]['comments'][$commentid]['author'] =  "Guest"; 

                                } elseif ( ( !is_null( $wpfb_task['task']['comments'][$cmnt_key]['wpf_user_id'] ) && intval( $wpfb_task['task']['comments'][$cmnt_key]['wpf_user_id'] ) > 0 ) && ( is_null( $wpfb_task['task']['comments'][$cmnt_key]['user_id'] ) || ( intval( is_null( $wpfb_task['task']['comments'][$cmnt_key]['user_id'] ) ) === 0 ) ) ) {                                    
                                    $response[$wpfb_task['task']['id']]['comments'][$commentid]['author'] =  "Deleted User"; 
                                } else {                                    
                                    $response[$wpfb_task['task']['id']]['comments'][$commentid]['author'] =  "Guest";  
                                }

                                $comment_type    = $response[$wpfb_task['task']['id']]['comments'][$commentid]['comment_type'];
                                if ( $comment_type === 1 ) {
                                    if ( $comment_attachments_index < $comment_attachments_count ) {
                                        $response[$wpfb_task['task']['id']]['comments'][$commentid]['author'] = $comment_attachment_name;
                                        $comment_attachments_index++;
                                    }
                                } else {
                                    $comment_attachments_index = 0;
                                }

                                /* => v2.1.1 */
                                foreach ( $wpfb_users as $key=>$wfusers ) {
                                    if ( $key === $comment['wpf_user_id'] ) {
                                                $response[$wpfb_task['task']['id']]['comments'][$commentid]['author'] = $wfusers['displayname'];
                                        break;
                                    }
                                }
                            }
                        }
                    }
            
                    $comment_time                                                                 = $comment['created_at'];
                    $comment_time                                                                 = strtotime( $comment_time );
                    $comment_time                                                                 = get_date_from_gmt( date( 'Y-m-d H:i:s', $comment_time ), 'Y-m-d H:i:s' );
                    $comment_time1                                                                = date_create( $comment_time );
                    $wpf_wp_current_timestamp                                                     = date( 'Y-m-d H:i:s', current_time( 'timestamp', 0 ) );
                    $current_time                                                                 = date_create( $wpf_wp_current_timestamp );
                    $curr_comment_time                                                            = wpfb_time_difference( $comment_time1, $current_time );
                    $response[$wpfb_task['task']['id']]['comments'][$commentid]['time']       = $curr_comment_time['comment_time'];
                    $response[$wpfb_task['task']['id']]['comments'][$commentid]['time_full']  = $curr_comment_time['interval'];
                    $response[$wpfb_task['task']['id']]['comments'][$commentid]['user_id']    = $comment['user_id'];
                    $response[$wpfb_task['task']['id']]['comments'][$commentid]['is_note']    = $comment['is_note'];
                    $response[$wpfb_task['task']['id']]['comments'][$commentid]['is_edited']  = $comment['is_edited'];
                    $response[$wpfb_task['task']['id']]['comments'][$commentid]['is_deleted'] = $comment['is_deleted'];
                    $response[$wpfb_task['task']['id']]['comments'][$commentid]['updated_at'] = date( 'H:i:s M:Y', strtotime( $comment['updated_at'] ) );
                    $response[$wpfb_task['task']['id']]['task_notify_wpf_users']                  = $wpfb_task['task']['task_notify_wpf_users'];
                    $response[$wpfb_task['task']['id']]['task_auth_img']                          = $active_user_data;
                }
	        }

            if ( isset( $_POST['task_id'] ) && $wpfb_tasks['task']['id'] == $_POST['task_id'] ) {
                return $response;
                exit();
            }
	    }
    }
    return $response;
}

if ( ! function_exists( 'validate_gravatar' ) ) {
    function validate_gravatar( $id ) {
        $author_obj = get_user_by( 'id', $id );
        $email      = $user->user_email;
        // Craft a potential url and test its headers
        $hash = md5(strtolower(trim($email)));
    
        $uri = 'http://www.gravatar.com/avatar/' . $hash . '?d=404';
        $headers = @get_headers($uri);
    
        if (!preg_match("|200|", $headers[7])) {
            $has_valid_avatar = false;
        } else {
            $has_valid_avatar = true;
        }
    
        return $has_valid_avatar;
    }
}

/*
 * This function is used to push the media to the wordpress media library. It is called from Atarim dashboard.
 * URL: DOMAIN/wp-admin/admin-ajax.php?action=app_push_to_media
 *
 * @input string
 * @return String
 */
if ( ! function_exists( 'app_push_to_media' ) ) {
    function app_push_to_media() {
        $valid = wpf_api_request_verification();
        if( $valid == 1 ) {
            $input_json = file_get_contents( 'php://input' );
            $input      = json_decode( $input_json );
            require_once( ABSPATH . 'wp-admin/includes/media.php' );
            require_once( ABSPATH . 'wp-admin/includes/file.php' );
            require_once( ABSPATH . 'wp-admin/includes/image.php' );
            $save_url   = $input->url;
            $res        = media_sideload_image( $save_url );

            if ( ! is_wp_error( $res ) ) {
                $response = 200;
            } else {
                $response = 400;
            }
        } else {
            $response = 403;
        }
		echo $response;
		exit;
    }
}
add_action( 'wp_ajax_push_app_push_to_media', 'app_push_to_media' );
add_action( 'wp_ajax_nopriv_app_push_to_media', 'app_push_to_media' );

/*
 * This function is used to push the media to the wordpress media library. It is called from task popover.
 *
 * @input string
 * @return numeric
 */
if ( ! function_exists( 'push_to_media' ) ) {
    function push_to_media() {
        wpf_security_check();
        $save_url = sanitize_url( $_POST['media_link'] );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        $res = media_sideload_image( $save_url );
        if ( ! is_wp_error( $res ) ) {
            echo 1;
        } else {
            echo 2;
        }
        exit;
    }
}
add_action( 'wp_ajax_push_to_media', 'push_to_media' );
add_action( 'wp_ajax_nopriv_push_to_media', 'push_to_media' );

/*
 * This function is get the milestone of the site
 *
 * @input Array ( $_POST )
 * @return JSON
 */
if ( ! function_exists( 'wpf_fetch_milestones' ) ) {
    function wpf_fetch_milestones() {
        wpf_security_check();
        $post_data = [
            'wpf_site_id' => get_option( 'wpf_site_id' ),            
            'from_wp'     => 1
        ];
        $url         = WPF_CRM_API . 'wp-api/milestone/all/';
        $sendtocloud = wp_json_encode( $post_data );
        $milestones  = wpf_send_remote_post( $url, $sendtocloud );
        echo wp_json_encode( $milestones );
        exit();
    }
}
add_action( 'wp_ajax_wpf_fetch_milestones', 'wpf_fetch_milestones' );
add_action( 'wp_ajax_nopriv_wpf_fetch_milestones', 'wpf_fetch_milestones' );


/*
 * This function is to check if internal task is allowed by Pratap
 *
 */
function wpf_is_internal_allowed() {
    if ( is_feature_enabled( 'internal_tasks' ) ) {
        echo 'true';
    } else {
        echo 'false';
    }
    exit();
}
add_action( 'wp_ajax_wpf_is_internal_allowed', 'wpf_is_internal_allowed' );
add_action( 'wp_ajax_nopriv_wpf_is_internal_allowed', 'wpf_is_internal_allowed' );

/*
 * This function is to create Wordpress and EDD user by Pratap
 *
 */
function wpf_create_account() {
    global $current_user;
    $user_id = $current_user->ID;
    $wpf_name  = isset( $_POST['wpf_name'] ) ? sanitize_text_field( $_POST['wpf_name'] ) : '';
    $wpf_email = isset( $_POST['wpf_email'] ) ? sanitize_text_field( $_POST['wpf_email'] ) : '';
    $wpf_pass  = isset( $_POST['wpf_pass'] ) ? sanitize_text_field( $_POST['wpf_pass'] ) : '';
    if ( '' !== $wpf_name && '' !== $wpf_email && '' !== $wpf_pass ) {
        $payload =  array( 
            'name'       => $wpf_name, 
            'email'      => $wpf_email,
            'password'   => $wpf_pass,
            'url'        => base64_encode( WPF_HOME_URL ),
            'site_name'  => get_option( 'blogname' )
        );

        $response = wpf_send_remote_post( 
            WPF_CRM_API . 'wp-api/user/create-free-account',
            wp_json_encode( $payload )
        );
        if ( ! empty( $response['result']['wpf_license'] ) ) {
            $wpf_license_key = '';
            update_option( 'wpf_license', base64_decode( sanitize_text_field( $response['result']['wpf_license'] ) ) );
            if ( isset( $response['result']['wpf_site_id'] ) ) {
                update_option( 'wpf_site_id', base64_decode( sanitize_text_field( $response['result']['wpf_site_id'] ) ), 'no' );
            }
            if ( isset( $response['result']['wpf_license_key'] ) ) {
                update_option( 'wpf_license_key', base64_decode( sanitize_text_field( $response['result']['wpf_license_key'] ) ), 'no' );
                $wpf_license_key = base64_decode( sanitize_text_field( $response['result']['wpf_license_key'] ) );
            }
            update_user_meta( $user_id, 'wpf_user_type', 'advisor' );
            do_action( 'wpf_initial_sync', $wpf_license_key );
            syncUsers();
            update_option("wpf_initial_setup_complete", 'yes');

            echo wp_json_encode( ['status' => true, 'result' => $response['result']] );

        } else if( ! empty( $response['result']['site'] ) ) {
            // added the site id
            add_option( 'wpf_site_id', $response['result']['site']['wpf_site_id'] );
            echo wp_json_encode( $response );
        } else {
            echo wp_json_encode( ['status' => false] );
        }
    }
    exit();
}
add_action( 'wp_ajax_wpf_create_account', 'wpf_create_account' );
add_action( 'wp_ajax_nopriv_wpf_create_account', 'wpf_create_account' );

/*
 * Send invitation Share version 2 ajax by Pratap
 *
 */
function avc_send_invitations() {
    wpf_security_check();
    // Get all name and emails to invite.
    $user_data = isset( $_POST['user_data'] ) ? $_POST['user_data'] : '';
    // Get current page URL
    $current_page = isset( $_POST['current_page'] ) ? sanitize_text_field( $_POST['current_page'] ) : '';
    // Get site title
    $site_title = isset( $_POST['site_title'] ) ? sanitize_text_field( $_POST['site_title'] ) : '';
    // String to genarate random password.
    $chars  = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    // Initialise array to store valid emails and links.
    $success = array();
    // Initialise array to store valid emails.
    $valid_emails = array();
    // Initialise array to store emails for which user creation failed.
    $user_error = array();
    // Initialise variable to check if atleast one email was sent.
    $email_sent = 0;
    // Current logged in user email to set as From email.
    $current_user = wp_get_current_user();
    $from = $current_user->user_email;
    // list of users to send on app side.
    $users = array();
    // User array to send email.
    $sendemail = array();
    // Get site id
    $wpf_site_id = get_option( 'wpf_site_id' );
    // API to send user data on app side.
    $userapi = WPF_CRM_API.'wp-api/wpfuser/user';
    // API to send email info on app side.
    $emailapi = WPF_CRM_API.'v2/share';
    // Default user role set in site.
    $default_role = get_option( 'default_role' );
    // If default role value is missing.
    if ( $default_role == '' ) {
        $default_role = 'subscriber';
    }
    // Temp var in token link to allow cookie.
    $temp_var = '&action=atarim';
    // Check if we have emails to send invites.
    if ( ! empty( $user_data ) ) {
        // Loop through array for each email.
        foreach ( $user_data as $data ) {
            if ( $data[0] != '' ) {
                $email = sanitize_email( $data[0] );
                $email = wp_strip_all_tags( $email );
                // By storing email as array key will make sure that we have unique emails to send invites.
                $valid_emails[$email] = wp_strip_all_tags( sanitize_text_field( $data[1] ) );
            }
        }
        // Loop through array for each email.
        foreach ( $valid_emails as $email => $name ) {
            // Capitalize each word.
            $name = ucwords( $name );
            // Initilise token variable.
            $token = '';
            // Initilise user id variable.
            $user_id = '';
            // Initilise link variable.
            $link = '';
            // First check if user exist with this email.
            $user_exist = get_user_by( 'email', $email );
            if ( ! empty( $user_exist ) ) { // If user exist with the email.
                // Get user id.
                $user_id = $user_exist->ID;
                // Get token using user id.
                $user_token = get_user_meta( $user_id, 'avc_user_token', true );
                $token = $user_token;
                // Create invitation link.
                $link = $current_page . $token . $temp_var;
                // User info to send in email.
                $sendemail[] = array(
                    'name'  => $name,
                    'email' => $email,
                    'link'  => $link
                );
            } else { // If user does not exist with the email.
                // Generate token.
                $token = substr( str_shuffle( $chars ), 0, 10 );
                remove_action( 'user_register', 'wpf_sync_users' );
                // Create user.
                $result = wp_insert_user( array(
                    'user_login'   => $name,
                    'user_pass'    => $token,
                    'user_email'   => $email,
                    'first_name'   => $name,
                    'display_name' => $name,
                ));
                add_action( 'user_register', 'wpf_sync_users' );

                // Check for the error on user creation.
                if ( is_wp_error( $result ) ) { // If error
                    // Save email in array.
                    $err_msg = '';
                    if ( $result->get_error_code() == 'existing_user_login' ) {
                        $err_msg = 'A WordPress user with this username already exists, please choose a different username.';
                    }
                    $user_error[$email] = $err_msg;
                    // Empty token.
                    $token = '';
                } else { // If user created.
                    // Get user id.
                    $user_id = $result;
                    // Add entry in option table to use for verification when user visit the page via link.
                    update_option( 'avc_guest_' . $token,  $user_id, 'no' );
                    // Save token in user meta table.
                    update_user_meta( $user_id, 'avc_user_token', $token );
                    // Set user role
                    update_user_meta( $user_id, 'wpf_user_type', 'king' );
                    // Create invitation link.
                    $link = $current_page . $token . $temp_var;
                    // Create intial to display.
                    $initial = substr( $name, 0, 2 );
                    $initial = strtoupper( $initial );
                    // Store data to display in popup after action is complete.
                    $success[] = array(
                        'email'   => $email,
                        'link'    => $link,
                        'name'    => $name,
                        'initial' => $initial,
                        'id'      => $user_id
                    );
                    // USer info to add in app DB.
                    $users[] = array(
                        'wpf_id'      => $user_id,
                        'wpf_site_id' => $wpf_site_id,
                        'username'    => $name,
                        'wpf_email'   => $email,
                        'first_name'  => $name,
                        'last_name'   => '',
                        'role'        => $default_role,
                    );
                    // User info to send in email.
                    $sendemail[] = array(
                        'name'  => $name,
                        'email' => $email,
                        'link'  => $link
                    );
                }
            }
        }
    }

    // IF send emails.
    if ( ! empty( $sendemail ) ) {
        $emailargs = array(
            'site_title'  => $site_title,
            'wpf_site_id' => $wpf_site_id,
            'from'        => $from,
            'users'       => $sendemail,
        );
        $emailarg = wp_json_encode( $emailargs );
        // Send user email data.
        $response = wpf_send_remote_post( $emailapi, $emailarg );
        // If user email data sent successfully.
        if ( isset( $response['status'] ) && $response['status'] == '1' ) {
            $email_sent = 1;
        }
    }

    // If new users
    if ( ! empty( $users ) ) {
        $userargs = array(
            'users'  => $users,
            'action' => 'add'
        );
        $userarg = wp_json_encode( $userargs );
        // Send user data.
        $response = wpf_send_remote_post( $userapi, $userarg );
        // If user data sent successfully.
        if ( isset( $response['status'] ) && $response['status'] == '200' ) {
            get_notif_sitedata_filterdata();
        }
    }

    $return = array(
        'sent'       => $email_sent,
        'success'    => $success,
        'user_error' => $user_error
    );

    echo wp_json_encode($return);
    exit;
}
add_action( 'wp_ajax_avc_send_invitations', 'avc_send_invitations' );
add_action( 'wp_ajax_nopriv_avc_send_invitations', 'avc_send_invitations' );

/*
 * Delete invitation Share version 2 ajax by Pratap
 *
 */
function avc_delete_invitations() {
    wpf_security_check();
    // Get user id to delete invite.
    $user_id = isset( $_POST['id'] ) ? $_POST['id'] : '';
    // Get site id
    $wpf_site_id = get_option( 'wpf_site_id' );
    // API to send user data on app side.
    $userapi = WPF_CRM_API.'wp-api/wpfuser/user';
    if ( $user_id > 0 ) {
        // USer info to delete from app DB.
        $users[] = array(
            'wpf_id'      => $user_id,
            'wpf_site_id' => $wpf_site_id
        );
        $userargs = array(
            'users'  => $users,
            'action' => 'delete'
        );
        $userarg = wp_json_encode( $userargs );
        // Send user data.
        $response = wpf_send_remote_post( $userapi, $userarg );
        // If user data sent successfully.
        if ( isset( $response['status'] ) && ( $response['status'] == '200' || $response['status'] == '500' ) ) {
            $token = get_user_meta( $user_id, 'avc_user_token', true );
            delete_option( 'avc_guest_' . $token );
            wp_delete_user( $user_id );
            get_notif_sitedata_filterdata();
            echo 'true';
            exit;
        } else {
            echo 'api_failed';
            exit;
        }
    }
    echo 'false';
    exit;
}
add_action( 'wp_ajax_avc_delete_invitations', 'avc_delete_invitations' );
add_action( 'wp_ajax_nopriv_avc_delete_invitations', 'avc_delete_invitations' );

function load_wpfb_pages() {
    wpf_security_check();
    $wpside = isset( $_POST['wpside'] ) ? sanitize_text_field( $_POST['wpside'] ) : 'frontend';
    echo page_tab_content( $wpside );
    exit;
}
add_action( 'wp_ajax_load_wpfb_pages', 'load_wpfb_pages' );
add_action( 'wp_ajax_nopriv_load_wpfb_pages', 'load_wpfb_pages' );

function wpf_add_page() {
    $page_id = $_POST['current_page_id'];
    $page_url = $_POST['current_page_url'];
    $page_title = $_POST['current_page_title'];
    $post_data = array(
        'wpf_site_id'  => get_option( 'wpf_site_id' ),            
        'wpf_page_id' => $page_id,
        'page_url' => $page_url,
        'page_title' => $page_title
    );
    $args = array(
        'body'        => $post_data,
        'method'      => 'POST',
        'data_format' => 'body',
        'timeout'     => 100,
    );
    $url = WPF_CRM_API . 'wp-api/site/page';
    $response = wp_remote_post( $url, $args );
    if( $response['response']['code'] == 200 ) {
        $body = $response['body'];
        $body = json_decode( $body );
        if( ! empty( $body ) ) {
            $page = $body->result;
            $page_id = $page->id;
            $page_url = $page->url;
            $page_title = $page->label;
            $total_tasks = $page->total_tasks;
            $is_approved = $page->is_approved;
            $alterimg = WPF_PLUGIN_URL . 'images/placeholder-image.png';
            $page_screenshot = image_exists_checker( $page->screenshot, $alterimg );
            echo $page_html = page_html( $page_id, $page_url, $page_title, $total_tasks, $is_approved, $page_screenshot );
        }
    } else {
        echo '403';
    }
    exit;
}
add_action( 'wp_ajax_wpf_add_page', 'wpf_add_page' );
add_action( 'wp_ajax_nopriv_wpf_add_page', 'wpf_add_page' );

function wpf_delete_page() {
    $page_id = $_POST['page_id'];
    $post_data = array(
        'wpf_site_id' => get_option( 'wpf_site_id' ),            
        'page_id'     => $page_id,
    );
    $args = array(
        'body'        => $post_data,
        'method'      => 'DELETE',
        'data_format' => 'body',
        'timeout'     => 100,
    );
    $url = WPF_CRM_API . 'wp-api/site/pages';
    $response = wp_remote_post( $url, $args );
    exit;
}
add_action( 'wp_ajax_wpf_delete_page', 'wpf_delete_page' );
add_action( 'wp_ajax_nopriv_wpf_delete_page', 'wpf_delete_page' );

function wpf_delete_file() {
    $file_id = $_POST['file_id'];
    $post_data = array(
        'wpf_site_id'  => get_option( 'wpf_site_id' ),            
        'ids'          => array( $file_id )
    );
    $args = array(
        'body'        => $post_data,
        'method'      => 'POST',
        'data_format' => 'body',
        'timeout'     => 100,
    );
    $url = WPF_CRM_API . 'wp-api/comments/delete-files';
    $response = wp_remote_post( $url, $args );
    exit;
}
add_action( 'wp_ajax_wpf_delete_file', 'wpf_delete_file' );
add_action( 'wp_ajax_nopriv_wpf_delete_file', 'wpf_delete_file' );