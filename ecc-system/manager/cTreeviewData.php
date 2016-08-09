<?php
require_once('SqlHelper.php');

class TreeviewData {
	
	private $dbms = false;
	
	private $showOnlyPersonal = false;
	private $showOnlyDontHave = false;
	
	private $sqlFields = '
		md.crc32 as md_crc32,
		md.id as md_id,
		md.eccident as md_eccident,
		md.name as md_name,
		md.info as md_info,
		md.info_id as md_info_id,
		md.running as md_running,
		md.bugs as md_bugs,
		md.trainer as md_trainer,
		md.intro as md_intro,
		md.usermod as md_usermod,
		md.freeware as md_freeware,
		md.multiplayer as md_multiplayer,
		md.netplay as md_netplay,
		md.year as md_year,
		md.usk as md_usk,
		md.rating as md_rating,
		md.category as md_category,
		md.creator as md_creator,
		md.publisher as md_publisher,
		md.storage as md_storage,
		md.region as md_region,
		md.cdate as md_cdate,
		md.uexport as md_uexport,
		fd.id as id,
		fd.title as title,
		fd.path as path,
		fd.path_pack as path_pack,
		fd.crc32 as crc32,
		fd.md5 as md5,
		fd.size as size,
		fd.eccident as fd_eccident,
		fd.launchtime as fd_launchtime,
		fd.launchcnt as fd_launchcnt,
		fd.mdata as fd_mdata
	';
	
	// called by FACTORY
	public function setDbms($dbmsObject){
		$this->dbms = $dbmsObject;
	}
	
	public function showOnlyPersonal($state){
		$this->showOnlyPersonal = $state;
	}
	public function showOnlyDontHave($state){
		$this->showOnlyDontHave = $state;
	}	
	
	/* ------------------------------------------------------------------------
	* VERSION TO GET ALSO META-DATA, IF THERE IS NO FOUND GAME
	* -------------------------------------------------------------------------
	*/
	public function get_file_data_TEST_META(
		$extension,
		$like=false,
		$limit=array(),
		$return_count=true,
		$orderBy="",
		$language=false,
		$category=false,
		$search_ext=false,
		$onlyFiles=true,
		$hideDup=false,
		$hideMetaless=false,
		$searchRating = false,
		$randomGame = false,
		$updateCategories = false
	)
	{
		$snip_where = array();
		$sqlOrderBy = array();
		
		// freeform search like
		if ($like) $snip_where[] = $like;

		// Show/hide doublettes
		if ($hideDup) $snip_where[] = "fd.duplicate is null";
		
		// show/hide missing roms
		if (!$onlyFiles && !$this->showOnlyDontHave){
			if ($extension) $snip_where[] = "fd.eccident='".sqlite_escape_string($extension)."'";
		}
		else{
			if ($extension) $snip_where[] = "md.eccident='".sqlite_escape_string($extension)."'";
		}
		
		if ($searchRating) {
			$snip_where[] = "md.rating<=".(int)$searchRating."";
			$rateOrder = ($orderBy == 'DESC') ? 'ASC' : 'DESC';
			$sqlOrderBy[] = "md.rating ".$rateOrder."";
		}
		
		if ($category) $snip_where[] = "md.category=".$category."";
		if ($esearch = SqlHelper::createSqlExtSearch($search_ext)) $snip_where[] = $esearch;
		if ($language) $snip_where[] = "mdl.lang_id='".$language."'";
		if ($hideMetaless) $snip_where[] = "md.id IS NULL";
		
		$snip_join = array();
		
		if ($this->showOnlyDontHave){
			$snip_where[] = "fd.id IS NULL";
			$snip_join[] = "mdata AS md left join fdata AS fd on (md.eccident=fd.eccident and md.crc32=fd.crc32)";
			$sqlOrderBy[] = 'md.name '.$orderBy;
		}
		else{
			if ($this->showOnlyPersonal) $snip_join[] = "udata AS ud inner join fdata AS fd on (ud.eccident=fd.eccident and ud.crc32=fd.crc32) left join mdata AS md on (fd.eccident=md.eccident and fd.crc32=md.crc32)";
			elseif (!$onlyFiles) $snip_join[] = "fdata AS fd left join mdata AS md on (fd.eccident=md.eccident and fd.crc32=md.crc32)";
			else $snip_join[] = "mdata AS md left join fdata AS fd on (md.eccident=fd.eccident and md.crc32=fd.crc32)";
			$sqlOrderBy[] = 'UPPER(coalesce(md.name, fd.title)) COLLATE NOCASE '.$orderBy;			
		}
		
		if ($language) $snip_join[] = "left join mdata_language AS mdl on md.id=mdl.mdata_id";

		if ($randomGame) {
			$sqlOrderBy = array('random(*)');
			$limit = array(0,1);
		}
		
		# create sql snipplets
		$snipSqlWhere = SqlHelper::createSqlWhere($snip_where);
		$snipSqlJoin = SqlHelper::createSqlJoin($snip_join);
		$snipSqlOrderBy = SqlHelper::createSqlOrder($sqlOrderBy);
		$snipSqlLimit = SqlHelper::createSqlLimit($limit);
		
		# create sql
		$q = "
			SELECT
			".$this->sqlFields."
			FROM
			".$snipSqlJoin."
			WHERE
			".$snipSqlWhere."
			".$snipSqlOrderBy."
			".$snipSqlLimit."
		";
		#print $q;
		$hdl = $this->dbms->query($q);
		$ret = array();
		while($res = $hdl->fetch(SQLITE_ASSOC)) {
			$ret['data'][$res['id']."|".$res['md_id']] = $res;
			$ret['data'][$res['id']."|".$res['md_id']]['composite_id'] = $res['id']."|".$res['md_id'];
		}
		if ($return_count===true) {
			$q = "SELECT count(*) FROM ".$snipSqlJoin." WHERE ".$snipSqlWhere."";
			#print $q."\n";
			$hdl = $this->dbms->query($q);
			$ret['count'] = $hdl->fetchSingle();
		}
				
		if ($updateCategories){
			$eccidentSql = ($extension) ? "fd.eccident='".sqlite_escape_string($extension)."'" : '1';
			$q = "SELECT md.category, count(*) AS cnt FROM ".$snipSqlJoin." WHERE ".$eccidentSql." GROUP BY md.category ORDER BY cnt DESC";
			#print $q."\n";
			$hdl = $this->dbms->query($q);
			while($res = $hdl->fetch(SQLITE_ASSOC)) {
				$ret['cat'][$res['md.category']] = $res['cnt'];
			}
		}
		return $ret;
	}
	
