<?php

/**
 * Class Videos_model models videos information from the DB
 *
 * @category	Model
 * @author		Călin-Andrei Burloiu
 */
class Videos_model extends CI_Model {
	public $db = NULL;
	
	public function __construct()
	{
		parent::__construct();
		
		if ($this->db === NULL)
		{
			$this->load->library('singleton_db');
			$this->db = $this->singleton_db->connect();
		}
	}
	
	/**
	 * Retrieves a set of videos information which can be used for displaying
	 * that videos as a list with few details.
	 *
	 * @param		int $category_id	DB category ID; pass NULL for all
	 * categories
	 * @param mixed $user an user_id (as int) or an username 
	 * (as string); pass NULL for all users
	 * @param int $offset
	 * @param int $count number of videos to retrieve; if set to TRUE this
	 * method will retrieve the number of videos that satisfy condition
	 * @param string $ordering	control videos ording by these
	 * possibilities:
	 * <ul>
	 *   <li><strong>'hottest':</strong> newest most appreciated first. An
	 *   appreciated video is one which has a bigger
	 *   score = views + likes - dislikes.</li>
	 *   <li><strong>'newest':</strong> newest first.</li>
	 *   <li><strong>'alphabetically':</strong> sort alphabetically.</li>
	 * </ul>
	 * @param bool $unactivated whether to retrieve or not ingested unactivated
	 * videos; typically only administrators should see this kind of assets
	 * @return array a list of videos, each one being an assoc array with:
	 * <ul>
	 *   <li>id, name, title, duration, thumbs_count, default_thumb, views => from DB</li>
	 *   <li>shorted_title => ellipsized title</li>
	 *   <li>video_url => P2P-Tube video URl</li>
	 *   <li>user_id, user_name</li>
	 *   <li>thumbs => thumbnail images' URLs</li>
	 * </ul>
	 */
	public function get_videos_summary($category_id, $user, $offset, $count,
		$ordering = 'hottest', $unactivated = FALSE)
	{
		$this->load->helper('text');
		
		$order_statement = "";
		if ($count !== TRUE)
		{
			// Ordering
			switch ($ordering)
			{
			case 'hottest':
				$order_statement = "ORDER BY date DESC, score DESC, RAND()";
				break;
			case 'newest':
				$order_statement = "ORDER BY date DESC";
				break;
			case 'alphabetically':
				$order_statement = "ORDER BY title";
				break;

			default:
				$order_statement = "";
			}
		}
		
		// Show unactivated videos.
		$cond_unactivated = ($unactivated
				? '(a.activation_code IS NULL OR a.activation_code IS NOT NULL
					AND a.cis_response = '. CIS_RESP_COMPLETION. ')'
				: 'a.activation_code IS NULL');
		
		// Category filtering
		if ($category_id === NULL)
			$cond_category = "1";
		else
		{
			$category_id = intval($category_id);
			$cond_category = "category_id = $category_id";
		}
		
		// User filtering
		if ($user === NULL)
			$cond_user = "1";
		else
		{
			if (is_int($user))
				$cond_user = "v.user_id = $user";
			else if (is_string($user))
				$cond_user = "u.username = '$user'";
		}
		
		if ($count === TRUE)
			$fields = "COUNT(*) count";
		else
			$fields = "v.id, name, title, duration, user_id, u.username, views,
				thumbs_count, default_thumb,
				(views + likes - dislikes) AS score,
				a.activation_code, a.cis_response";
		
		$query = $this->db->query(
			"SELECT $fields
			FROM `videos` v 
				LEFT JOIN `videos_unactivated` a ON (id = a.video_id),
				`users` u
			WHERE v.user_id = u.id AND $cond_category AND $cond_user
				AND $cond_unactivated
			$order_statement
			LIMIT $offset, $count"); 
		
		if ($query->num_rows() > 0)
		{
			if ($count === TRUE)
				return $query->row()->count;
			
			$videos = $query->result_array();
		}
		else
			return array();
		
		foreach ($videos as & $video)
		{
			// P2P-Tube Video URL
			$video['video_url'] = site_url(sprintf("watch/%d/%s",
				$video['id'], $video['name']));
			
			// Thumbnails
			$video['thumbs'] = $this->get_thumbs($video['name'], 
				$video['thumbs_count']);
			$video['json_thumbs'] = json_encode($video['thumbs']);
				
			// Ellipsized title
			//$video['shorted_title'] = ellipsize($video['title'], 45, 0.75);
			$video['shorted_title'] = character_limiter($video['title'], 50);
		}
		
		return $videos;
	}
	
