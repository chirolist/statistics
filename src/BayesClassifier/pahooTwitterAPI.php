<?php
/** pahooTwitter.php
 * Twitter APIに関わるクラス（PHP5専用）
 *
 * @copyright	(c)studio pahoo
 * @author		パパぱふぅ
 * @参考URL		http://www.pahoo.org/e-soul/webtech/php06/php06-24-01.shtm
 *
 * [利用するWebAPI]
 *  Twitter API
*/

// Twitter API クラス =======================================================
class pahooTwitterAPI {
	var $webapi;		//直前に呼び出したWebAPI URL
	var $error;		//エラーフラグ
	var $errmsg;		//エラーメッセージ
	var $errcode;		//エラーコード
	var $responses;	//直前の結果（配列）

	//OAuth用パラメータ
	const TWTR_CONSUMER_KEY    = '***************';
	const TWTR_CONSUMER_SECRET = '***************';
	const TWTR_ACCESS_KEY      = '***************';
	const TWTR_ACCESS_SECRET   = '***************';

/**
 * コンストラクタ
 * @param	なし
 * @return	なし
*/
function __construct() {
	$this->webapi = '';
	$this->error  = FALSE;
	$this->errmsg  = '';
	$this->errcode = 0;
	$this->responses = array();
}

/**
 * デストラクタ
 * @return	なし
*/
function __destruct() {
	unset($this->responses);
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
 * PHP5以上かどうか検査する
 * @return	bool TRUE：PHP5以上／FALSE:PHP5未満
*/
function isphp5over() {
	$version = explode('.', phpversion());

	return $version[0] >= 5 ? TRUE : FALSE;
}

// Twitter API ==============================================================
/**
 * Twitter用文字列長
 * @param	string $str テキスト
 * @return	int 長さ
*/
function twitter_strlen($str) {
	$pat = '/https?\:\/\/[\-_\.\!\~\*\'\(\)a-zA-Z0-9\;\/\?\:\@\&\=\+\$\,\%\#]+/i';
	$str = preg_replace($pat, '12345678901234567890123', $str);
//	$str = preg_replace($pat, '', $str);		//URLをカウントしない

	return mb_strlen($str);
}

/**
 * Twitter用文字列カット
 * @param	string $str テキスト
 * @param	int    $len カットする長さ
 * @return	int 長さ
*/
function twitter_strcut($str, $len) {
	if (mb_strlen($str) <= $len)	return $str;

	$str = mb_substr($str, 0, $len - 3) . '...';

	return $str;
}

/**
 * URLエンコード RFC3986版
 * @param	string $str エンコードするテキスト
 * @return	string エンコード結果
*/
function _rawurlencode($str) {
	$str = str_replace(array('+', '%7E'), array('%20', '~'), $str) ;
	$str = rawurlencode($str);

	return $str;
}

/**
 * Twitter API：アクセストークンを用いたリクエスト（ユーザー認証あり）
 * @param	string $url    TwitterAPIのリクエストURL
 * @param	string $method GET|POST
 * @param	array  $option オプションパラメータ配列
 * @return	bool TRUE：リクエスト成功／FALSE：失敗
*/
function request_user($url, $method, $option) {
	//キー生成
	$signature_key = $this->_rawurlencode(self::TWTR_CONSUMER_SECRET) . '&' . $this->_rawurlencode(self::TWTR_ACCESS_SECRET);

	//パラメータ
	$params = array(
		'oauth_token'				=> self::TWTR_ACCESS_KEY,
		'oauth_consumer_key'		=> self::TWTR_CONSUMER_KEY,
		'oauth_signature_method'	=> 'HMAC-SHA1',
		'oauth_timestamp'			=> time(),
		'oauth_nonce'				=> microtime(),
		'oauth_version'			=> '1.0'
	);
	$params = array_merge($option, $params);
	ksort($params) ;

	//リクエスト文字列生成
	$request_params = http_build_query($params, '', '&');
	$request_params = $this->_rawurlencode($request_params);
	$encoded_request_method = $this->_rawurlencode($method);
	$encoded_request_url = $this->_rawurlencode($url);

	//シグネチャ生成
	$signature_data = $encoded_request_method . '&' . $encoded_request_url . '&' . $request_params;
	$hash = hash_hmac('sha1', $signature_data, $signature_key, TRUE);
	$signature = base64_encode($hash);
	$params['oauth_signature'] = $signature;

	//ヘッダ文字列生成
	$header_params = http_build_query($params, '', ',');

	//リクエスト用コンテキスト
	$context = array(
		'http' => array(
			'method' => $method,			//リクエストメソッド
			'header' => array(				//ヘッダー
				'Authorization: OAuth ' . $header_params
			),
		),
	);

	//オプション処理
	if (count($option) > 0) {
		if ($method == 'GET') {
			$url .= '?' . http_build_query($option);
		} else {
			$context['http']['content'] = http_build_query($option) ;
		}
	}

	// cURLを使ってリクエスト
	$curl = curl_init() ;
	curl_setopt($curl, CURLOPT_URL , $url);
	curl_setopt($curl, CURLOPT_HEADER, 1) ; 
	curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $context['http']['method']);
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER , FALSE);	//証明書は無視
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);		//結果を文字列で
	curl_setopt($curl, CURLOPT_HTTPHEADER, $context['http']['header']);
	if (isset($context['http']['content']) && !empty($context['http']['content'])) {
		curl_setopt($curl, CURLOPT_POSTFIELDS, $context['http']['content']);			//リクエストボディ
	}
	curl_setopt($curl, CURLOPT_TIMEOUT, 5);
	$res1 = curl_exec($curl);
	$res2 = curl_getinfo($curl);
	curl_close($curl);

	//結果処理
	$this->webapi = $url;
	$json = substr($res1, $res2['header_size']);
	$this->responses = json_decode($json);
	if (isset($this->responses->errors)) {
		$this->error   = TRUE;
		$this->errmsg  = $this->responses->errors[0]->message;
		$this->errcode = $this->responses->errors[0]->code;
	}

	return (! $this->error);
}

