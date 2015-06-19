<?php
namespace nzedb;

use nzedb\db\Settings;

/**
 * Class for reading and writing NZB files on the hard disk,
 * building folder paths to store the NZB files.
 */
class NZB
{
	const NZB_NONE = 0; // Release has no NZB file yet.
	const NZB_ADDED = 1; // Release had an NZB file created.

	/**
	 * Determines if the site setting table per group is enabled.
	 * @var bool
	 * @access private
	 */
	private $tablePerGroup;

	/**
	 * Levels deep to store NZB files.
	 * @var int
	 */
	private $nzbSplitLevel;

	/**
	 * Path to store NZB files.
	 * @var string
	 */
	private $siteNzbPath;

	/**
	 * Group ID when writing NZBs.
	 * @var int
	 * @access protected
	 */
	protected $groupID;

	/**
	 * Instance of class db.
	 * @var \nzedb\db\Settings
	 * @access public
	 */
	public $pdo;

	/**
	 * Current nZEDb version.
	 * @var string
	 * @access protected
	 */
	protected $_nZEDbVersion;

	/**
	 * Base query for selecting collection data for writing NZB files.
	 * @var string
	 * @access protected
	 */
	protected $_collectionsQuery;

	/**
	 * Base query for selecting binary data for writing NZB files.
	 * @var string
	 * @access protected
	 */
	protected $_binariesQuery;

	/**
	 * Base query for selecting parts data for writing NZB files.
	 * @var string
	 * @access protected
	 */
	protected $_partsQuery;

	/**
	 * String used for head in NZB XML file.
	 * @var string
	 * @access protected
	 */
	protected $_nzbHeadString;

	/**
	 * Names of CBP tables.
	 * @var array [string => string]
	 * @access protected
	 */
	protected $_tableNames;

	/**
	 * Default constructor.
	 *
	 * @param Settings $pdo
	 *
	 * @access public
	 */
	public function __construct(&$pdo = null)
	{
		$this->pdo = ($pdo instanceof Settings ? $pdo : new Settings());

		$this->tablePerGroup = ($this->pdo->getSetting('tablepergroup') == 0 ? false : true);
		$nzbSplitLevel = $this->pdo->getSetting('nzbsplitlevel');
		$this->nzbSplitLevel = (empty($nzbSplitLevel) ? 1 : $nzbSplitLevel);
		$this->siteNzbPath = (string)$this->pdo->getSetting('nzbpath');
		if (substr($this->siteNzbPath, -1) !== DS) {
			$this->siteNzbPath .= DS;
		}
	}

	/**
	 * Initiate class vars when writing NZB's.
	 *
	 * @param int $groupID
	 *
	 * @access public
	 */
	public function initiateForWrite($groupID)
	{
		$this->groupID = $groupID;
		// Set table names
		if ($this->tablePerGroup === true) {
			if ($this->groupID == '') {
				exit("$this->groupID is missing\n");
			}
			$this->_tableNames = [
				'cName' => 'collections_' . $this->groupID,
				'bName' => 'binaries_' . $this->groupID,
				'pName' => 'parts_' . $this->groupID
			];
		} else {
			$this->_tableNames = [
				'cName' => 'collections',
				'bName' => 'binaries',
				'pName' => 'parts'
			];
		}

		$this->_collectionsQuery = sprintf(
			'SELECT %s.*, UNIX_TIMESTAMP(%s.date) AS udate, groups.name AS groupname
			FROM %s
			INNER JOIN groups ON %s.group_id = groups.id
			WHERE %s.releaseid = ',
			$this->_tableNames['cName'],
			$this->_tableNames['cName'],
			$this->_tableNames['cName'],
			$this->_tableNames['cName'],
			$this->_tableNames['cName']
		);
		$this->_binariesQuery = (
			'SELECT id, name, totalparts FROM ' . $this->_tableNames['bName'] . ' WHERE collection_id = %d ORDER BY name'
		);
		$this->_partsQuery = (
			'SELECT DISTINCT(messageid), size, partnumber FROM ' . $this->_tableNames['pName'] .
			' WHERE binaryid = %d ORDER BY partnumber'
		);

		$this->_nzbHeadString = (
			"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE nzb PUBLIC \"-//newzBin//DTD NZB 1.1//EN\" \"http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd\">\n<!-- NZB Generated by: nZEDb " .
			$this->pdo->version() . ' ' . htmlspecialchars(date('F j, Y, g:i a O'), ENT_QUOTES, 'utf-8') .
			" -->\n<nzb xmlns=\"http://www.newzbin.com/DTD/2003/nzb\">\n<head>\n <meta type=\"category\">%s</meta>\n <meta type=\"name\">%s</meta>\n</head>\n\n"
		);
	}

