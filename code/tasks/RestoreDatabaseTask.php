<?php

/**
 * This task imports a database from a file uploaded into assets
 * This does not support files with strings in double quotes
 * The dump file must be generated with extended-inserts turned off
 * mysqldump --extended-insert=FALSE -uroot -p database_name | gzip > database.sql.gz
 *
 * If no db parameter is given /dev/task/RestoreDatabaseTask/?db=file.sql.gz
 * then it will default to looking for database.sql.gz
 *
 * It only supports ".gz and .sql" file formats currently.
 *
 * NOTE: This will only run on dev/test environments and will not backup existing data
 *
 * @access public
 */
class RestoreDatabaseTask extends BuildTask {

	protected $verbose = false;

	protected $onlyShowErrors = false;

	/**
	 * $enabeld Show it in /dev/tasks and CLI
	 * @var boolean
	 */
	protected $enabeld = true;

	/**
	 * $sqlComments used for skipping commented lines
	 * @var array
	 */
	protected $sqlComments = array('#', '-- ', 'DELIMITER', '/*!');

	/**
	 * $queryDelimiter default query delimiter
	 * @var string
	 */
	protected $queryDelimiter = ';'; //Queries delimiter

	/**
	 * $maxLines max length of a query to be considered
	 * May need to be adjusted on bigger databases
	 * @var integer
	 */
	protected $maxLines = 1024; //Lines

	/**
	 * $maxChunkSize 32K blocks of file
	 *
	 * @var integer
	 */
	protected $maxChunkSize = 32 * 1024;

	public $description = 'Restore database from a given database backup file in assets directory';


	/**
	 * Check that we do not run this in production
	 */
	public function init() {

		// only allowed to do this in DEV or TEST environments for obivious reasons
		if (Director::isLive()) {
			echo Debug::text("Sorry, can't do this in production/live environments");
			exit(); // maybe return?
		}

		parent::init();

	}

	/**
	 * @var bool $enabled If set to FALSE, keep it from showing in the list
	 * and from being executable through URL or CLI.
	 *
	 * @access public
	 * @param SS_Request
	 */
	public function run($request) {

		$dbFilename = $request->getVar('db') ? $request->getVar('db') : 'database.sql.gz';
		$file = File::find($dbFilename);

		if (!$this->checkExtension($dbFilename) || !$file) {
			echo Debug::text("Sorry, either I could not find $dbFilename in assets or is not the right format");
			echo Debug::text("Only allowed file formats are .sql or .gz");
			exit();
		}

		// make sure existing DB tables are dropped
		if ($this->dropDBTables()) {
			$this->importSql($file);
		}

	}

	/**
	 * This method is responsible for dropping all the tables!
	 *
	 * @access private
	 * @return bool
	 */
	private function dropDBTables(){

		$done = false;

		DB::query("SET foreign_key_checks = 0");
		$tables = DB::query("SHOW TABLES");

		if ($tables){
			foreach ($tables as $k => $v) {
				$name = array_values($v);
				echo Debug::text("Drop it like its ... " . $name[0]);
				DB::query("DROP TABLE IF EXISTS `" . $name[0] . "`;");
			}
			$done = true;
		}

		DB::query("SET foreign_key_checks = 1");

		return $done;
	}


	/**
	 * importSql Imports non-extended mysql database dumps
	 * SQL dump file must be created without extended inserts
	 * mysqldump --extended-insert=FALSE -uroot -p database_name | gzip > database.sql.gz
	 *
	 * @access private
	 * @param  SS_File $file SilverStripe File found in /assets/
	 */
	private function importSql($file) {
		@ini_set('auto_detect_line_endings', true);
		@set_time_limit(0);

		$name = $file->Name;
		$path = $file->getFullPath();


		$comment = $this->sqlComments;
		$maxLines = $this->maxLines;
		$maxChunkSize = $this->maxChunkSize;
		$compressed = $this->isCompressed($name);


		$lines = $compressed ? @gzfile($path) : @file($path);
		$file = $compressed ? @gzopen($path, 'r') : @fopen($path, 'r') ;

		$currentline = 0;
		$query = "";

		while ($currentline < count($lines)) {

			$dumpline = "";

			// Keep going until we are at the end of line or end of file
			while (!feof($file) && $this->endOfLine($dumpline)) {
				$dumpline .= (!$compressed) ? fgets($file, $maxChunkSize) : gzgets($file, $maxChunkSize);
			}

			// Line is empty, stop!
			if ($dumpline === '') break;

			$dumpline = $this->sanitize($dumpline, $currentline);

			// Do not process SQL comments
			$skipcomment = false;
			reset($comment);
			foreach ($comment as $cv) {
				if ($dumpline == "" || strpos($dumpline, $cv) === 0) {
					$skipcomment = true;
					break;
				}
			}

			if ($skipcomment){
				$currentline++;
				continue;
			}

			// Build the query
			$query .= $dumpline;

			if (($this->delimiterFound(trim($dumpline)) || $this->queryDelimiter == '')) {
				DB::query($this->getQuery($query));
				$query = "";
			}

			$currentline++;
		}

		echo Debug::text("#---- Import complete -----#");

	}

	/**
	 *       HELPER FUNCTIONS
	 */



	/**
	 * checkExtension Checks to see if extension is sql or gz
	 *
	 * @param string $filename
	 * @return boolean
	 */
	private function checkExtension($filename) {
		return (bool) preg_match("/(\.(sql|gz))$/i", $filename);
	}

	/**
	 * sanitize Removes UTF-8 byte code
	 * standardise all line breaks with \n
	 *
	 * @param  string  $line line containing query string
	 * @param  integer $ln line number
	 * @return string
	 */
	private function sanitize($line, $ln = 0) {

		// Strip UTF8 byte code for first line
		if ($ln == 0) {
			$line = preg_replace('|^\xEF\xBB\xBF|','', $line);
		}

		// Remove double back-slashes
		$line = str_replace("\\\\", "", $line);

		return str_replace(array("\r\n", "\r"), "\n", $line);
	}

	/**
	 * Method returns true or false if filename contains .gz
	 *
	 * @param  string  $filename filename
	 * @return boolean
	 */
	private function isCompressed($filename) {
		return (bool) preg_match("/\.gz$/i", $filename);
	}

	/**
	 * endOfLine Check to see if we are at the end of line
	 *
	 * @param  string $line
	 * @return boolean
	 */
	private function endOfLine($line) {
		return (bool) (substr($line, -1) != "\n" && substr($line, -1) != "\r");
	}

	/**
	 * delimiterFound Check to see if the line contains delimiter i.e ;
	 *
	 * @param string $line
	 * @return boolean
	 */

	private function delimiterFound($line) {
		return (bool) preg_match('/'.preg_quote($this->queryDelimiter,'/').'$/',trim($line));
	}

	/**
	 * getQuery Removes the delimiter from query
	 *
	 * @param  string $query
	 * @return string query string without delimiter
	 */
	private function getQuery($query) {
		// leave out the delimiter from, query
		return substr($query, 0, -1*strlen($this->queryDelimiter));
	}

}