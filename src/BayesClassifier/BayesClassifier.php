<?php
/** BayesClassifier
 * 単純ベイズ分類機：ツイートを学習、判定
 *
 * @copyright	(c)studio pahoo
 * @author		パパぱふぅ
 * @動作環境	PHP 5/7, SQLite
 * @参考サイト	http://www.pahoo.org/e-soul/webtech/php03/php03-16-01.shtm
 *
 * [コマンドライン・パラメータ]
 * auto  自動的にツイートを取得し、ＤＢに格納する
 *
*/
// 初期化処理 ================================================================
define('INTERNAL_ENCODING', 'UTF-8');
mb_internal_encoding(INTERNAL_ENCODING);
mb_regex_encoding(INTERNAL_ENCODING);
define('MYSELF', basename($_SERVER['SCRIPT_NAME']));
define('REFERENCE', 'http://www.pahoo.org/e-soul/webtech/php03/php03-16-01.shtm');

//プログラム・タイトル
define('TITLE', '単純ベイズ分類機：ツイートを学習、判定');

//リリース・フラグ（公開時にはTRUEにすること）
define('FLAG_RELEASE', FALSE);

//出力ログ・レベル
define('LOG_LEVEL', 1);	//0:エラーのみ，1:最小限の成功ログまで，2:全部

//一度に取得するツイート数
define('NUM_TWEETS', 60);

//一度に学習するツイート数
define('NUM_LEARN', 100);

//TwitterAPI呼び出し間隔（秒）
define('TIME_INTERVAL', (10 * 60));

//SQLite DBファイル名；各自の環境に合わせて変更すること
define('DBFILE', './sqlite/usertimelines.sqlite3');

//MeCab実行プログラム；各自の環境に合わせて変更すること
define('MECAB', 'C:\Program Files (x86)\MeCab\bin\mecab.exe');

//ユーザー辞書；各自の環境に合わせて変更すること
define('FILE_UDIC_MECAB', 'C:\Program Files (x86)\MeCab\dic\user.dic');

//Twitter API クラス；各自の環境に合わせて変更すること
require_once('pahooTwitterAPI.php');

/**
 * 共通HTMLヘッダ
 * @global string $HtmlHeader
*/
$encode = INTERNAL_ENCODING;
$title  = TITLE;
$HtmlHeader =<<< EOD
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="{$encode}">
<title>{$title}</title>
<meta name="author" content="studio pahoo" />
<meta name="copyright" content="studio pahoo" />
<meta name="ROBOTS" content="NOINDEX,NOFOLLOW" />
<meta http-equiv="pragma" content="no-cache">
<meta http-equiv="cache-control" content="no-cache">
<style type="text/css">
table {
	width: 550px;
	border-collapse: collapse;
	margin-top: 10px;
	margin-bottom: 10px;
}
tr, td, th {
	border: 1px gray solid;
	padding: 4px;
}
.index {
	background-color: gainsboro;
}
</style>
<script type="text/javascript">
//カウントダウンタイマ
var CDT;
function countDown() {
	var sec = document.getElementById("sec").innerHTML;
	if (sec > 0) {
		sec--;
		document.getElementById("sec").innerHTML  = sec;
		document.getElementById("store").disabled = true;
		document.getElementById("reset").disabled = true;
	} else {
		document.getElementById("store").disabled = false;
		document.getElementById("reset").disabled = false;
		clearInterval(CDT);
	}
}
window.onload = function() {
	CDT = setInterval("countDown()", 1000);
}
</script>
</head>

EOD;

/**
 * 共通HTMLフッタ
 * @global string $HtmlFooter
*/
$HtmlFooter =<<< EOD
</html>

EOD;

// pahooLearningTweetsクラス =================================================
class pahooLearningTweets {
	var $ptw;							//pahooTwitterAPIクラス
	var $pdo;							//DBアクセス
	var $error;						//エラーフラグ
	var $errmsg;						//エラーメッセージ
	var $table_logAPI  = 'log_api';	//テーブル：TwitterAPI利用ログ
	var $table_users   = 'users';		//テーブル：ユーザー
	var $table_tweets  = 'tweets';		//テーブル：ツイート
	var $table_vectors = 'vectors';	//テーブル：学習記録
	var $fname_mylog   = './log/';		//アプリケーション・ログファイル
	var $level_mylog   = LOG_LEVEL;	//ログ出力レベル

/**
 * コンストラクタ
 * @param	なし
 * @return	なし
*/
function __construct() {
	$this->error  = FALSE;
	$this->errmsg = '';

	//アプリケーション・ログファイルの準備＝プログラムファイル名.log
	$arr = pathinfo($_SERVER['SCRIPT_NAME']);
	$this->fname_applog = $this->fname_mylog . $arr['filename'] . '.log';

	//pahooTwitterAPIクラス
	$this->ptw = new pahooTwitterAPI();

	//SQLite準備
	$this->pdo = NULL;
	try {
		$this->pdo = new PDO('sqlite:' . DBFILE);
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		//テーブル作成：TwitterAPI利用ログ
		//user_id:ログID, dt:利用日時, count:取得ツイート数
		$this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->table_logAPI . '(
			id       INTEGER PRIMARY KEY AUTOINCREMENT,
			url      TEXT,
			count    INTEGER,
			dt       TEXT
    	)');

		//テーブル作成：ユーザー情報
		//user_id:ユーザーID, screen_name:スクリーンネーム, dt:登録日時
		$this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->table_users . '(
			user_id     TEXT PRIMARY KEY,
			screen_name TEXT,
			dt          TEXT
    	)');

