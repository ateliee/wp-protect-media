<?php
require_once( __DIR__ . '/../../../../wp-load.php' );
require_once( __DIR__ . '/include.php' );
if(!defined('ABSPATH')){
    http_response_code(500);
    echo "Invalid Server Error";
    exit;
}
// ブロック中の場合
if(ProtectMedia::is_block()){
    http_response_code(500);
    echo "Access Blocked";
    exit;
}
// ログインしていない場合はアクセス拒否
if(!is_user_logged_in()){
    http_response_code(401);
    echo "Invalid Auth";
    exit;
}
// 許可するディレクトリ
$protect_dir = ProtectMedia::get_protect_dir();
if($protect_dir === false){
    http_response_code(500);
    echo "Invalid Setting Server Error";
    exit;
}

$file = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$path = realpath(ABSPATH.$file);
$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if(!$extension || !$path){
    http_response_code(500);
    echo "Invalid Server Error";
    exit;
}
if(strpos($path, $protect_dir) !== 0){
    http_response_code(404);
    echo "Invalid Server Error";
    exit;
}
if(!file_exists($path)){
    http_response_code(404);
    echo "File Not Found";
    exit;
}

if($extension === 'jpg' || $extension === 'jpeg') {
    header('Content-Type: image/jpeg');
}else if($extension === 'gif'){
    header('Content-Type: image/gif');
}else if($extension === 'png'){
    header('Content-Type: image/png');
}else{
    http_response_code(404);
    echo "Un Support File Type";
    exit;
}
header("X-Robots-Tag: noindex, nofollow");
readfile($path);
exit;