/**
 * Twitter API：メディアアップロード
 * @param	string $url    TwitterAPIのリクエストURL
 * @param	string $method GET|POST
 * @param	string $type   メディアタイプ（image|banner|media_data）
 * @param	string $data   メディアデータ（バイナリ）
 * @return	string メディアID／NULL：失敗
*/
function upload($url, $method, $type, $data) {
	//キー生成
	$signature_key = $this->_rawurlencode(self::TWTR_CONSUMER_SECRET) . '&' . $this->_rawurlencode(self::TWTR_ACCESS_SECRET);

	//メディアデータ
	$media_data = ($type == 'media_data') ? base64_encode($data) : $data;
	$option = array($type => $media_data);

	//パラメータ
	$params = array(
		'oauth_token'				=> self::TWTR_ACCESS_KEY,
		'oauth_consumer_key'		=> self::TWTR_CONSUMER_KEY,
		'oauth_signature_method'	=> 'HMAC-SHA1',
		'oauth_timestamp'			=> time(),
		'oauth_nonce'				=> microtime(),
		'oauth_version'			=> '1.0'
	);
	ksort($params) ;

	//バウンダリー生成
	$boundary = 'p-a-h-o-o---------------' . md5(mt_rand());

	//POSTフィールド生成
	$request_body  = '';
	$request_body .= '--' . $boundary . "\r\n";
	$request_body .= 'Content-Disposition: form-data; name="' . $type . '"; ';
	$request_body .= "\r\n";
	$request_body .= "\r\n" . $media_data . "\r\n";
	$request_body .= '--' . $boundary . '--' . "\r\n\r\n";

	//リクエストヘッダー生成
	$request_header = "Content-Type: multipart/form-data; boundary=" . $boundary ;

	//リクエスト文字列生成
	$request_params = http_build_query($params, '', '&');
	$request_params = $this->_rawurlencode($request_params);
	$encoded_request_method = $this->_rawurlencode($method);
	$encoded_request_url = $this->_rawurlencode($url);

	//シグネチャ生成
	$signature_data = $encoded_request_method . '&' . $encoded_request_url . '&' . $request_params;
	$hash = hash_hmac('sha1', $signature_data, $signature_key, TRUE);
	$signature = base64_encode($hash);
	$params['oauth_signature'] = $signature;

	//ヘッダ文字列生成
	$header_params = http_build_query($params, '', ',');

	//リクエスト用コンテキスト
	$context = array(
		'http' => array(
			'method' => $method, 		// リクエストメソッド
			'header' => array(			// ヘッダー
				'Authorization: OAuth ' . $header_params,
				'Content-Type: multipart/form-data; boundary= ' . $boundary,
			),
			'content' => $request_body,
		),
	);

	// cURLを使ってリクエスト
	$curl = curl_init() ;
	curl_setopt($curl, CURLOPT_URL, $url);
	curl_setopt($curl, CURLOPT_HEADER, 1); 
	curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $context['http']['method']);
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);	//証明書は無視
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE) ;	//結果を文字列で
	curl_setopt($curl, CURLOPT_HTTPHEADER, $context['http']['header']);
	curl_setopt($curl, CURLOPT_POSTFIELDS, $context['http']['content']);
	curl_setopt($curl, CURLOPT_TIMEOUT, 5 );
	$res1 = curl_exec($curl);
	$res2 = curl_getinfo($curl);
	curl_close($curl);

	//結果処理
	$this->webapi = $url;
	$json = substr($res1, $res2['header_size']);
	$this->responses = json_decode($json);

	//メディアID
	if (isset($this->responses->media_id_string)) {
		$res = (string)$this->responses->media_id_string;
	} else if (isset($this->responses->errors)) {
		$this->error   = TRUE;
		$this->errmsg  = $this->responses->errors[0]->message;
		$this->errcode = $this->responses->errors[0]->code;
		$res = NULL;
	} else if (isset($this->responses->id_str)) {
		$res = $this->responses->id_str;
	} else {
		$res = NULL;
	}

	return $res;
}