		//テーブル作成：ツイート内容
		//id:メッセージID, user_id:ユーザーID, description:ツイート内容,
		//flag:学習済みフラグ, dt:登録日時
		$this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->table_tweets . '(
			id          TEXT PRIMARY KEY,
			user_id     TEXT,
			description TEXT,
			flag        INTEGER,
			dt          TEXT
	  	)');

		//テーブル作成：学習記録
		//id:学習ID, user_id:ユーザーID, word:単語, count:出現回数
		//dt:登録日時
		$this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->table_vectors . '(
			id          TEXT PRIMARY KEY,
			user_id     TEXT,
			word        TEXT,
			count       INTEGER,
			dt          TEXT
	  	)');

	} catch (PDOException $e) {
		$this->error  = TRUE;
		$this->errmsg = 'Error SQLite: ' . $e->getMessage();
		$this->putAppLog($this->errmsg, __LINE__, __FUNCTION__);
	}
}

/**
 * デストラクタ
 * @return	なし
*/
function __destruct() {
	$this->ptw = NULL;
	$this->pdo = NULL;
}

/**
 * エラー状況
 * @return	bool TRUE:異常／FALSE:正常
*/
function iserror() {
	return $this->error;
}

/**
 * エラーメッセージ取得
 * @param	なし
 * @return	string 現在発生しているエラーメッセージ
*/
function geterror() {
	return $this->errmsg;
}

/**
 * アプリケーション・ログに書き込む
 * @param	string $msg  メッセージ
 * @param	int    $level 出力レベル（0:再優先～）
 * @param	int    $line 行番号
 * @param	string $func 関数名
 * @return	bool TRUE/FALSE
*/
function putAppLog($msg, $level, $line, $func) {
	//出力レベルのチェック
	if ($level > $this->level_mylog)	return TRUE;

	//タイムスタンプ
	$dt = date(DATE_W3C, time());
	//パスが無ければ生成
	$arr = pathinfo($this->fname_applog);
	if (! file_exists($arr['dirname'])) {
		mkdir($arr['dirname']);
	}
	//ログファイルが無ければ生成
	if (! file_exists($this->fname_applog)) {
		$outfp = fopen($this->fname_applog, 'w');
		if ($outfp == FALSE)	return FALSE;
		fwrite($outfp, $dt . " -- make new log file.\n");
		fclose($outfp);
	}
	//ログ書き込み
	$outfp = fopen($this->fname_applog, 'a');
	if ($outfp == FALSE)	return FALSE;
	$str = sprintf("%s, %s(%d) >> %s\n", $dt, $func, $line, $msg);
	fwrite($outfp, $str);

	return fclose($outfp);
}

// [1] 学習データの登録 ======================================================
/**
 * ユーザーID取得＋DB登録
 * @param	string $screen_name スクリーンネーム
 * @return	array(ユーザーID, 作成日時)／array(FALSE,FALSE)
*/
function getUserID($screen_name) {
	$sql_select = 'SELECT * FROM ' . $this->table_users . ' WHERE screen_name=:screen_name';
	$sql_insert = 'INSERT INTO ' . $this->table_users . ' (user_id, screen_name, dt) VALUES (:user_id, :screen_name, :dt)';

	//DB検索
	$stmt = $this->pdo->prepare($sql_select);
	$stmt->bindValue(':screen_name', $screen_name, PDO::PARAM_STR);
	$ret = $stmt->execute();
	$row = $stmt->fetch();
	if (isset($row['user_id'])) {
		$userID = $row['user_id'];
		$dt = $row['dt'];

	//DB登録
	} else {
		//TwitterAPI：ユーザー取得
		$url    = 'https://api.twitter.com/1.1/users/show.json';
		$method = 'GET';
		$param['screen_name'] = $screen_name;
		$ret = $this->ptw->request_user($url, $method, $param);
		//エラー処理
		if ($this->ptw->iserror()) {
			$this->error = TRUE;
			$this->errmsg = 'Error TwitterAPI: ' . $screen_name . ' -- ' . $this->ptw->geterror();
			$this->putAppLog($this->errmsg, 0, __LINE__, __FUNCTION__);
			$userID = FALSE;
			$dt = FALSE;
		//正常処理
		} else {
			$msg = 'Success TwitterAPI: ' . $screen_name . ' -- get user_id.';
			$this->putAppLog($msg, 1, __LINE__, __FUNCTION__);
			$userID = $this->ptw->responses->id_str;
			$dt = $this->ptw->responses->created_at;
			$stmt = $this->pdo->prepare($sql_insert);
			$stmt->bindValue(':user_id', $userID, PDO::PARAM_STR);
			$stmt->bindValue(':screen_name', $screen_name, PDO::PARAM_STR);
			$stmt->bindValue(':dt', date(DATE_W3C, strtotime($dt)), PDO::PARAM_STR);
			$ret = $stmt->execute();
			//エラー処理
			if ($ret == 0) {
				$this->error = TRUE;
				$this->errmsg = 'Error SQLite: ' . $screen_name . ' -- cannot add user..';
				$this->putAppLog($this->errmsg, 0, __LINE__, __FUNCTION__);
				$userID = FALSE;
				$dt = FALSE;
			} else {
				$msg = 'Success SQLite: ' . $screen_name . ' -- add user.';
				$this->putAppLog($msg, 1, __LINE__, __FUNCTION__);
			}
		}
	}

	return array($userID, $dt);
}