	/**
	 * Write an NZB to the hard drive for a single release.
	 *
	 * @param int    $relID   The ID of the release in the DB.
	 * @param string $relGuid The guid of the release.
	 * @param string $name    The name of the release.
	 * @param string $cTitle  The name of the category this release is in.
	 *
	 * @return bool Have we successfully written the NZB to the hard drive?
	 *
	 * @access public
	 */
	public function writeNZBforReleaseId($relID, $relGuid, $name, $cTitle)
	{
		$path = ($this->buildNZBPath($relGuid, $this->nzbSplitLevel, true) . $relGuid . '.nzb.gz');
		$fp = gzopen($path, 'wb7');
		if ($fp) {
			$nzb_guid = '';
			gzwrite(
				$fp,
				sprintf(
					$this->_nzbHeadString,
					htmlspecialchars($cTitle, ENT_QUOTES, 'utf-8'),
					htmlspecialchars($name, ENT_QUOTES, 'utf-8')
				)
			);

			$collections = $this->pdo->queryDirect($this->_collectionsQuery . $relID);

			if ($collections instanceof \Traversable) {

				foreach ($collections as $collection) {
					$poster = htmlspecialchars($collection['fromname'], ENT_QUOTES, 'utf-8');
					$binaries = $this->pdo->queryDirect(sprintf($this->_binariesQuery, $collection['id']));

					if ($binaries instanceof \Traversable) {

						foreach ($binaries as $binary) {

							// Buffer segment writes, increases performance.
							$string = '';

							$parts = $this->pdo->queryDirect(sprintf($this->_partsQuery, $binary['id']));

							if ($parts instanceof \Traversable) {

								foreach ($parts as $part) {
									if ($nzb_guid === '') {
										$nzb_guid = $part['messageid'];
									}
									$string .= (
										'  <segment bytes="' . $part['size']
										. '" number="' . $part['partnumber'] . '">'
										. htmlspecialchars($part['messageid'], ENT_QUOTES, 'utf-8')
										. "</segment>\n"
									);
								}
							}

							gzwrite($fp,
								'<file poster="' . $poster .
								'" date="' . $collection['udate'] .
								'" subject="' . htmlspecialchars($binary['name'], ENT_QUOTES, 'utf-8') .
								' (1/' . $binary['totalparts'] . ")\">\n <groups>\n  <group>" . $collection['groupname'] .
								"</group>\n </groups>\n <segments>\n" . $string . " </segments>\n</file>\n"
							);
						}
					}
				}
			}

			gzwrite($fp, '</nzb>');
			gzclose($fp);

			if (is_file($path)) {
				// Mark release as having NZB and delete CBP.
				$this->pdo->queryExec(
					sprintf('
						UPDATE releases SET nzbstatus = %d %s WHERE id = %d;
						DELETE FROM %s WHERE releaseid = %d',
						NZB::NZB_ADDED,
						($nzb_guid === '' ? '' : ', nzb_guid = ' . $this->pdo->escapestring(md5($nzb_guid))),
						$relID, $this->_tableNames['cName'], $relID
					)
				);

				// Chmod to fix issues some users have with file permissions.
				chmod($path, 0777);
				return true;
			} else {
				echo "ERROR: $path does not exist.\n";
			}
		}
		return false;
	}

	/**
	 * Build a folder path on the hard drive where the NZB file will be stored.
	 *
	 * @param string $releaseGuid         The guid of the release.
	 * @param int    $levelsToSplit       How many sub-paths the folder will be in.
	 * @param bool   $createIfNotExist Create the folder if it doesn't exist.
	 *
	 * @return string $nzbpath The path to store the NZB file.
	 *
	 * @access public
	 */
	private function buildNZBPath($releaseGuid, $levelsToSplit, $createIfNotExist)
	{
		$nzbPath = '';

		for ($i = 0; $i < $levelsToSplit && $i < 32; $i++) {
			$nzbPath .= substr($releaseGuid, $i, 1) . DS;
		}

		$nzbPath = $this->siteNzbPath . $nzbPath;

		if ($createIfNotExist === true && !is_dir($nzbPath)) {
			mkdir($nzbPath, 0777, true);
		}

		return $nzbPath;
	}

	/**
	 * Retrieve path + filename of the NZB to be stored.
	 *
	 * @param string $releaseGuid         The guid of the release.
	 * @param int    $levelsToSplit       How many sub-paths the folder will be in. (optional)
	 * @param bool   $createIfNotExist Create the folder if it doesn't exist. (optional)
	 *
	 * @return string Path+filename.
	 *
	 * @access public
	 */
	public function getNZBPath($releaseGuid, $levelsToSplit = 0, $createIfNotExist = false)
	{
		if ($levelsToSplit === 0) {
			$levelsToSplit = $this->nzbSplitLevel;
		}

		return ($this->buildNZBPath($releaseGuid, $levelsToSplit, $createIfNotExist) . $releaseGuid . '.nzb.gz');
	}