	/* ------------------------------------------------------------------------
	*
	*/
	public function get_bookmarks(
		$extension,
		$like=false,
		$limit=array(),
		$return_count=true,
		$orderBy="",
		$language=false,
		$category=false,
		$search_ext=false,
		$onlyFiles=true,
		$hideDup=false,
		$hideMetaless=false,
		$searchRating = false
	)
	{
		$snip_where = array();
		$sqlOrderBy = array();
		
		// languages selection from dropdown
		$snip_where[] = "fd.duplicate IS NULL";

		// show/hide missing roms
		if (!$onlyFiles) if ($extension) $snip_where[] = "fd.eccident='".sqlite_escape_string($extension)."'";
		else if ($extension) $snip_where[] = "md.eccident='".sqlite_escape_string($extension)."'";
		
		if ($searchRating) {
			$snip_where[] = "md.rating<=".(int)$searchRating."";
			$rateOrder = ($orderBy == 'DESC') ? 'ASC' : 'DESC';
			$sqlOrderBy[] = "md.rating ".$rateOrder."";
		}

		if ($like) $snip_where[] = $like;
		if ($hideMetaless) $snip_where[] = "md.id IS NULL";
		
		#if ($esearch) $snip_where[] = SqlHelper::createSqlExtSearch($search_ext);
		if ($esearch = SqlHelper::createSqlExtSearch($search_ext)) $snip_where[] = $esearch;
		if ($category) $snip_where[] = "md.category=".$category;
		if ($language) $snip_where[] = "mdl.lang_id='".$language."'";

		$snip_join = array();
		if ($language) $snip_join[] = "left join mdata_language AS mdl on md.id=mdl.mdata_id";
		
		$sqlOrderBy[] = "UPPER(coalesce(md.name, fd.title)) COLLATE NOCASE ".$orderBy;
		
		# create sql snipplets
		$snipSqlWhere = SqlHelper::createSqlWhere($snip_where);
		$snipSqlJoin = SqlHelper::createSqlJoin($snip_join);
		$snipSqlOrderBy = SqlHelper::createSqlOrder($sqlOrderBy);
		$snipSqlLimit = SqlHelper::createSqlLimit($limit);
		
		# create sql
		$q = "
			SELECT
			".$this->sqlFields."
			FROM
			fdata_bookmarks as b
			left join fdata AS fd on b.file_id=fd.id
			left join mdata AS md on fd.crc32=md.crc32
			".$snipSqlJoin."
			WHERE
			".$snipSqlWhere."
			".$snipSqlOrderBy."
			".$snipSqlLimit."
		";
		
		$hdl = $this->dbms->query($q);
		$ret = array();
		while($res = $hdl->fetch(SQLITE_ASSOC)) {
			$ret['data'][$res['id']."|".$res['md_id']] = $res;
			$ret['data'][$res['id']."|".$res['md_id']]['composite_id'] = $res['id']."|".$res['md_id'];
		}
		if ($return_count===true) {
			$q = "
				SELECT
				count(*)
				FROM
				fdata_bookmarks as b
				left join fdata AS fd on b.file_id=fd.id
				left join mdata AS md on fd.crc32=md.crc32
				".$snipSqlJoin."
				WHERE
				".$snipSqlWhere."
			";
			$hdl = $this->dbms->query($q);
			$ret['count'] = $hdl->fetchSingle();
		}
		return $ret;
	}
	
