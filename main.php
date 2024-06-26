<?php
use Swoole\Coroutine;
use function Swoole\Coroutine\run;
use function Swoole\Timer;
date_default_timezone_set('Asia/Shanghai');

require './config.php';
//加载inc
//没有找到更好的autoload能在源码和phar中一起运行的方式，如果有欢迎pr!
require(__DIR__ . '/inc/api.php');
require(__DIR__ . '/inc/cluster.php');
require(__DIR__ . '/inc/database.php');
require(__DIR__ . '/inc/mlog.class.php');
require(__DIR__ . '/inc/PluginInfoInterface.php');
require(__DIR__ . '/inc/pluginsmanager.php');
require(__DIR__ . '/inc/server.php');
require(__DIR__ . '/inc/socketio.php');
require(__DIR__ . '/inc/token.php');
require(__DIR__ . '/inc/webapi.php');

api::getconfig($config);
const PHPOBAVERSION = '1.6.0';
const VERSION = '1.10.9';
$download_dir = api::getconfig()['file']['cache_dir'];
const USERAGENT = 'openbmclapi-cluster/' . VERSION . '  ' . 'php-openbmclapi/'.PHPOBAVERSION;
const OPENBMCLAPIURL = 'openbmclapi.staging.bangbang93.com';
mlog("OpenBmclApi on PHP v". PHPOBAVERSION . "-" . VERSION,0,true);
run(function(){
    $config = api::getconfig();
    //注册信号处理器、
    function exits() {
        global $shouldExit;
        $shouldExit = true; // 设置退出标志
        Swoole\Timer::clearAll();
        global $socketio;
        if (is_object($socketio)) {
            $socketio->disable();
        }
        global $httpserver;
        if (is_object($httpserver)) {
            $httpserver->stopserver();
        }
        echo PHP_EOL;
        mlog("正在退出...");
    }
    function registerSigintHandler() {
        global $shouldExit;
        $shouldExit = false; // 初始化为false
        Swoole\Process::signal(SIGINT, function ($signo){
            exits();
        });
    }

    //创建数据库
    $database = new database();
    $database->initializedatabase();

    //获取初次Token
    $token = new token($config['cluster']['CLUSTER_ID'],$config['cluster']['CLUSTER_SECRET'],VERSION);
    $tokendata = $token->gettoken();
    mlog("GetToken:".$tokendata['token'],1);
    mlog("TokenTTL:".$tokendata['upttl'],1);
    //启动更新TokenTimer
    global $tokentimerid;
    $tokentimerid = Swoole\Timer::tick($tokendata['upttl'], function () use ($token) {
        $tokendata = $token->gettoken();
        mlog("GetNewToken:".$tokendata['token'],1);
    });
    registerSigintHandler();
    mlog("Timer start on ID{$tokentimerid}",1);

    //建立socketio连接主控
    global $socketio;
    $socketio = new socketio(OPENBMCLAPIURL,$tokendata['token'],$config['advanced']['keepalive']);
    mlog("正在连接主控");
    Coroutine::create(function () use (&$socketio){
        $socketio->connect();
    });
    Coroutine::sleep(1);
    //获取证书
    $socketio->ack("request-cert");
    Coroutine::sleep(1);
    $allcert = $socketio->Getcert();
    //写入证书并且是否损坏
    if (!file_exists('./cert/'.$config['cluster']['CLUSTER_ID'].'.crt') && !file_exists('./cert/'.$config['cluster']['CLUSTER_ID'].'.key')) {
        mlog("正在获取证书");
        if (!file_exists("./cert")) {
            mkdir("./cert",0777,true);
        }
        mlog("已获取证书,到期时间{$allcert['0']['1']['expires']}");
        $cert = fopen('./cert/'.$config['cluster']['CLUSTER_ID'].'.crt', 'w');
        $Writtencert = fwrite($cert, $allcert['0']['1']['cert']);
        fclose($cert);
        $cert = fopen('./cert/'.$config['cluster']['CLUSTER_ID'].'.key', 'w');
        $Writtencert = fwrite($cert, $allcert['0']['1']['key']);
        fclose($cert);
    }
    $crt = file_get_contents('./cert/'.$config['cluster']['CLUSTER_ID'].'.crt');
    if ($crt!== $allcert['0']['1']['cert']) {
        mlog("证书损坏/过期");
        mlog("已获取新的证书,到期时间{$allcert['0']['1']['expires']}");
        $cert = fopen('./cert/'.$config['cluster']['CLUSTER_ID'].'.crt', 'w');
        $Writtencert = fwrite($cert, $allcert['0']['1']['cert']);
        fclose($cert);
        $cert = fopen('./cert/'.$config['cluster']['CLUSTER_ID'].'.key', 'w');
        $Writtencert = fwrite($cert, $allcert['0']['1']['key']);
        fclose($cert);
    }
    //设置http服务器
    global $httpserver;
    $httpserver = new fileserver($config['cluster']['host'],$config['cluster']['port'],$config['cluster']['CLUSTER_ID'].'.crt',$config['cluster']['CLUSTER_ID'].'.key',$config['cluster']['CLUSTER_SECRET']);
    $server = $httpserver->setupserver();

    //开始加载插件
    $pluginsManager = new PluginsManager();
    $pluginsManager->loadPlugins($server);

    //启动http服务器
    Coroutine::create(function () use ($config,$httpserver){
        $httpserver->startserver();
    });
    $uptime = api::getinfo();
    $uptime['uptime'] = time();
    api::getinfo($uptime);

    //下载文件列表
    $cluster = new cluster($tokendata['token'],VERSION);
    $files = $cluster->getFileList();
    $FilesCheck = new FilesCheck($files);
    if ($config['file']['check'] == "hash"){
        $Missfile = $FilesCheck->FilesCheckerhash();
    }
    elseif($config['file']['check'] == "size"){
        $Missfile = $FilesCheck->FilesCheckersize();
    }
    elseif($config['file']['check'] == "exists"){
        $Missfile = $FilesCheck->FilesCheckerexists();
    }
    $isSynchronized = api::getinfo();
    $isSynchronized['isSynchronized'] = true;
    api::getinfo($isSynchronized);
    //循环到没有Missfile这个变量
    if (is_array($Missfile)){
        mlog("缺失/损坏".count($Missfile)."个文件");
        while(is_array($Missfile)){
            $download = new download($Missfile,$config['advanced']['MaxConcurrent']);
            $download->downloadFiles();
            $FilesCheck = new FilesCheck($Missfile);
            if ($config['file']['check'] == "hash"){
                $Missfile = $FilesCheck->FilesCheckerhash();
            }
            elseif($config['file']['check'] == "size"){
                $Missfile = $FilesCheck->FilesCheckersize();
            }
            elseif($config['file']['check'] == "exists"){
                $Missfile = $FilesCheck->FilesCheckerexists();
            }
            if (is_array($Missfile)){
                //mlog("缺失/损坏".count($Missfile)."个文件");
            }
            else{
                $isSynchronized = api::getinfo();
                $isSynchronized['isSynchronized'] = false;
                api::getinfo($isSynchronized);
                mlog("检查文件完毕,没有缺失/损坏");
            }
        }
    }
    else{
        global $shouldExit;
        if (!$shouldExit){
            $isSynchronized = api::getinfo();
            $isSynchronized['isSynchronized'] = false;
            api::getinfo($isSynchronized);
            mlog("检查文件完毕,没有缺失/损坏");
        }
    }
    global $shouldExit;
    if (!is_array($Missfile) && !$shouldExit){//判断Missfile是否为空和是否是主动退出
        //enable节点
        $socketio->enable($config['cluster']['public_host'],$config['cluster']['public_port'],$config['cluster']['byoc']);
    }
});