/**
 * 直前のツイートURLを取得
 * @param	なし
 * @return	string メッセージURL／FALSE：失敗
*/
function getLastTweet() {
	if (! isset($this->responses->id_str))		FALSE;

	$id_str = (string)$this->responses->id_str;
	$url = 'https://twitter.com/' . (string)$this->responses->user->screen_name . '/status/' . $id_str;

	return $url;
}

/**
 * 投稿する
 * @param	string $message 投稿メッセージ（UTF-8限定）
 * @return	bool TRUE：リクエスト成功／FALSE：失敗
*/
function tweet($message) {
	$url    = 'https://api.twitter.com/1.1/statuses/update.json';
	$method = 'POST' ;
	$option = array('status' => $message);

	return $this->request_user($url, $method, $option);
}

/**
 * メディア付き投稿
 * @param	string $message 投稿メッセージ（UTF-8限定）
 * @param	array  $fnames  メディアデータのファイル名（配列）
 * @return	bool TRUE：リクエスト成功／FALSE：失敗
*/
function tweet_media($message, $fnames) {
	static $url_upload    = 'https://upload.twitter.com/1.1/media/upload.json';
	static $url_tweet     = 'https://api.twitter.com/1.1/statuses/update.json';
	static $method        = 'POST' ;

	//メディアのアップロード
	$media_ids = '';
	$cnt = 0;
	foreach ($fnames as $fname) {
		$data = @file_get_contents($fname);
		$media_id = $this->upload($url_upload, 'POST', 'media_data', $data);
		if ($media_id == NULL)		break;
		if ($cnt > 0)	$media_ids .= ',';
		$media_ids .= $media_id;
		$cnt++;
		if ($cnt > 3)	break;		//最大4つまで
	}

	//ツイート
	if (! $this->error) {
		$option = array('status' => $message, 'media_ids' => $media_ids);
		$res = $this->request_user($url_tweet, $method, $option);
	} else {
		$res = FALSE;
	}

	return $res;
}