	/* ------------------------------------------------------------------------
	*
	*/
	public function get_last_launched(
		$extension,
		$like=false,
		$limit=array(),
		$return_count=true,
		$orderBy="",
		$language=false,
		$category=false,
		$search_ext=false,
		$onlyFiles=true,
		$hideDup=false,
		$hideMetaless=false,
		$searchRating = false
	)
	{
		// order by must be reverse! :-(
		$orderBy = ($orderBy=='DESC') ? 'ASC' : 'DESC';
		
		// INIT WHERE SNIPPLET		
		$snip_where = array();
		$sqlOrderBy = array();
		
		$snip_where[] = "fd.duplicate IS NULL";
		if ($like) $snip_where[] = $like;
		if ($extension) $snip_where[] = "fd.eccident='".sqlite_escape_string($extension)."'";
		if ($category) $snip_where[] = "md.category=".$category;
		
		if ($searchRating) {
			$snip_where[] = "md.rating<=".(int)$searchRating."";
			$sqlOrderBy[] = "md.rating ".$orderBy."";
		}
		
		if ($language) $snip_where[] = "mdl.lang_id='".$language."'";
		if ($hideMetaless) $snip_where[] = "md.id IS NULL";
		if ($esearch = SqlHelper::createSqlExtSearch($search_ext)) $snip_where[] = $esearch;
		$snip_where[] = 'launchtime != ""';
		
		$snip_join = array();
		if ($language) $snip_join[] = "left join mdata_language AS mdl on md.id=mdl.mdata_id";
		
		$sqlOrderBy[] = "launchtime ".$orderBy;
		
		# create sql snipplets
		$snipSqlWhere = SqlHelper::createSqlWhere($snip_where);
		$snipSqlJoin = SqlHelper::createSqlJoin($snip_join);
		$snipSqlOrderBy = SqlHelper::createSqlOrder($sqlOrderBy);
		$snipSqlLimit = SqlHelper::createSqlLimit($limit);
		
		# create sql
		$q = "
			SELECT
			".$this->sqlFields."
			FROM
			fdata AS fd
			left join mdata AS md on (fd.eccident=md.eccident AND fd.crc32=md.crc32)
			".$snipSqlJoin."
			WHERE
			".$snipSqlWhere."
			".$snipSqlOrderBy."
			".$snipSqlLimit."
		";

		$hdl = $this->dbms->query($q);
		$ret = array();
		while($res = $hdl->fetch(SQLITE_ASSOC)) {
			$ret['data'][$res['id']."|".$res['md_id']] = $res;
			$ret['data'][$res['id']."|".$res['md_id']]['composite_id'] = $res['id']."|".$res['md_id'];
		}
		
		if ($return_count===true) {
			$q = "
				SELECT
				count(*)
				FROM
				fdata AS fd
				left join mdata AS md on fd.crc32=md.crc32 and fd.eccident=md.eccident
				".$snipSqlJoin."
				WHERE
				".$snipSqlWhere."
			";
			$hdl = $this->dbms->query($q);
			$ret['count'] = $hdl->fetchSingle();
		}
		return $ret;
	}
	