/**
 * ユーザーの最小／最大ツイートIDを取得
 * @param	string $screen_name スクリーンネーム
 * @return	array(最小ID, 最大ID)
*/
function getMinMaxID($screen_name) {
	$sql_select = 'SELECT MIN(id), MAX(id) FROM ' . $this->table_tweets . ' WHERE user_id=:user_id';

	list($userID, $dt) = $this->getUserID($screen_name);
	if ($userID == FALSE)	return array(FALSE, FALSE);

	//DB取得
	$stmt = $this->pdo->prepare($sql_select);
	$stmt->bindValue(':user_id', $userID, PDO::PARAM_STR);
	$ret = $stmt->execute();
	$row = $stmt->fetch();
	//エラー処理
	if (! isset($row[0])) {
		$this->error = TRUE;
		$this->errmsg = 'Error SQLite: ' . $screen_name . ' -- cannot get min/max ID.';
		$this->putAppLog($this->errmsg, 0, __LINE__, __FUNCTION__);
		return array(FALSE, FALSE);
	//正常リターン
	} else {
		$msg = 'Sucess SQLite: ' . $screen_name . ' -- get min/max ID.';
		$this->putAppLog($msg, 2, __LINE__, __FUNCTION__);
		return array($row[0], $row[1]);
	}
}

/**
 * あるユーザーの登録ツイート数を取得
 * @param	string $screen_name スクリーンネーム
 * @return	array(学習数, 総数)
*/
function getTweetNum($screen_name) {
	$sql_total = 'SELECT count(*) FROM ' . $this->table_tweets . ' WHERE user_id=:user_id';
	$sql_count = 'SELECT count(*) FROM ' . $this->table_tweets . ' WHERE user_id=:user_id AND flag>0';

	list($userID, $dt) = $this->getUserID($screen_name);
	if ($userID == FALSE)	return array(FALSE, FALSE);

	//学習数
	$stmt = $this->pdo->prepare($sql_count);
	$stmt->bindValue(':user_id', $userID, PDO::PARAM_STR);
	$ret = $stmt->execute();
	$row = $stmt->fetch();
	$count = $row[0];

	//総数
	$stmt = $this->pdo->prepare($sql_total);
	$stmt->bindValue(':user_id', $userID, PDO::PARAM_STR);
	$ret = $stmt->execute();
	$row = $stmt->fetch();
	$total = $row[0];

	$msg = 'Success SQLite: ' . $screen_name . ' -- count/total tweets.';
	$this->putAppLog($msg, 2, __LINE__, __FUNCTION__);

	return array($count, $total);
}

/**
 * あるユーザーの最古／最新ツイート日時を取得
 * @param	string $screen_name スクリーンネーム
 * @return	array(最古日時, 最新日時)
*/
function getMinMaxDT($screen_name) {
	$sql_select = 'SELECT MIN(dt), MAX(dt) FROM ' . $this->table_tweets . ' WHERE user_id=:user_id';

	list($userID, $dt) = $this->getUserID($screen_name);
	if ($userID == FALSE)	return array(FALSE, FALSE);

	//DB取得
	$stmt = $this->pdo->prepare($sql_select);
	$stmt->bindValue(':user_id', $userID, PDO::PARAM_STR);
	$ret = $stmt->execute();
	$row = $stmt->fetch();
	//エラー処理
	if (! isset($row[0])) {
		$this->error = TRUE;
		$this->errmsg = 'Error SQLite: ' . $screen_name . ' -- cannot get min/max date.';
		$this->putAppLog($this->errmsg, 0, __LINE__, __FUNCTION__);
		$row[0] = FALSE;
		$row[1] = FALSE;
	//正常処理
	} else {
		$msg = 'Success SQLite: ' . $screen_name . ' -- min/max date.';
		$this->putAppLog($msg, 2, __LINE__, __FUNCTION__);
	}
	return array($row[0], $row[1]);
}

/**
 * ユーザー情報を取得
 * @param	array $users ユーザー情報を格納する配列
 * @return	int ユーザー数／FALSE
*/
function getUserInfo(&$users) {
	$sql_select = 'SELECT * FROM ' . $this->table_users . ' ORDER BY user_id';

	//DB取得
	$stmt = $this->pdo->prepare($sql_select);
	$ret = $stmt->execute();
	$cnt = 0;
	while ($row = $stmt->fetch()) {
		$screen_name = $row['screen_name'];
		$users[$cnt]['screen_name'] = $screen_name;
 		list($users[$cnt]['dt_min'], $users[$cnt]['dt_max']) = $this->getMinMaxDT($screen_name);
		list($users[$cnt]['count'], $users[$cnt]['total']) = $this->getTweetNum($screen_name);
		$users[$cnt]['score'] = '';
		$cnt++;
	}
	$msg = 'Success SQLite: get user info.';
	$this->putAppLog($msg, 2, __LINE__, __FUNCTION__);

	return $cnt;
}

/**
 * 登録ユーザーのうち、取得ツイート数が最も少ないユーザーを返す
 * @param	なし
 * @return	string スクリーンネーム／FALSE
*/
function getScreenNameFew() {
	$users = array();
	$count = $this->getUserInfo($users);
	if ($count <= 0)	return FALSE;

	$total = (-1);
	$screen_name = '';
	foreach ($users as $user) {
		if ($total < 0) {
			$total = $user['total'];
			$screen_name = $user['screen_name'];
		} else if ($user['total'] < $total) {
			$total = $user['total'];
			$screen_name = $user['screen_name'];
		}
	}
	$msg = 'Success SQLite: get screen_name few.';
	$this->putAppLog($msg, 2, __LINE__, __FUNCTION__);

	return $screen_name;
}

/**
 * 登録ユーザーのうち、最新ツイートが最も古いユーザーを返す
 * @param	なし
 * @return	string スクリーンネーム／FALSE
*/
function getScreenNameOld() {
	$users = array();
	$count = $this->getUserInfo($users);
	if ($count <= 0)	return FALSE;

	$last = '';
	$screen_name = '';
	foreach ($users as $user) {
		if ($last == '') {
			$last = $user['dt_max'];
			$screen_name = $user['screen_name'];
		} else if ($user['dt_max'] < $last) {
			$last = $user['dt_max'];
			$screen_name = $user['screen_name'];
		}
	}
	$msg = 'Success SQLite: get screen_name old.';
	$this->putAppLog($msg, 2, __LINE__, __FUNCTION__);

	return $screen_name;
}