/**
 * 指定の緯度・経度から最も近いトレンド地域を取得
 * @param	double $latitude  緯度（世界測地系）
 * @param	double $longitude 経度（世界測地系）
 * @return	array WOEID（WOEID,地域名）／FALSE：失敗
*/
function getWOEID($latitude, $longitude) {
	static $url_closest = 'https://api.twitter.com/1.1/trends/closest.json';
	static $method     = 'GET';
	$option['lat']     = $latitude;
	$option['long']    = $longitude;

	$res = $this->request_user($url_closest, $method, $option);

	if ($res == FALSE)	return FALSE;
	$woeid = isset($this->responses[0]->woeid) ? $this->responses[0]->woeid : FALSE;
	$place = isset($this->responses[0]->name)  ? $this->responses[0]->name  : FALSE;

	return array($woeid, $place);
}

/**
 * 指定の地域におけるトレンドを取得
 * @param	string $woid WOEID
 * @return	array トレンド配列
*/
function getTrends($woeid) {
	static $url_trends = 'https://api.twitter.com/1.1/trends/place.json';
	static $method     = 'GET';
	$option['id']      = $woeid;

	$res = $this->request_user($url_trends, $method, $option);

	if ($res == FALSE)	return FALSE;
	$results = array();
	foreach ($this->responses[0]->trends as $key=>$item) {
		$results[$key + 1]['title'] = $item->name;
		$results[$key + 1]['url']   = $item->url;
	}

	return $results;
}

/**
 * ユーザーのツイートを取得
 * @param	string $screen_name スクリーンネーム
 * @param	int    $count       取得件数（200以下；省略時200）
 * @return	array ツイート配列
*/
function user_timeline($screen_name, $count=200) {
	static $url_user_timeline = 'https://api.twitter.com/1.1/statuses/user_timeline.json';
	static $method         = 'GET';
	$option['screen_name'] = $screen_name;
	$option['count']       = $count;

	$res = $this->request_user($url_user_timeline, $method, $option);

	if ($res == FALSE)	return FALSE;
	$results = array();
	foreach ($this->responses as $key=>$item) {
		$results[$key + 1]['text'] = $item->text;
		$results[$key + 1]['url']  = 'https://twitter.com/' . $screen_name . '/status/' . $item->id_str;
	}

	return $results;
}

/**
 * ツイートの埋め込み用HTMLを取得
 * @param	string $url    ツイートURL
 * @param	int    $width  フレームの最大横幅（ピクセル）
 * @param	bool   $script JavaScriptを追加するか否か
 * @return	string HTML
*/
function oembed($url, $width, $script) {
	static $url_oembed = 'https://publish.twitter.com/oembed';
	static $method         = 'GET';
	$option['url'] = $url;
	$option['omit_script'] = $script ? 'true' : 'false';
	$option['maxwidth'] = $width;

	$res = $this->request_user($url_oembed, $method, $option);

	if ($res == FALSE)	return FALSE;

	$results = isset($this->responses->html) ? $this->responses->html : FALSE;

	return $results;
}

// End of Class ===========================================================
}

/*
** バージョンアップ履歴 ===================================================
 *
 * @version  4.3  2017/04/12  oembed() 追加
 * @version  4.22 2016/09/24  twitter_strlen 仕様変更
 * @version  4.21 2016/02/14  update_profile_image.json 対応
 * @version  4.2  2016/02/13  getWOEID(), getTrends() 追加
 * @version  4.1  2016/02/12  getLastTweet() 追加
 * @version  4.0  2016/02/11  pahooTwitterAPIクラスを分離
 * @version  3.1  2014/01/19  https対応
 * @version  3.0  2013/07/21  API 1.1対応
 * @version  2.1  2012/01/13  bug-fix
 * @version  2.0  2010/09/10  OAuth認証に対応
 * @version  1.0  2009/07/09
*/