	/**
	 * Enter description here...
	 *
	 * @param unknown_type $mdataId
	 * @param unknown_type $rating
	 * @return unknown
	 */
	public function addRatingByMdataId($mdataId, $rating)
	{
		if (!$mdataId || $rating > 6) return false;
		$q = "UPDATE mdata set rating = ".(int)$rating.", cdate = ".time().", uexport = NULL WHERE id = ".(int)$mdataId."";
		$this->dbms->query($q);
		return true;
	}

	/**
	 * Enter description here...
	 *
	 * @param unknown_type $eccIdent
	 * @return unknown
	 */
	public function unsetRatingsByEccident($eccIdent) {
		$sqlWhere = ($eccIdent) ? " WHERE eccident = '".sqlite_escape_string($eccIdent)."'" : '';
		$q = "UPDATE mdata SET rating = NULL, cdate = ".time().", uexport = NULL ".$sqlWhere."";
		$this->dbms->query($q);
		return true;
	}
	
	/* ------------------------------------------------------------------------
	*
	*/
	public function add_bookmark_by_id($id)
	{
		if (!$id) return false;
		
		// is bookmark in db
		$q = "select file_id from fdata_bookmarks where file_id = ".(int)$id." ";
		$hdl = $this->dbms->query($q);
		if ($hdl->fetchSingle()) return false;
		
		// new bookmark
		$q = "INSERT INTO fdata_bookmarks (file_id) VALUES (".(int)$id.")";
		$hdl = $this->dbms->query($q);
	}
	
	/* ------------------------------------------------------------------------
	*
	*/
	public function remove_bookmark_by_id($id) {
		if ($id) {
			$q = '
				DELETE FROM
				fdata_bookmarks
				WHERE
				file_id = '.(int)$id.'
			';
			#print $q."\n";
			$hdl = $this->dbms->query($q);
		}
	}
	
	/* ------------------------------------------------------------------------
	*
	*/
	public function remove_bookmark_all() {
		$q = '
			DELETE FROM
			fdata_bookmarks
		';
		$hdl = $this->dbms->query($q);
	}

	public function get_duplicates_all($eccident, $remove = false) {
		
		$snip_where = array();
		if ($eccident) $snip_where[] = "eccident='".sqlite_escape_string($eccident)."'";
		$snip_where[] = "duplicate=1";
		
		#$sql_snip = implode(" AND ", $snip_where);
		$sql_snip = SqlHelper::createSqlWhere($snip_where);
		
		$removeString = ($remove) ? 'and removed (database only)' : 'your';
		$msg = LOGGER::add('romparse', "Find $removeString duplicate roms\r\nPlatform: $eccident", 1);
		
		$q = "
			SELECT
			eccident,
			count(*) as cnt
			FROM
			fdata
			WHERE
			".$sql_snip."
			GROUP BY
			eccident
			ORDER BY
			eccident";
		$hdl = $this->dbms->query($q);
		$out = array();
		$msgRoms = '';
		while($res = $hdl->fetch(SQLITE_ASSOC)) {
				
			$msgRoms .= LOGGER::add('romparse', $res['eccident']." (".$res['cnt'].")");
			
			$log = array();
			$q2 = "select crc32 from fdata where eccident = '".$res['eccident']."' AND duplicate=1 ORDER BY crc32";
			$hdl2 = $this->dbms->query($q2);
			$lastEccident = false;
			while($res2 = $hdl2->fetch(SQLITE_ASSOC)) {

				$msgRoms .= LOGGER::add('romparse', $res2['crc32']);
				$msgRoms .= LOGGER::add('romparse', "STATE\tCRC32\tTITLE\tPATH");
				
				$q3 = "SELECT crc32, title, path, duplicate FROM fdata WHERE eccident='".sqlite_escape_string($res['eccident'])."' AND crc32='".sqlite_escape_string($res2['crc32'])."' ORDER BY duplicate, title ASC";
				$hdl3 = $this->dbms->query($q3);
				while($res3 = $hdl3->fetch(SQLITE_ASSOC)) {
					$state = ($res3['duplicate']) ? '-' : '=';
					$msgRoms .= LOGGER::add('romparse', $state."\t".$res3['crc32']."\t".$res3['title']."\t".$res3['path']);
				}
			}
		}
		$msg .= ($msgRoms) ? $msgRoms : 'congratulation - no duplicates found! :-)' ;
		
		if ($remove) {
			$q = "DELETE FROM fdata WHERE ".$sql_snip."";
			$hdl = $this->dbms->query($q);
		}
		
		return $msg;
	}
	