/**
 * 登録ユーザーのうち、最古ツイートが最も新しいユーザーを返す
 * @param	なし
 * @return	string スクリーンネーム／FALSE
*/
function getScreenNameNew() {
	$users = array();
	$count = $this->getUserInfo($users);
	if ($count <= 0)	return FALSE;

	$old = '';
	$screen_name = '';
	foreach ($users as $user) {
		if ($old == '') {
			$old = $user['dt_min'];
			$screen_name = $user['screen_name'];
		} else if ($user['dt_min'] > $old) {
			$last = $user['dt_min'];
			$screen_name = $user['screen_name'];
		}
	}
	$msg = 'Success SQLite: get screen_name new.';
	$this->putAppLog($msg, 2, __LINE__, __FUNCTION__);

	return $screen_name;
}

/**
 * ツイートをDB登録
 * @param	string $id ツイートID
 * @param	string $userID ユーザーID
 * @param	string $dt 作成日時
 * @param	string $description ツイート内容
 * @return	bool TRUE/FALSE
*/
function addTweet($id, $userID, $dt, $description) {
	$sql_select = 'SELECT * FROM ' . $this->table_tweets . ' WHERE id=:id';
	$sql_insert = 'INSERT INTO ' . $this->table_tweets . ' (id, user_id, flag, description, dt) VALUES (:id, :user_id, :flag, :description, :dt)';

	//DB登録
	$stmt = $this->pdo->prepare($sql_select);
	$stmt->bindValue(':id', $id, PDO::PARAM_STR);
	$ret = $stmt->execute();
	$row = $stmt->fetch();
	if (! isset($row['id'])) {
		$stmt = $this->pdo->prepare($sql_insert);
		$stmt->bindValue(':id', $id, PDO::PARAM_STR);
		$stmt->bindValue(':user_id', $userID, PDO::PARAM_STR);
		$stmt->bindValue(':flag', 0, PDO::PARAM_INT);
		$stmt->bindValue(':description', $description, PDO::PARAM_STR);
		$stmt->bindValue(':dt', date(DATE_W3C, strtotime($dt)), PDO::PARAM_STR);
		$ret = $stmt->execute();
	}
	//エラー処理
	if ($ret == 0) {
		$this->error = TRUE;
		$this->errmsg = 'Error SQLite: ' . $description . ' -- cannot add tweet';
		$this->putAppLog($this->errmsg, 0, __LINE__, __FUNCTION__);
		$ret = FALSE;
	//正常処理
	} else {
		$msg = 'Success SQLite: add tweet';
		$this->putAppLog($msg, 2, __LINE__, __FUNCTION__);
		$ret = TRUE;
	}

	return $ret;
}

/**
 * TwitterAPIアクセス記録をDB登録
 * @param	string $url TwitterAPI
 * @param	string $count リクエスト数
 * @return	bool TRUE/FALSE
*/
function addLogAPI($url, $count) {
	$sql_insert = 'INSERT INTO ' . $this->table_logAPI . ' (url, count, dt) VALUES (:url, :count, :dt)';

	//DB登録
	$stmt = $this->pdo->prepare($sql_insert);
	$stmt->bindValue(':url', $url, PDO::PARAM_STR);
	$stmt->bindValue(':count', $count, PDO::PARAM_INT);
	$stmt->bindValue(':dt', date(DATE_W3C, time()), PDO::PARAM_STR);
	$ret = $stmt->execute();
	//エラー処理
	if ($ret == 0) {
		$this->error = TRUE;
		$this->errmsg = 'Error SQLite: ' . $url . ' -- cannot add log API.';
		$this->putAppLog($this->errmsg, 0, __LINE__, __FUNCTION__);
		$ret = FALSE;
	//正常処理
	} else {
		$msg = 'Success SQLite: add log API.';
		$this->putAppLog($msg, 2, __LINE__, __FUNCTION__);
		$ret = TRUE;
	}
	return $ret;
}

/**
 * 前回のTwitterAPIアクセスからの経過時間を取得
 * @return	int 経過時間（秒）
*/
function getElapsedTime() {
	$sql_select = 'SELECT dt FROM ' . $this->table_logAPI . ' ORDER BY dt DESC';

	//DB取得
	$stmt = $this->pdo->prepare($sql_select);
	$ret = $stmt->execute();
	$row = $stmt->fetch();
	if ($ret == 0) {
		return 99999;
	} else {
		$dt = strtotime($row['dt']);
		return time() - $dt;
	}
}

