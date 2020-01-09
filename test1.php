<?php
date_default_timezone_set('PRC');
require_once __DIR__ . '/../src/RedLock.php';

$servers = [
    ['10.8.8.96', 6379, 0.01],
    ['10.8.8.96', 6380, 0.01],
    ['10.8.8.58', 6379, 0.01],
];
//exit;
$redLock = new RedLock($servers);
//$red_lock = $redLock -> renewal('testtesta','5dca198ff1800',10000);
//print_r($red_lock);
//exit;
//
//print_r($redlock);
//exit;

$lock = RedLock::watchDogToLock($redLock,'testtest',10000);//返回0 为成功,非0为进程中断信息
//$redLock -> renewal($key,$lock['token'],$ttl);
if(0 === $lock){
    echo '成功释放锁';
    exit;
}elseif(false === $lock){
    echo 'false';
    print_r($lock);
}


//exit;
//while (true) {
//    $lock = $redLock->lock('test11', 10000000000);
//
//    if ($lock) {
//        print_r($lock);
//    } else {
//        print "Lock not acquired\n";
//    }
//}