	/* ------------------------------------------------------------------------
	*
	*/
	public function remove_media_from_fdata($id, $eccident, $crc32) {
		if (!$id) return false;
		
		$q = '
			DELETE FROM
			fdata
			WHERE
			id = '.(int)$id.'
		';
		#print $q."\n";
		$hdl = $this->dbms->query($q);
		
		$duplicates = $this->get_duplicates($eccident, $crc32);
		if (!count($duplicates)) return true;
		
		if (!in_array('', $duplicates)) {
			$this->update_duplicate(key($duplicates));
		}
		
		// remove bookmarks also
		$this->remove_bookmark_by_id($id);
		
		return true;
	}
	
	public function get_duplicates($eccident, $crc32) {
		$q = "
			SELECT
			*
			FROM
			fdata
			WHERE
			eccident='".sqlite_escape_string($eccident)."' AND
			crc32='".sqlite_escape_string($crc32)."'
		";
		#print $q."\n";
		$hdl = $this->dbms->query($q);
		$out = array();
		while($res = $hdl->fetch(SQLITE_ASSOC)) {
			$out[$res['id']] = $res['duplicate'];
		}
		return $out;
	}
	
	public function update_duplicate($id) {
		$q = "
			UPDATE
			fdata
			SET
			duplicate = NULL
			WHERE
			id = ".$id."
		";
		#print $q."\n";
		$hdl = $this->dbms->query($q);
	}
	
	public function remove_media_duplicates($eccident, $crc32) {
		$q = "
			DELETE FROM
			fdata
			WHERE
			eccident='".sqlite_escape_string($eccident)."' AND
			crc32='".sqlite_escape_string($crc32)."'
		";
		#print $q."\n";
		$hdl = $this->dbms->query($q);
	}
	
	/* ------------------------------------------------------------------------
	*
	*/
	public function update_file_info($data, $modified=false) {
		
		//$modified_snip = ($modified) ? ", cdate = '".time()."'" : "";
		
		$q = "
			UPDATE
			mdata
			SET
			name = '".sqlite_escape_string($data['name'])."',
			info = '".sqlite_escape_string($data['info'])."',
			info_id = '".sqlite_escape_string($data['info_id'])."',
			running = ".$data['running'].",
			bugs = ".$data['bugs'].",
			trainer = ".$data['trainer'].",
			intro = ".$data['intro'].",
			usermod = ".$data['usermod'].",
			multiplayer = ".$data['multiplayer'].",
			netplay = ".$data['netplay'].",
			freeware = ".$data['freeware'].",
			year = '".sqlite_escape_string($data['year'])."',
			usk = '".sqlite_escape_string($data['usk'])."',
			category = ".$data['category'].",
			creator = '".sqlite_escape_string($data['creator'])."',
			publisher = '".sqlite_escape_string($data['publisher'])."',
			storage = ".sqlite_escape_string($data['storage']).",
			cdate = ".time().",
			uexport = NULL
			WHERE
			id = ".$data['id']."
		";
		#print $q."\n";
		$hdl = $this->dbms->query($q);
	}
	