/**
 * ユーザーのツイートを取得＋DB登録
 * @param	string $screen_name スクリーンネーム
 * @param	int    $count 取得ツイート数
 * @param	string $since_id このIDより新しいツイートを取得[省略可能]
 * @param	string $max_id  このIDより古いツイートを取得[省略可能]
 * @return	int 登録数／FALSE
*/
function getUserTweets($screen_name, $count, $since_id='', $max_id='') {
	$url    = 'https://api.twitter.com/1.1/statuses/user_timeline.json';
	$method = 'GET';
	$param['screen_name'] = $screen_name;
	$param['exclude_replies'] = 'false';
	$msg = '';
	if ($since_id != '') {
		$param['since_id'] = $since_id;
		$count++;
		$msg = 'after ID' . $since_id;
	} else if ($max_id != '') {
		$param['max_id']   = $max_id;
		$count++;
		$msg = 'before ID' . $max_id;
	}
	$param['count'] = $count;

	//API呼び出し間隔確認
	if ($this->getElapsedTime() <= TIME_INTERVAL) {
		$this->error = TRUE;
		$this->errmsg = 'TwitterAPI: too many request';
		return FALSE;
	}
	//ツイート取得
	$ret = $this->ptw->request_user($url, $method, $param);
	$count = 0;
	//エラー処理
	if (($ret == FALSE) || (count($this->ptw->responses) == 0)) {
		$this->error = TRUE;
		$this->errmsg = 'Error TwitterAPI: ' . $screen_name . ' -- cannnot get tweets.';
		$this->putAppLog($this->errmsg, 0, __LINE__, __FUNCTION__);
	//ツイートをDB登録
	} else {
		$this->addLogAPI($url, $count);	//TwitterAPIアクセス記録を更新
		foreach ($this->ptw->responses as $item) {
			$id = $item->id_str;
			$userID = $item->user->id_str;
			$dt = $item->created_at;
			$description = $item->text;
			if ($this->addTweet($id, $userID, $dt, $description) == FALSE)	return FALSE;
			$count++;
		}
		$msg = 'Success TwitterAPI: ' . $screen_name . ' -- get ' . $count . ' tweets ' . $msg . '.';
		$this->putAppLog($msg, 1, __LINE__, __FUNCTION__);
	}
	return $count;
}

// [2] 学習 ==================================================================
/**
 * MeCabを使って単語に分解する
 * @param	string $text 分解するコンテンツ
 * @param	array $words 分解した単語を格納する配列
 * @return	int 分解した単語数
*/
function getWords($text, &$words) {
	$tmpfile = tempnam(sys_get_temp_dir(), 'pahoo');
	$cnt = 0;

	$str = mb_convert_encoding($text, 'SJIS', INTERNAL_ENCODING);
	file_put_contents($tmpfile, $str);

	//1行ずつ処理
	$arr = preg_split("/\n/iu", $str);
	foreach ($arr as $str) {
		$str = rtrim($str);
		if ($str == '')	continue;
		$cmd = 'echo ' . $str . ' | ' .  '"' . MECAB . '" -Owakati -u "' . FILE_UDIC_MECAB . '"';
		$cmd = '"' . MECAB . '" ' . $tmpfile;
		$handle = popen($cmd, 'r');
		//結果を1行ずつ取得
		while ($str = fgets($handle)) {
			$str = mb_convert_encoding($str, INTERNAL_ENCODING, 'SJIS');
			$result = mb_split("[\t\r\n' ,]", $str);
			//結果を配列に格納する
			foreach ($result as $key=>$val) {
				if ($val != '')	$words[$cnt][] = $val;
			}
			$cnt++;
		}
		pclose($handle);
	}
	unlink($tmpfile);

	return $cnt;
}

/**
 * 学習対象の単語かどうか
 * @param	array $word MeCabで分解した1行
 * @return	bool TRUE/FALSE
*/
function isword($word) {
	if (count($word) < 3)			return FALSE;

	//英数字のみはスキップ
	if (preg_match('/^[a-z0-9]+$/ui', $word[0]) > 0)	return FALSE;

	//1文字はスキップ
	if (mb_strlen($word[0]) <= 1)	return FALSE;

	//名詞のみを対象に
	if (preg_match('/名詞/ui', $word[1]) > 0) {
		if (preg_match('/(名詞|一般)/ui', $word[2]) > 0)	return TRUE;
	}
	return FALSE;
}

/**
 * テキストを単語ベクトルに変換
 * @param	string $text テキスト
 * @param	array  $vectors 単語ベクトルを格納する配列
 * @return	int 分解した単語数
*/
function text2vector($text, &$vectors) {
	$words = array();
	$cnt = 0;
	$this->getWords($text, $words);		//単語分割
	foreach ($words as $word) {
		if (! $this->isword($word))	continue;
		if (isset($vectors[$word[0]])) {
			$vectors[$word[0]]++;
		} else {
			$vectors[$word[0]] = 1;
			$cnt++;
		}
	}
	return $cnt;
}

/**
 * 登録されたツイートのうち未学習のものをまとめて取り出す
 * @param	string $userID ユーザーID
 * @param	int $count 取り出す最大件数
 * @return	array(取り出したツイート, ツイート件数)
*/
function takeTweet($userID, $count) {
	$sql_select = 'SELECT * FROM ' . $this->table_tweets . ' WHERE user_id=:user_id AND flag<>1';
	$sql_update = 'UPDATE ' . $this->table_tweets . ' set flag=:flag WHERE id=:id';

	//DB取得
	$stmt = $this->pdo->prepare($sql_select);
	$stmt->bindValue(':user_id', $userID, PDO::PARAM_STR);
	$ret = $stmt->execute();
	$cnt = 0;
	$text = '';
	while ($row = $stmt->fetch()) {
		$text .= $row['description'];
		$stmt2 = $this->pdo->prepare($sql_update);
		$stmt2->bindValue(':id', $row['id'], PDO::PARAM_STR);
		$stmt2->bindValue(':flag', 1, PDO::PARAM_INT);
		$ret = $stmt2->execute();
		$cnt++;
		if ($cnt >= $count)	break;
	}
	$msg = 'Success SQLite: take ' .$cnt . ' tweets.';
	$this->putAppLog($msg, 1, __LINE__, __FUNCTION__);

	return array($text, $cnt);
}