	/**
	 * Returns the number of videos from database from a specific category or
	 * user.
	 * NULL parameters count videos from all categories and / or all users.
	 * 
	 * @param int $category_id
	 * @param mixed $user an user_id (as int) or an username (as string)
	 * @return int number of videos or FALSE if an error occured
	 */
	public function get_videos_count($category_id = NULL, $user = NULL,
			$unactivated = FALSE)
	{
		return $this->get_videos_summary($category_id, $user, 0, TRUE, NULL,
				$unactivated);
	}
	
	/**
	 * Retrieves information about a video.
	 *
	 * If $name does not match with the video's `name` from the DB an error is
	 * marked in the key 'err'. If it's NULL it is ignored.
	 *
	 * @access public
	 * @param string $id	video's `id` column from `videos` DB table
	 * @param string $name	video's `name` column from `videos` DB
	 * table. NULL means there is no name provided.
	 * @return array	an associative list with information about a video
	 * with the following keys:
	 * <ul>
	 *   <li>all columns form DB with some exceptions that are overwritten or new</li>
	 *   <li>content is moved in assets</li>
	 *   <li>assets => list of associative lists where each one represents a</li>
	 * video asset having keys: "src", "res", "par" and "ext". Value of key
	 * "src" is the video torrent formated as
	 * {name}_{format}.{video_ext}.{default_torrent_ext}</li>
	 *   <li>username => user name from `users` table</li>
	 *   <li>category_title => a human-friendly category name</li>
	 *   <li>tags => associative list of "tag => score"</li>
	 *   <li>date => date and time when the video was created</li>
	 *   <li>thumbs => thumbnail images' URLs</li>
	 * </ul>
	 */
	public function get_video($id, $name = NULL)
	{
		$this->load->helper('video');
		$this->load->helper('text');
		
		$query = $this->db->query("SELECT v.*, u.username,
					a.activation_code, a.cis_response
				FROM `videos` v 
					LEFT JOIN `videos_unactivated` a ON (v.id = a.video_id),
					`users` u
				WHERE v.user_id = u.id AND v.id = $id");
		$video = array();
		
		if ($query->num_rows() > 0)
		{
			$video = $query->row_array();
			if ($name !== NULL && $video['name'] != $name)
				$video['err'] = 'INVALID_NAME';
		}
		else
		{
			return FALSE;
		}
		
		// Convert JSON encoded string to arrays.
		$video['assets'] = json_decode($video['formats'], TRUE);
		unset($video['formats']);
		$video['tags'] = json_decode($video['tags'], TRUE);
		asort($video['tags']);
		$video['tags'] = array_reverse($video['tags'], TRUE);
		
		// Sort assets by their megapixels number.
		function access_function($a) { return $a['res']; }
		function assets_cmp($a, $b) 
			{ return megapixels_cmp($a, $b, "access_function"); }
		usort($video['assets'], "assets_cmp");
		
		// Torrents
		$video['url'] = array();
		foreach ($video['assets'] as & $asset)
		{
			$def = substr($asset['res'], strpos($asset['res'], 'x') + 1) . 'p';
			$asset['def'] = $def;
 			$asset['src'] = site_url('data/torrents/'. $video['name'] . '_'
 				. $def . '.'. $asset['ext']
 				. '.'. $this->config->item('default_torrent_ext'));
		}
		
		// Category title
		$categories = $this->config->item('categories');
		$category_name = $categories[ intval($video['category_id']) ];
		$video['category_title'] = $category_name ?
			$this->lang->line("ui_categ_$category_name") : $category_name;
		
		// Thumbnails
		$video['thumbs'] = $this->get_thumbs($video['name'], $video['thumbs_count']);
		
		// Shorted description
		$video['shorted_description'] = character_limiter(
				$video['description'], 128);
		
		return $video;
	}
	
	/**
	 * Adds a new uploaded video to the DB.
	 * 
	 * @param string $name
	 * @param string $title
	 * @param string $description
	 * @param string $tags comma separated tags
	 * @param string $duration video duration formatted [HH:]mm:ss
	 * @param array $formats a dictionary corresponding to `formats` JSON
	 * column from `videos` table
	 * @param int $category_id
	 * @param int $user_id
	 * @param string $uploaded_file the raw video file uploaded by the user
	 * @return mixed returns an activation code on success or FALSE otherwise
	 */
	public function add_video($name, $title, $description, $tags, $duration,
			$formats, $category_id, $user_id, $uploaded_file)
	{
		$this->load->config('content_ingestion');
		
		// Tags.
		$json_tags = array();
		$tok = strtok($tags, ',');
		while ($tok != FALSE)
		{
			$json_tags[trim($tok)] = 0;
			
			$tok = strtok(',');
		}
		$json_tags = json_encode($json_tags);
		
		$json_formats = json_encode($formats);
		
		// Thumbnail images
		$thumbs_count = $this->config->item('thumbs_count');
		$default_thumb = rand(0, $thumbs_count - 1);
		
		$query = $this->db->query("INSERT INTO `videos`
				(name, title, description, duration, formats, category_id,
						user_id, tags, date, thumbs_count, default_thumb)
				VALUES ('$name', '$title', '$description', '$duration',
						'$json_formats', $category_id,
						$user_id, '$json_tags', utc_timestamp(),
						$thumbs_count, $default_thumb)");
		if ($query === FALSE)
			return FALSE;
		
		// Find out the id of the new video added.
		$query = $this->db->query("SELECT id from `videos`
				WHERE name = '$name'");
		if ($query->num_rows() === 0)
			return FALSE;
		$video_id = $query->row()->id;
		
		// Activation code.
		$activation_code = Videos_model::gen_activation_code();
		
		$query = $this->db->query("INSERT INTO `videos_unactivated`
				(video_id, activation_code, uploaded_file)
				VALUES ($video_id, '$activation_code', '$uploaded_file')");
		
		return $activation_code;
	}
	
	/**
	 * Request content_ingest to the CIS in order to start the content
	 * ingestion process.
	 * 
	 * @param string $activation_code
	 * @param string $raw_video_fn uploaded video file name
	 * @param string $name
	 * @param int $raw_video_size uploaded video file size in bytes
	 * @param array $transcode_configs dictionary which must be included in
	 * the JSON data that needs to be sent to CIS
	 * @return mixed return the HTTP content (body) on success and FALSE
	 * otherwise
	 */
	public function send_content_ingestion($activation_code, $raw_video_fn,
			$name, $raw_video_size, $transcode_configs)
	{
		$this->config->load('content_ingestion');
		
		$url = $this->config->item('cis_url') . 'ingest_content';
		$data = array(
			'code'=>$activation_code,
			'raw_video'=>$raw_video_fn,
			'name'=>$name,
			'weight'=>$raw_video_size,
			'transcode_configs'=>$transcode_configs,
			'thumbs'=>$this->config->item('thumbs_count')
		);
		$json_data = json_encode($data);
		
		// Send request to CIS.
		$r = new HttpRequest($url, HttpRequest::METH_POST);
		$r->setBody($json_data);
		try
		{
			$response = $r->send()->getBody();
		}
		catch (HttpException $ex) 
		{
			return FALSE;
		}
		
		return $response;
	}
	
	public function set_cis_response($activation_code,
			$response = CIS_RESP_COMPLETION)
	{
		return $this->db->query("UPDATE `videos_unactivated`
			SET cis_response = $response
			WHERE activation_code = '$activation_code'");
	}
	
	public function send_upload_error_email($activation_code,
			$cis_response = CIS_RESP_INTERNAL_ERROR)
	{
		$query = $this->db->query("SELECT v.title, u.email
			FROM `videos_unactivated` a, `videos` v, `users` u
			WHERE a.activation_code = '$activation_code'
				AND a.video_id = v.id AND v.user_id = u.id");
		
		if ($query->num_rows() > 0)
		{
			$title = $query->row()->title;
			$email = $query->row()->email;
		}
		else
			return FALSE;
		
		$subject = '['. $this->config->item('site_name')
				. '] Upload Error';
		if ($cis_response == CIS_RESP_INTERNAL_ERROR)
		{
			$msg = sprintf($this->lang->line(
					'video_internal_cis_error_email_content'), $title);
		}
		else if ($cis_response == CIS_RESP_UNREACHABLE)
		{
			$msg = sprintf($this->lang->line(
					'video_unreachable_cis_error_email_content'), $title);
		}
		$headers = "From: ". $this->config->item('noreply_email');
		
		return mail($email, $subject, $msg, $headers);
	}
	
	/**
	 * Activates a video by deleting its entry from `videos_unactivated`.
	 * 
	 * @param mixed $code_or_id use type string for activation_code or type
	 * int for video_id
	 * @return boolean TRUE on success, FALSE otherwise
	 */
	public function activate_video($code_or_id)
	{
		if (is_string($code_or_id))
			$query = $this->db->query("SELECT uploaded_file from `videos_unactivated`
				WHERE activation_code = '$code_or_id'");
		else if (is_int($code_or_id))
			$query = $this->db->query("SELECT uploaded_file from `videos_unactivated`
				WHERE video_id = '$code_or_id'");
		else
			return FALSE;
		
		if ($query->num_rows() > 0)
			$uploaded_file = $query->row()->uploaded_file;
		else
			return FALSE;
		
		if (is_string($code_or_id))
			$query = $this->db->query("DELETE FROM `videos_unactivated`
				WHERE activation_code = '$code_or_id'");
		else if (is_int($code_or_id))
			$query = $this->db->query("DELETE FROM `videos_unactivated`
				WHERE video_id = '$code_or_id'");
		else
			return FALSE;
		
		if (!$query)
			return $query;
		
		return unlink("data/upload/$uploaded_file");		
	}
	
	public function get_unactivated_videos()
	{
		$query = $this->db->query("SELECT a.video_id, v.name, a.cis_response
			FROM `videos_unactivated` a, `videos` v
			WHERE a.video_id = v.id AND a.cis_response = "
				. CIS_RESP_COMPLETION);
		
		if ($query->num_rows() > 0)
			return $query->result_array();
		else
			return FALSE;
	}
	
	/**
	 * Retrieves comments for a video.
	 * 
	 * @param int $video_id
	 * @param int $offset
	 * @param int $count
	 * @param string $ordering	control comments ording by these possibilities:
	 * <ul>
	 *   <li><strong>'hottest':</strong> newest most appreciated first. An
	 *   appreciated comment is one which has a bigger
	 *   score = likes - dislikes.</li>
	 *   <li><strong>'newest':</strong> newest first.</li>
	 * </ul>
	 * @return array	an array with comments
	 */
	public function get_video_comments($video_id, $offset, $count,
			$ordering = 'newest')
	{
		$this->load->helper('date');
		$cond_hottest = '';
		
		// Ordering
		switch ($ordering)
		{
		case 'newest':
			$order_statement = "ORDER BY time DESC";
			break;
		case 'hottest':
			$order_statement = "ORDER BY score DESC, time DESC";
			$cond_hottest = "AND c.likes + c.dislikes > 0";
			break;
				
		default:
			$order_statement = "";
		}
		
		$query = $this->db->query(
			"SELECT c.*, u.username, u.time_zone, (c.likes + c.dislikes) AS score
				FROM `videos_comments` c, `users` u
				WHERE c.user_id = u.id AND video_id = $video_id $cond_hottest
				$order_statement
				LIMIT $offset, $count");
		
		if ($query->num_rows() == 0)
			return array();
		
		$comments = $query->result_array();
		
		foreach ($comments as & $comment)
		{
			$comment['local_time'] = human_gmt_to_human_local($comment['time'],
				$comment['time_zone']);
		}
		
		return $comments;
	}
	
	public function get_video_comments_count($video_id)
	{
		$query = $this->db->query(
					"SELECT COUNT(*) count
						FROM `videos_comments`
						WHERE video_id = $video_id");
				
		if ($query->num_rows() == 0)
			return FALSE;
		
		return $query->row()->count;
	}
	
	/**
	 * Insert in DB a comment for a video.
	 * 
	 * @param int $video_id
	 * @param int $user_id
	 * @param string $content
	 */
	public function comment_video($video_id, $user_id, $content)
	{
		// Prepping content.
		$content = substr($content, 0, 512);
		$content = htmlspecialchars($content);
		$content = nl2br($content);
		
		return $query = $this->db->query(
			"INSERT INTO `videos_comments` (video_id, user_id, content, time)
			VALUES ($video_id, $user_id, '$content', UTC_TIMESTAMP())");
	}
	
	/**
	 * Increments views count for a video.
	 * 
	 * @param int $id	DB video id
	 * @return void
	 */
	public function inc_views($id)
	{
		return $this->db->query('UPDATE `videos` '
						. 'SET `views`=`views`+1 '
						. 'WHERE id='. $id); 
	}
	
	public function vote($video_id, $user_id, $like = TRUE)
	{
		if ($like)
		{
			$col = 'likes';
			$action = 'like';
		}
		else
		{
			$col = 'dislikes';
			$action = 'dislike';
		}
		
		$query = $this->db->query("SELECT * FROM `users_actions`
			WHERE user_id = $user_id
				AND target_id = $video_id
				AND target_type = 'video'
				AND action = '$action'
				AND date = CURDATE()");
		// User already voted today
		if ($query->num_rows() > 0)
			return -1;
		
		$this->db->query("UPDATE `videos`
			SET $col = $col + 1
			WHERE id = $video_id");
		
		// Mark this action so that the user cannot repeat it today.
		$this->db->query("INSERT INTO `users_actions`
				(user_id, action, target_type, target_id, date)
			VALUES ( $user_id, '$action', 'video', $video_id, CURDATE() )");
		
		$query = $this->db->query("SELECT $col FROM `videos`
			WHERE id = $video_id");
		
		if ($query->num_rows() === 1)
		{
			$row = $query->row_array();
			return $row[ $col ];
		}
		
		// Error
		return FALSE;
	}
	
	public function vote_comment($comment_id, $user_id, $like = TRUE)
	{
		if ($like)
		{
			$col = 'likes';
			$action = 'like';
		}
		else
		{
			$col = 'dislikes';
			$action = 'dislike';
		}
	
		$query = $this->db->query("SELECT * FROM `users_actions`
				WHERE user_id = $user_id
					AND target_id = $comment_id
					AND target_type = 'vcomment'
					AND action = '$action'
					AND date = CURDATE()");
		// User already voted today
		if ($query->num_rows() > 0)
			return -1;
	
		$this->db->query("UPDATE `videos_comments`
				SET $col = $col + 1
				WHERE id = $comment_id");
	
		// Mark this action so that the user cannot repeat it today.
		$this->db->query("INSERT INTO `users_actions`
					(user_id, action, target_type, target_id, date)
				VALUES ( $user_id, '$action', 'vcomment', $comment_id, CURDATE() )");
	
		$query = $this->db->query("SELECT $col FROM `videos_comments`
				WHERE id = $comment_id");
	
		if ($query->num_rows() === 1)
		{
			$row = $query->row_array();
			return $row[ $col ];
		}
	
		// Error
		return FALSE;
	}
	
	public function get_thumbs($name, $count)
	{
		$thumbs = array();
		
		for ($i=0; $i < $count; $i++)
			$thumbs[] = site_url(sprintf("data/thumbs/%s_t%02d.jpg", $name, $i));
		
		return $thumbs;
	}

	/**
	 * Searches videos in DB based on a search query string and returns an
	 * associative array of results.
	 * If count is zero the function only return the number of results.
	 * @param string $search_query
	 * @param int $offset
	 * @param int $count
	 * @param int $category_id	if NULL, all categories are searched
	 * @return array	an associative array with the same keys as that from
	 * get_videos_summary's result, but with two additional keys: 
	 * description and date.
	 */
	public function search_videos($search_query, $offset = 0, $count = 0, 
									$category_id = NULL)
	{
		$search_query = trim($search_query);
		$search_query = str_replace("'", " ", $search_query);
		
		// Search word fragments.
		// sfc = search fragment condition
		$sfc = "( ";
		// sfr = search fragment relevance
		$sfr = "( ";
		$sep = ' +-*<>()~"';
		$fragm = strtok($search_query, $sep);
		while ($fragm !== FALSE)
		{
			$sfc .= "(title LIKE '%$fragm%'
					OR description LIKE '%$fragm%'
					OR tags LIKE '%$fragm%') OR ";
			
			// Frament relevances are half of boolean relevances such
			// that they will appear at the end of the results.
			$sfr .= "0.25 * (title LIKE '%$fragm%')
					+ 0.1 * (description LIKE '%$fragm%')
					+ 0.15 * (tags LIKE '%$fragm%') + ";
			
			$fragm = strtok($sep);
		}
		$sfc = substr($sfc, 0, -4) . " )";
		$sfr = substr($sfr, 0, -3) . " )";
		
		if (! $this->is_advanced_search_query($search_query))
		{
			$search_cond = "MATCH (title, description, tags)
					AGAINST ('$search_query') OR $sfc";
			$relevance = "( MATCH (title, description, tags)
					AGAINST ('$search_query') + $sfr ) AS relevance";
		}
		// boolean mode
		else
		{
			$against = "AGAINST ('$search_query' IN BOOLEAN MODE)";
			$search_cond = "( MATCH (title, description, tags)
					$against) OR $sfc";
			$relevance = "( 0.5 * (MATCH(title) $against)
					+ 0.3 * (MATCH(tags) $against)
					+ 0.2 * (MATCH(description) $against)
					+ $sfr) AS relevance";
		}
		
		if ($count === 0)
		{
			$selected_columns = "COUNT(*) count";
			$order = "";
			$limit = "";
		}
		else
		{
			// TODO select data, description if details are needed
			$selected_columns = "v.id, name, title, duration, user_id, views,
					thumbs_count, default_thumb, u.username,
					(views + likes - dislikes) AS score, 
					$relevance";
			$order = "ORDER BY relevance DESC, score DESC";
			$limit = "LIMIT $offset, $count";
		}
		
		if ($category_id !== NULL)
			$category_cond = "category_id = '$category_id' AND ";
		else
			$category_cond = "";

		$str_query = "SELECT $selected_columns
			FROM `videos` v, `users` u
			WHERE  v.user_id = u.id AND $category_cond ( $search_cond )
			$order
			$limit";
// 		echo "<p>$str_query</p>";
		$query = $this->db->query($str_query);
		
		if ($query->num_rows() > 0)
		{
			if ($count === 0)
				return $query->row()->count;
			else
				$videos = $query->result_array();
		}
		else
			return NULL;
		
		$this->load->helper('text');
		
		foreach ($videos as & $video)
		{
			// P2P-Tube Video URL
			$video['video_url'] = site_url(sprintf("watch/%d/%s",
				$video['id'], $video['name']));
			
			// Thumbnails
			$video['thumbs'] = $this->get_thumbs($video['name'], 
				$video['thumbs_count']);
			$video['json_thumbs'] = json_encode($video['thumbs']);
				
			// Ellipsized title
			//$video['shorted_title'] = ellipsize($video['title'], 45, 0.75);
			$video['shorted_title'] = character_limiter($video['title'], 50);
			
			// TODO: user information
			$video['user_name'] = 'TODO';
		}
		
		return $videos;
	}
	
	public function decode_search_query($search_query)
	{
		$search_query = urldecode($search_query);
		
		$search_query = str_replace('_AST_', '*', $search_query);
		$search_query = str_replace('_AND_', '+', $search_query);
		$search_query = str_replace('_GT_', '>', $search_query);
		$search_query = str_replace('_LT_', '<', $search_query);
		$search_query = str_replace('_PO_', '(', $search_query);
		$search_query = str_replace('_PC_', ')', $search_query);
		$search_query = str_replace('_LOW_', '~', $search_query);
		$search_query = str_replace('_QUO_', '"', $search_query);
		
		return $search_query;
	}
	
	public function encode_search_query($search_query)
	{
		$search_query = str_replace('*', '_AST_', $search_query);
		$search_query = str_replace('+', '_AND_', $search_query);
		$search_query = str_replace('>', '_GT_', $search_query);
		$search_query = str_replace('<', '_LT_', $search_query);
		$search_query = str_replace('(', '_PO_', $search_query);
		$search_query = str_replace(')', '_PC_', $search_query);
		$search_query = str_replace('~', '_LOW_', $search_query);
		$search_query = str_replace('"', '_QUO_', $search_query);
		
		$search_query = urlencode($search_query);
	
		return $search_query;
	}
	
	/**
	 * Return TRUE if it contains any special caracter from an advanced search
	 * query.
	 * @param string $search_query
	 * @return boolean
	 */
	public function is_advanced_search_query($search_query)
	{
		return (preg_match('/\*|\+|\-|>|\<|\(|\)|~|"/', $search_query) == 0
			? FALSE : TRUE);
	}
	
	public static function gen_activation_code()
	{
		$ci =& get_instance();
		
		$activation_code = substr(
			sha1($ci->config->item('encryption_key')
				. mt_rand()),
			0,
			16);
		
		return $activation_code;
	}
}

/* End of file videos_model.php */
/* Location: ./application/models/videos_model.php */