	public function saveMetaData($inputData) {
		
		$data = array();
		$data['running'] = ($inputData['md_running']);
		$data['bugs'] = ($inputData['md_bugs']);
		$data['trainer'] = ($inputData['md_trainer']);
		$data['intro'] = ($inputData['md_intro']);
		$data['usermod'] = ($inputData['md_usermod']);
		$data['multiplayer'] = ($inputData['md_multiplayer']);
		$data['netplay'] = ($inputData['md_netplay']);
		$data['freeware'] = ($inputData['md_freeware']);
		$data['category'] = $inputData['md_category'];
		$data['cdate'] = time();
		
		foreach ($data as $key => $value) {
			if (!isset($data[$key])) $data[$key] = 'NULL';
		}

		$data['id'] = $inputData['md_id'];
		$data['eccident'] = strtolower($inputData['fd_eccident']);
		$data['crc32'] = $inputData['crc32'];
		$path = ($inputData['path_pack']) ? $inputData['path_pack'] : $inputData['path'];
		$data['extension'] = strtolower(".".FACTORY::get('manager/FileIO')->get_ext_form_file($path));
		$data['name'] = (trim($inputData['md_name'])) ? $inputData['md_name'] : FACTORY::get('manager/FileIO')->get_plain_filename($path);
		
		$data['info'] = $inputData['md_info'];
		$data['usk'] = $inputData['md_usk'];
		$data['info_id'] = $inputData['md_info_id'];
		$data['year'] = $inputData['md_year'];
		$data['creator'] = $inputData['md_creator'];
		$data['publisher'] = $inputData['md_publisher'];

		$data['storage'] = ($inputData['md_storage'] === null) ? $inputData['md_storage'] = 'NULL' : $inputData['md_storage'] ;		
		
		if ($inputData['md_id']) {
			$this->update_file_info($data, false);
		}
		else {
			return $this->insert_file_info($data);
		}
	}

	private function updateMetaData($id, $data) {
	}
	private function insertMetaData() {
	}
	
	/* ------------------------------------------------------------------------
	*
	*/
	public function insert_file_info($data) {
		
		$q = "
			INSERT INTO
			mdata
			(
				eccident,
				name,
				crc32,
				extension,
				info,
				info_id,
				running,
				bugs,
				trainer,
				intro,
				usermod,
				freeware,
				multiplayer,
				netplay,
				year,
				usk,
				category,
				creator,
				publisher,
				storage,
				cdate
			)
			VALUES
			(
				'".sqlite_escape_string($data['eccident'])."',
				'".sqlite_escape_string($data['name'])."',
				'".sqlite_escape_string($data['crc32'])."',
				'".sqlite_escape_string($data['extension'])."',
				'".sqlite_escape_string($data['info'])."',
				'".sqlite_escape_string($data['info_id'])."',
				".$data['running'].",
				".$data['bugs'].",
				".$data['trainer'].",
				".$data['intro'].",
				".$data['usermod'].",
				".$data['freeware'].",
				".$data['multiplayer'].",
				".$data['netplay'].",
				'".sqlite_escape_string($data['year'])."',
				'".sqlite_escape_string($data['usk'])."',
				".$data['category'].",
				'".sqlite_escape_string($data['creator'])."',
				'".sqlite_escape_string($data['publisher'])."',
				".$data['storage'].",
				".time()."
			)
		";
		#print $q."\n";
		$hdl = $this->dbms->query($q);
		return $this->dbms->lastInsertRowid();
	}
	
	/* ------------------------------------------------------------------------
	*
	*/
	public function save_language($data) {
		$q = "DELETE FROM mdata_language WHERE mdata_id=".$data['id'];
		$hdl = $this->dbms->query($q);
		foreach ($data['languages'] as $lang_ident => $void) {
			$q = "INSERT INTO mdata_language ( mdata_id, lang_id) VALUES ('".$data['id']."', '".sqlite_escape_string($lang_ident)."')";
			$hdl = $this->dbms->query($q);
		}
		return true;
	}
	
	/* ------------------------------------------------------------------------
	*
	*/
	public function get_language_status($mdat_id, $lang_ident) {
		$ret = false;
		$q = "SELECT mdata_id FROM mdata_language WHERE mdata_id=".$mdat_id." AND lang_id='".sqlite_escape_string($lang_ident)."'";
		$hdl = $this->dbms->query($q);
		$ret = $hdl->fetchSingle();
		return ($ret) ? true : false;
	}
	