/**
 * 単語ベクトル情報を更新する
 * @param	string $userID ユーザーID
 * @param	array  $vectors 単語ベクトル情報
 * @return	bool TRUE/FALSE
*/
function updateVectors($userID, $vectors) {
	$sql_select = 'SELECT count FROM ' . $this->table_vectors . ' WHERE user_id=:user_id AND word=:word';
	$sql_insert = 'INSERT INTO ' . $this->table_vectors . ' (user_id, word, count, dt) VALUES (:user_id, :word, :count, :dt)';
	$sql_update = 'UPDATE ' . $this->table_vectors . ' set count=:count, dt=:dt WHERE user_id=:user_id AND word=:word';

	foreach ($vectors as $word=>$count) {
		$stmt = $this->pdo->prepare($sql_select);
		$stmt->bindValue(':user_id', $userID, PDO::PARAM_STR);
		$stmt->bindValue(':word',    $word,   PDO::PARAM_STR);
		$ret = $stmt->execute();
		$row = $stmt->fetch();

		//DB追加
		if (! isset($row['count'])) {
			$stmt2 = $this->pdo->prepare($sql_insert);
			$stmt2->bindValue(':user_id', $userID, PDO::PARAM_STR);
			$stmt2->bindValue(':word',    $word,   PDO::PARAM_STR);
			$stmt2->bindValue(':count',   $count,  PDO::PARAM_INT);
			$stmt2->bindValue(':dt', date(DATE_W3C, time()), PDO::PARAM_STR);
			$ret = $stmt2->execute();
			if ($ret < 1) {
				$this->error = TRUE;
				$this->errmsg = 'Error SQLite: ' . $word . ' -- cannot insert vectors.';
				$this->putAppLog($this->errmsg, 0, __LINE__, __FUNCTION__);
				return FALSE;
			}
		//DB更新
		} else {
			$stmt2 = $this->pdo->prepare($sql_update);
			$stmt2->bindValue(':user_id', $userID, PDO::PARAM_STR);
			$stmt2->bindValue(':word',    $word,   PDO::PARAM_STR);
			$stmt2->bindValue(':count', $row['count'] + $count, PDO::PARAM_INT);
			$stmt2->bindValue(':dt', date(DATE_W3C, time()), PDO::PARAM_STR);
			$ret = $stmt2->execute();
			if ($ret < 1) {
				$this->error = TRUE;
				$this->errmsg = 'Error SQLite: ' . $word . ' -- cannot update vectors.';
				$this->putAppLog($this->errmsg, 0, __LINE__, __FUNCTION__);
				return FALSE;
			}
		}
	}
	return TRUE;
}

/**
 * 登録されたツイートを学習する
 * @param	string $screen_name スクリーンネーム
 * @param	int $count 一度に学習する最大件数
 * @return	int 学習した件数
*/
function learnTweets($screen_name, $count) {
	$vectors = array();

	list($userID, $dt) = $this->getUserID($screen_name);
	if ($userID != FALSE) {
		list($text, $count) = $this->takeTweet($userID, $count);
		$this->text2vector($text, $vectors);
		if ($this->updateVectors($userID, $vectors) == TRUE) {
			$msg = 'Success SQLite: ' . $screen_name . ' update vectors.';
			$this->putAppLog($msg, 1, __LINE__, __FUNCTION__);
		}
	}
	return $count;
}

// [3] 判定（単純ベイズ分類機） ==============================================
/**
 * ユーザーの生起確率
 * @param	string $screen_name スクリーンネーム
 * @return	float 生起確率／FALSE
*/
function userProb($screen_name) {
	$sql_total = 'SELECT count(*) FROM ' . $this->table_tweets . ' WHERE 1';
	$sql_count = 'SELECT count(*) FROM ' . $this->table_tweets . ' WHERE user_id=:user_id';

	list($userID, $dt) = $this->getUserID($screen_name);
	$total = 0;
	$count = 0;
	if ($userID != FALSE) {
		//合計
		$stmt = $this->pdo->prepare($sql_total);
		$stmt->execute();
		$row = $stmt->fetch();
		$total = $row[0];

		//生起回数
		$stmt = $this->pdo->prepare($sql_count);
		$stmt->bindValue(':user_id', $userID, PDO::PARAM_STR);
		$stmt->execute();
		$row = $stmt->fetch();
		$count = $row[0];
	}

	return ($total == 0) ? FALSE : ($count / $total);
}

/**
 * あるユーザーにおける単語の出現頻度
 * @param	string $screen_name スクリーンネーム
 * @param	string $word        単語
 * @return	float 出現頻度／FALSE
*/
function wordProb($screen_name, $word) {
	$sql_total = 'SELECT * FROM ' . $this->table_tweets . ' WHERE user_id=:user_id AND flag=1';
	$sql_count  = 'SELECT count FROM ' . $this->table_vectors . ' WHERE user_id=:user_id AND word=:word';

	list($userID, $dt) = $this->getUserID($screen_name);
	$total = 0;
	$count = 0;
	if ($userID != FALSE) {
		//合計
		$stmt = $this->pdo->prepare($sql_total);
		$stmt->bindValue(':user_id', $userID, PDO::PARAM_STR);
		$stmt->execute();
		$res = $stmt->fetchAll();
		$total = 1;
		foreach ($res as $val)		$total++;

		//出現回数
		$stmt = $this->pdo->prepare($sql_count);
		$stmt->bindValue(':user_id', $userID, PDO::PARAM_STR);
		$stmt->bindValue(':word', $word, PDO::PARAM_STR);
		$stmt->execute();
		$res = $stmt->fetchAll();
		$count = 1;		//ラプラススムージング
		foreach ($res as $val)		$count += $val['count'];
	}

	return ($total == 0) ? NULL : ($count / $total);
}