	/**
	 * Determine is an NZB exists, returning the path+filename, if not return false.
	 *
	 * @param  string $releaseGuid              The guid of the release.
	 *
	 * @return bool|string On success: (string) Path+file name of the nzb.
	 *                     On failure: (bool)   False.
	 *
	 * @access public
	 */
	public function NZBPath($releaseGuid)
	{
		$nzbFile = $this->getNZBPath($releaseGuid);
		return (is_file($nzbFile) ? $nzbFile : false);
	}

	/**
	 * Retrieve various information on a NZB file (the subject, # of pars,
	 * file extensions, file sizes, file completion, group names, # of parts).
	 *
	 * @param string	$nzb		The NZB contents in a string.
	 * @param array		$options
	 * 					'no-file-key'	=> True - use numeric array key; False - Use filename as array key.
	 * 					'strip-count'	=> True - Strip file/part count from file name to make the array key; False - Leave file name as is.
	 *
	 * @return array $result Empty if not an NZB or the contents of the NZB.
	 *
	 * @access public
	 */
	public function nzbFileList($nzb, array $options = [])
	{
		$defaults = [
				'no-file-key' => true,
				'strip-count' => false,
			];
		$options += $defaults;

		$num_pars = $i = 0;
		$result = [];

		if (!$nzb) {
			return $result;
		}

		$xml = @simplexml_load_string(str_replace("\x0F", '', $nzb));
		if (!$xml || strtolower($xml->getName()) !== 'nzb') {
			return $result;
		}

		foreach ($xml->file as $file) {
			// Subject.
			$title = (string)$file->attributes()->subject;

			// Amount of pars.
			if (stripos($title, '.par2')) {
				$num_pars++;
			}

			if ($options['no-file-key'] == false) {
				$i = $title;
				if ($options['strip-count']) {
					// Strip file / part count to get proper sorting.
					$i = preg_replace('#\d+[- ._]?(/|\||[o0]f)[- ._]?\d+?(?![- ._]\d)#i', '', $i);
					// Change .rar and .par2 to be sorted before .part0x.rar and .volxxx+xxx.par2
					if (strpos($i, '.par2') !== false && !preg_match('#\.vol\d+\+\d+\.par2#i', $i)) {
						$i = str_replace('.par2', '.vol0.par2', $i);
					} else if (preg_match('#\.rar[^a-z0-9]#i', $i) && !preg_match('#\.part\d+\.rar#i', $i)) {
						$i = preg_replace('#\.rar(?:[^a-z0-9])#i', '.part0.rar', $i);
					}
				}
			}

			$result[$i]['title'] = $title;

			// Extensions.
			if (preg_match(
					'/\.(\d{2,3}|7z|ace|ai7|srr|srt|sub|aiff|asc|avi|audio|bin|bz2|'
					. 'c|cfc|cfm|chm|class|conf|cpp|cs|css|csv|cue|deb|divx|doc|dot|'
					. 'eml|enc|exe|file|gif|gz|hlp|htm|html|image|iso|jar|java|jpeg|'
					. 'jpg|js|lua|m|m3u|mkv|mm|mov|mp3|mp4|mpg|nfo|nzb|odc|odf|odg|odi|odp|'
					. 'ods|odt|ogg|par2|parity|pdf|pgp|php|pl|png|ppt|ps|py|r\d{2,3}|'
					. 'ram|rar|rb|rm|rpm|rtf|sfv|sig|sql|srs|swf|sxc|sxd|sxi|sxw|tar|'
					. 'tex|tgz|txt|vcf|video|vsd|wav|wma|wmv|xls|xml|xpi|xvid|zip7|zip)'
					. '[" ](?!(\)|\-))/i',
					$title, $ext
				)
			) {

				if (preg_match('/\.r\d{2,3}/i', $ext[0])) {
					$ext[1] = 'rar';
				}
				$result[$i]['ext'] = strtolower($ext[1]);
			} else {
				$result[$i]['ext'] = '';
			}

			$fileSize = $numSegments = 0;

			// Parts.
			if (!isset($result[$i]['segments'])) {
				$result[$i]['segments'] = [];
			}

			// File size.
			foreach ($file->segments->segment as $segment) {
				array_push($result[$i]['segments'], (string)$segment);
				$fileSize += $segment->attributes()->bytes;
				$numSegments++;
			}
			$result[$i]['size'] = $fileSize;

			// File completion.
			if (preg_match('/(\d+)\)$/', $title, $parts)) {
				$result[$i]['partstotal'] = $parts[1];
			}
			$result[$i]['partsactual'] = $numSegments;

			// Groups.
			if (!isset($result[$i]['groups'])) {
				$result[$i]['groups'] = [];
			}
			foreach ($file->groups->group as $g) {
				array_push($result[$i]['groups'], (string)$g);
			}

			unset($result[$i]['segments']['@attributes']);
			if ($options['no-file-key']) {
				$i++;
			}
		}
		return $result;
	}
}