	/* ------------------------------------------------------------------------
	*
	*/
	public function get_language_by_mdata_id($mdat_id) {
		$ret = array();;
		if (!$mdat_id) return $ret;
		$q = "SELECT lang_id FROM mdata_language WHERE mdata_id=".$mdat_id." ORDER BY lang_id";
		#print $q."\n";
		$hdl = $this->dbms->query($q);
		$ret = array();
		while($res = $hdl->fetch(SQLITE_ASSOC)) {
			$ret[$res['lang_id']] = true;
		}
		return $ret;
	}
	
	public function update_launch_time($id) {
		$q = 'UPDATE fdata SET launchtime = '.time().', launchcnt = launchcnt+1 WHERE id = '.(int)$id.'';
		#print $q."\n";
		$hdl = $this->dbms->query($q);
	}
	
	/* ------------------------------------------------------------------------
	*
	*/
	public function get_media_count_for_eccident($eccident, $hideDup) {
		$ret = false;
		
		$snip_where = array();
		if ($eccident) $snip_where[] = "eccident='".sqlite_escape_string($eccident)."'";
		if ($hideDup) $snip_where[] = "duplicate is null";
		// BUILD WHERE SNIPPLET STRING
		$snip_where_sql = SqlHelper::createSqlWhere($snip_where);
		
		$q = "SELECT count(*) as cnt FROM fdata WHERE ".$snip_where_sql."";
		#print $q."\n";
		$hdl = $this->dbms->query($q);
		$ret = $hdl->fetchSingle();
		
		return $ret;
	}
	
	/* ------------------------------------------------------------------------
	*
	*/
	
	public function getNavPlatformCounts($extension, $hideDup, $language=false, $category=false, $search_ext=false, $hideMetaless=false, $like=false, $onlyFiles=false)
	{
		// CREATE WHERE-CLAUSE
		$snip_where = array();
		// initial entry
		$snip_where[] = "1";

		if ($like) $snip_where[] = $like;
		
		// show only data with metadata assigned
		if ($hideMetaless) $snip_where[] = "md.id IS NULL";
		// doublettes
		if ($hideDup) $snip_where[] = "fd.duplicate is null";
		// category
		if ($category) $snip_where[] = "md.category=".$category;
		// language
		if ($language) $snip_where[] = "mdl.lang_id='".$language."'";
		// esearch
		$esearch = SqlHelper::createSqlExtSearch($search_ext);
		if ($esearch) $snip_where[] = $esearch;
		
		// inner = count only roms with metadata
		$joinType = ($onlyFiles) ? 'inner' : 'left';
		
		$sql_join = ($language) ? "left join mdata_language AS mdl on md.id=mdl.mdata_id " : "";
		
		$personalJoin = ($this->showOnlyPersonal) ? " udata AS ud inner join fdata AS fd on (ud.eccident=fd.eccident and ud.crc32=fd.crc32) " : 'fdata AS fd';
		
		$sqlNamespace = 'fd';
		$snipSqlJoin = $personalJoin.' '.$joinType.' join mdata AS md on fd.crc32=md.crc32 and fd.eccident=md.eccident '.$sql_join;
		$snipSqlGroup = 'group by fd.eccident';

		if ($this->showOnlyDontHave){
			$sqlNamespace = 'md';
			$snip_where[] = 'fd.id IS NULL';
			$snipSqlJoin = ' mdata AS md left join fdata AS fd on (md.eccident=fd.eccident and md.crc32=fd.crc32) ';
			$snipSqlJoin .= ($language) ? ' left join mdata_language AS mdl on md.id=mdl.mdata_id ' : '';
			$snipSqlGroup = 'group by md.eccident';
		}

		#$eccIdents = '"'.implode('","', $extension).'"';
		#$snip_where[] = $sqlNamespace.'.eccident in ('.sqlite_escape_string($eccIdents).')';
		
		# create sql snipplets
		$snipSqlWhere = SqlHelper::createSqlWhere($snip_where);
		
		// GET COUNT
		$q = "
			SELECT
			".$sqlNamespace.".eccident as eccident, count(".$sqlNamespace.".id) as cnt
			FROM
			".$snipSqlJoin."
			WHERE
			".$snipSqlWhere."
			".$snipSqlGroup."
		";
		#print $q."\n";
		$hdl = $this->dbms->query($q);
		$ret = array();
		while($res = $hdl->fetch(SQLITE_ASSOC)) {
			$ret[$res['eccident']] = $res['cnt'];
		}
		return $ret;
	}
		