/**
 * あるユーザーにおけるスコア計算
 * @param	string $screen_name スクリーンネーム
 * @param	string $text        テキスト
 * @return	float スコア／NULL
*/
function textProb($screen_name, $text) {
	$words = array();

	//ユーザーの生起スコア
	$score = $this->userProb($screen_name);
	if ($score == FALSE)	return NULL;
	$score = log($score);

	//単語の出現スコア
	$this->getWords($text, $words);
	foreach ($words as $word) {
		if (! $this->isword($word))	continue;
		$ret = $this->wordProb($screen_name, $word[0]);
		if ($ret == FALSE)		return NULL;
		$score += log($ret);
	}

	return $score;
}

/**
 * 単純ベイズ分類機
 * @param	string $text  判定したいテキスト
 * @param	array  $users ユーザー別判定結果を格納する配列
 * @return	なし
*/
function resultsClassified($text, &$users) {
	$sql_select  = 'SELECT * FROM ' . $this->table_users . ' WHERE 1';

	$stmt = $this->pdo->prepare($sql_select);
	$stmt->execute();
	$key = 0;
	while ($row = $stmt->fetch()) {
		$score = $this->textProb($row['screen_name'], $text);
		foreach ($users as $key=>$user) {
			if ($user['screen_name'] == $row['screen_name']) {
				$users[$key]['score'] = $score;
				break;
			}
		}
	}
}


// End of Class ==============================================================
}

// サブルーチン ==============================================================
/**
 * エラー処理ハンドラ
*/
function myErrorHandler($errno, $errmsg, $filename, $linenum, $vars) {
	global $plt;
	echo "Sory, system error occured !";
	$plt->putAppLog($errmsg, 0, $linenum, $filename);
	exit(1);
}
error_reporting(E_ALL);
if (FLAG_RELEASE)	$old_error_handler = set_error_handler('myErrorHandler');

/**
 * 指定したボタンが押されてきたかどうか
 * @param	string $btn  ボタン名
 * @return	bool TRUE＝押された／FALSE＝押されていない
*/
function isButton($btn) {
	global $argv;

	if (isset($_GET[$btn]))	return TRUE;
	if (isset($_POST[$btn]))	return TRUE;
	if (isset($argv)) {
		foreach ($argv as $str) {
			if ($str == $btn)	return TRUE;
		}
	}
	return FALSE;
}

/**
 * 指定したパラメータを取り出す
 * @param	string $key  パラメータ名（省略不可）
 * @param	bool   $auto TRUE＝自動コード変換あり／FALSE＝なし（省略時：TRUE）
 * @param	mixed  $def  初期値（省略時：空文字）
 * @return	string パラメータ／NULL＝パラメータ無し
*/
function getParam($key, $auto=TRUE, $def='') {
	global $argv;

	if (isset($_GET[$key])) {
		$param = $_GET[$key];
	} else if (isset($_POST[$key])) {
		$param = $_POST[$key];
	} else if (isset($argv)) {
		$param = $def;
		foreach ($argv as $str) {
			$arr = explode('=', $str);
			if ($arr[0] == $key) {
				$param = isset($arr[1]) ? $arr[1] : '';
			}
		}
	} else {
		$param = $def;
	}
	if ($auto)	$param = mb_convert_encoding($param, INTERNAL_ENCODING, 'auto');

	return $param;
}

/**
 * タイムスタンプを整形
 * @param	string $dt タイムスタンプ
 * @return	string 整形後テキスト
*/
function format_dt($dt) {
	$pat = '/([0-9\-]+)T([0-9\:]+)\+/i';
	if (preg_match($pat, $dt, $arr) == 0) {
		$ret = '';
	} else {
		$ret = $arr[1] . '<br />' . $arr[2];
	}
	return $ret;
}

/**
 * HTML BODYを作成する
 * @param	string $text        判定したいテキスト
 * @param	string $screen_name 選択中のスクリーンネーム
 * @param	string $users       ユーザー情報
 * @param	int    $sec         前回呼び出しからの経過秒数
 * @param	string $errmsg      エラーメッセージ
 * @return	string HTML BODY
*/
function makeCommonBody($text, $screen_name, $users, $sec, $errmsg) {
	$myself = MYSELF;
	$refere = REFERENCE;

	$title = TITLE;
	$version = '<span style="font-size:small;">' . date('Y/m/d版', filemtime(__FILE__)) . '</span>';

	$errmsg = ($errmsg != '') ? "<p style=\"color:red;\">{$errmsg}</p>" : '';
	$n = NUM_TWEETS;

	$body =<<< EOT
<body>
<h2>{$title} {$version}</h2>
{$errmsg}

<form name="myform" method="post" action="{$myself}" enctype="multipart/form-data">
テキスト<br />
<textarea id="text" name="text" rows="3" cols="65">{$text}</textarea>
<input type="submit" name="test"  id="test"  value="判定" />

<table>
<tr class="index">
<th>スクリーンネーム</th>
<th>最古</tf>
<th>最新</tf>
<th style="font-size:80%;">学習数／<br />登録数</tf>
<th>判定結果</tf>
</tr>
<tr>
<td>新規：<input type="text" id="new_name" name="new_name" size="15" /></td>
<td>&nbsp;</td>
<td>&nbsp;</td>
<td>&nbsp;</td>
<td>&nbsp;</td>
</tr>

EOT;
	foreach ($users as $user) {
		$checked = ($screen_name == $user['screen_name']) ? 'checked' : '';
		$dt_min = format_dt($user['dt_min']);
		$dt_max = format_dt($user['dt_max']);
		if ($user['score'] == NULL) {
			$score = '判定不能';
		} else {
			$score = 1 / (0 - $user['score']) * 100;
//			$score = $user['score'];
			$score = sprintf('%.1f％', $score);
		}
		$body .=<<< EOT
<tr>
<td><input type="radio" id="screen_name" name="screen_name" value="{$user['screen_name']}" {$checked} />{$user['screen_name']}</td>
<td style="text-align:center; font-size:80%;">{$dt_min}</td>
<td style="text-align:center; font-size:80%;">{$dt_max}</td>
<td style="text-align:center; font-size:80%;">{$user['count']}/{$user['total']}</td>
<td style="text-align:center;">{$score}</td>
</tr>

EOT;
	}
		$body .=<<< EOT
</table>

<input type="submit" name="store" id="store" value="登録" />　
<input type="submit" name="learn" id="learn" value="学習" />　
<input type="submit" name="reset" id="reset" value="リセット" />
<br />登録可能になるまで <span id="sec" name="sec">{$sec}</span> 秒
</form>

<div style="border-style:solid; border-width:1px; margin:20px 0px 0px 0px; padding:5px; width:550px; font-size:small;">
<h3>使い方</h3>
<ol>
<li>学習データ登録</li>
<ol type="A">
<li>［<span style="font-weight:bold;">スクリーンネーム</span>］のラジオボタンを選択し，［<span style="font-weight:bold;">登録</span>］ボタンを押してください．</li>
<li>そのユーザーのツイートを {$n}件まとめてデータベースに登録します．</li>
<li>新しいユーザーを追加したい場合，［<span style="font-weight:bold;">新規</span>］のテキストボックスにスクリーンネームを入力し，［<span style="font-weight:bold;">登録</span>］ボタンを押下して下さい．</li>
<li>TwitterAPIの制約上，一定時間を経過しないと登録はできません．</li>
</ol>
<li>学習</li>
<ol type="A">
<li>［<span style="font-weight:bold;">スクリーンネーム</span>］のラジオボタンを選択し，［<span style="font-weight:bold;">学習</span>］ボタンを押下すると，未学習のツイートを順次学習します．</li>
</ol>
<li>判定</li>
<ol type="A">
<li>［<span style="font-weight:bold;">テキスト</span>］を入力し，［<span style="font-weight:bold;">判定</span>］ボタンを押してください．</li>
<li>判定結果が一覧表示されます．</li>
</ol>
<li>［<span style="font-weight:bold;">リセット</span>］ボタンを押すと，入力エリアがクリアされます．</li>
</ol>
※参考サイト：<a href="{$refere}">{$refere}</a>
</div>
</body>

EOT;
	return $body;
}

// メイン・プログラム =======================================================
$plt = new pahooLearningTweets();		//pahooLearningTweetsクラス

$users = array();										//ユーザー情報格納用
$text        = getParam('text', TRUE, '');				//判定テキスト
$screen_name = getParam('screen_name', TRUE, '');		//スクリーンネーム
$new_name    = getParam('new_name', TRUE, '');
if ($new_name != '')	$screen_name = $new_name;
$auto  = isButton('auto')  ? TRUE : FALSE;
$store = isButton('store') ? TRUE : FALSE;
$learn = isButton('learn') ? TRUE : FALSE;
$test  = isButton('text')  ? TRUE : FALSE;

if ($screen_name == '')	$screen_name = $plt->getScreenNameOld();	//最新が最も古いもの
//if ($screen_name == '')	$screen_name = $plt->getScreenNameNew();	//最古が最も新しいもの
//if ($screen_name == '')	$screen_name = $plt->getScreenNameFew();	//登録数が少ないもの
if (isButton('reset'))		$screen_name = '';

//ユーザー情報取得
$plt->getUserInfo($users);

//[1] 学習データ登録
$errmsg = '';
if (($auto || $store) && ($screen_name != '')) {
	list($userID, $dt) = $plt->getUserID($screen_name);
	if ($userID == FALSE) {
		$errmsg = $plt->errmsg;
	} else {
		list($min_id, $max_id) = $plt->getMinMaxID($screen_name);
		//最新取得か過去取得にするかはランダムに決める
		$rd = mt_rand(1, 100);
		if (($min_id == FALSE) || ($max_id == FALSE)) {
			$count = $plt->getUserTweets($screen_name, NUM_TWEETS);
		} else if ($rd <= 50) {
			$count = $plt->getUserTweets($screen_name, NUM_TWEETS, '', $min_id);
		} else {
			$count = $plt->getUserTweets($screen_name, NUM_TWEETS, $max_id);
		}
		//ユーザー一覧の登録数を更新
		if ($count != FALSE) {
			foreach ($users as $key=>$user) {
			if ($user['screen_name'] == $screen_name) {
				$users[$key]['total'] += $count;
				break;
			}
		}
	}
		$errmsg = $plt->errmsg;
	}

//[2] 学習
} else if ($learn && ($screen_name != '')) {
	$count = $plt->learnTweets($screen_name, NUM_LEARN);
	//ユーザー一覧の学習数を更新
	foreach ($users as $key=>$user) {
		if ($user['screen_name'] == $screen_name) {
			$users[$key]['count'] += $count;
			break;
		}
	}

//[3] 判定
} else if ($test && ($text != '')) {
	$plt->resultsClassified($text, $users);
}

//オンライン処理
if (! $auto) {
	//前回APIコールからの経過時間チェック
	$sec = $plt->getElapsedTime();
	$sec = TIME_INTERVAL - $sec;
	if ($sec < 0)	$sec = 0;

	//表示内容作成
	$HtmlBody = makeCommonBody($text, $screen_name, $users, $sec, $errmsg);

	// 表示処理
	echo $HtmlHeader;
	echo $HtmlBody;
	echo $HtmlFooter;
}

$plt = NULL;

/*
** バージョンアップ履歴 ===================================================
 *
 * @version  1.0  2017/04/30
*/
?>