	public function find_duplicate_by_id($id) {
		if (!$id) return false;
		$q="
			select
			md.id as md_id,
			md.name as md_name,
			md.crc32 as md_crc32
			from
			mdata_duplicate AS mdd left join mdata AS md on mdd.mdata_id_duplicate=md.id
			where
			mdd.mdata_id in (select mdata_id from mdata_duplicate where mdata_id_duplicate=".(int)$id.")
			group by mdd.mdata_id_duplicate
		";
		#print $q."\n";
		$hdl = $this->dbms->query($q);
		$ret = array();
		while($res = $hdl->fetch(SQLITE_ASSOC)) {
			$ret[] = $res;
		}
		if (false && count($ret)) {
			#print "<pre>";
			#print_r($ret);
			#print "</pre>\n";
		}
	}
	
	/* ------------------------------------------------------------------------
	*
	*/
	public function vacuum_database() {
		$q = "VACUUM";
		$hdl = $this->dbms->query($q);
	}
	
	public function update_fdata_by_path($path_source, $path_destination) {
		
		// ABS-PATH TO REL-PATH... 20061116 as
		$path_destination = FACTORY::get('manager/Os')->eccSetRelativeFile($path_destination);
		
		// set new filename
		$fileName = FileIO::get_plain_filename($path_destination);
		
		$q = '
			UPDATE
			fdata
			SET
			title = "'.sqlite_escape_string($fileName).'",
			path = "'.($path_destination).'"
			WHERE
			path = "'.$path_source.'"
		';
		//print $q."\n";
		$hdl = $this->dbms->query($q);
	}
	
	/**
	 * change the path in the dataset to a new
	 * position. used in fileoperations like rename and copy
	 *
	 * @param int $fdataId
	 * @param string $path_destination
	 * @return bool
	 */
	public function updatePathById($fdataId, $path_destination) {
		
		// ABS-PATH TO REL-PATH...
		$path_destination = FACTORY::get('manager/Os')->eccSetRelativeFile($path_destination);
		
		// set new filename
		$fileName = FileIO::get_plain_filename($path_destination);
		
		$q = '
			UPDATE
			fdata
			SET
			title = "'.sqlite_escape_string($fileName).'",
			path = "'.$path_destination.'"
			WHERE
			id = '.(int)$fdataId.'
		';
		//print $q."\n";
		$hdl = $this->dbms->query($q);
		
		return true;
	}
	
	public function deleteFdataById($id) {
		if (!$id) return false;
		$q = '
			DELETE FROM
			fdata
			WHERE
			id = '.(int)$id.'
		';
		$hdl = $this->dbms->query($q);

		// remove bookmarks also
		$this->remove_bookmark_by_id($id);
		
		return true;
	}
	
	/**
	 * NEEDS A NEW TABLE!!!!!
	 */
	public function getRomPersonalData() {}
	
	public function hasBookmark($fileId) {
		$q = "SELECT * from fdata_bookmarks WHERE file_id = ".(int)$fileId."";
		$hdl = $this->dbms->query($q);
		return ($hdl->fetchSingle()) ? true : false;
	}
	
	public function getFdataById($id) {
		$q = "SELECT * FROM fdata WHERE id = ".(int)$id."";
		$hdl = $this->dbms->query($q);
		return $hdl->fetch(SQLITE_ASSOC);
	}
	
	public function getAutoCompleteData($field = false, $onlyHaving = true) {
		$ret = array();
		if (!$field || !in_array($field, array('name', 'publisher', 'creator', 'year'))) return $ret;
		$join = ($onlyHaving) ? "INNER JOIN fdata AS fd ON (fd.eccident=md.eccident AND fd.crc32=md.crc32)" : '';
		$q="SELECT md.id as id, md.".$field." as ".$field." FROM mdata AS md ".$join." GROUP BY ".$field." ORDER BY ".$field." ASC";
		#print $q.LF;
		$hdl = $this->dbms->query($q);
		while($res = $hdl->fetch(SQLITE_ASSOC)) {
			if ($res[$field] && $res[$field] !== 'NULL') $ret[$res['id']] = $res[$field];
		}
		return $ret;
		
	}

}

?>
